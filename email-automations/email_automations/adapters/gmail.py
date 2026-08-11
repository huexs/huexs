"""Adaptadors `Mailbox` i `Mailer` sobre l'API de Gmail.

Els objectes `service` s'injecten, de manera que els tests de contracte poden
substituir-los sense tocar la xarxa. Cap error remot es converteix en un èxit
silenciós: les excepcions es propaguen al processador, que deixa l'element com
a reintentable.
"""

from __future__ import annotations

import base64
import binascii
import logging
import re
from datetime import datetime, timezone
from email.message import EmailMessage
from typing import Any, Iterator, Sequence

from ..config import GoogleConfig
from ..models import Attachment, MailMessage, OutgoingEmail
from .google_auth import GMAIL_READONLY, GMAIL_SEND, build_service

logger = logging.getLogger(__name__)

DEFAULT_MAX_ATTACHMENT_BYTES = 20 * 1024 * 1024
_PAGE_SIZE = 100
_TAG_RE = re.compile(r"<[^>]+>")


class GmailError(RuntimeError):
    """Resposta de Gmail que no es pot normalitzar."""


class GmailMailbox:
    def __init__(
        self,
        service: Any,
        *,
        user_id: str = "me",
        max_attachment_bytes: int = DEFAULT_MAX_ATTACHMENT_BYTES,
    ) -> None:
        self.service = service
        self.user_id = user_id
        self.max_attachment_bytes = max_attachment_bytes
        self._label_ids: dict[str, str] = {}

    def list_messages(self, *, label: str, limit: int) -> list[MailMessage]:
        if limit <= 0:
            return []
        label_id = self._resolve_label(label)
        messages: list[MailMessage] = []
        for reference in self._iter_references(label_id, limit):
            payload = (
                self.service.users()
                .messages()
                .get(userId=self.user_id, id=reference["id"], format="full")
                .execute()
            )
            messages.append(self._normalize(payload))
        logger.info(
            "gmail: %d missatges recuperats de l'etiqueta %s", len(messages), label
        )
        return messages

    def _iter_references(self, label_id: str, limit: int) -> Iterator[dict[str, Any]]:
        page_token: str | None = None
        seen = 0
        while seen < limit:
            response = (
                self.service.users()
                .messages()
                .list(
                    userId=self.user_id,
                    labelIds=[label_id],
                    maxResults=min(_PAGE_SIZE, limit - seen),
                    pageToken=page_token,
                )
                .execute()
            )
            for reference in response.get("messages") or ():
                yield reference
                seen += 1
                if seen >= limit:
                    return
            page_token = response.get("nextPageToken")
            if not page_token:
                return

    def _resolve_label(self, label: str) -> str:
        if label in self._label_ids:
            return self._label_ids[label]
        response = self.service.users().labels().list(userId=self.user_id).execute()
        self._label_ids = {
            item["name"]: item["id"] for item in response.get("labels") or ()
        }
        if label not in self._label_ids:
            raise GmailError(f"L'etiqueta '{label}' no existeix al compte")
        return self._label_ids[label]

    def _normalize(self, payload: dict[str, Any]) -> MailMessage:
        message_id = payload.get("id")
        thread_id = payload.get("threadId")
        if not message_id or not thread_id:
            raise GmailError("Resposta de Gmail sense id o threadId")
        body = payload.get("payload") or {}
        headers = _headers(body)
        return MailMessage(
            id=message_id,
            thread_id=thread_id,
            sender=headers.get("from", ""),
            subject=headers.get("subject", ""),
            body_text=_body_text(body),
            received_at=_received_at(payload),
            attachments=tuple(self._attachments(message_id, body)),
        )

    def _attachments(
        self, message_id: str, body: dict[str, Any]
    ) -> Iterator[Attachment]:
        for part in _walk(body):
            filename = part.get("filename") or ""
            part_body = part.get("body") or {}
            attachment_id = part_body.get("attachmentId")
            if not filename or not attachment_id:
                continue
            size = int(part_body.get("size") or 0)
            if size > self.max_attachment_bytes:
                logger.warning(
                    "gmail: adjunt omès per mida (%d bytes) al missatge %s",
                    size,
                    message_id,
                )
                continue
            fetched = (
                self.service.users()
                .messages()
                .attachments()
                .get(userId=self.user_id, messageId=message_id, id=attachment_id)
                .execute()
            )
            yield Attachment(
                filename=filename,
                content_type=part.get("mimeType") or "application/octet-stream",
                data=_decode(fetched.get("data")),
            )


class GmailMailer:
    def __init__(self, service: Any, *, sender: str = "me") -> None:
        self.service = service
        self.sender = sender

    def send(self, email: OutgoingEmail) -> str:
        message = EmailMessage()
        message["To"] = email.recipient
        message["Subject"] = email.subject
        if self.sender and self.sender != "me":
            message["From"] = self.sender
        message.set_content(email.text_body)
        raw = base64.urlsafe_b64encode(message.as_bytes()).decode("ascii")
        response = (
            self.service.users()
            .messages()
            .send(userId="me", body={"raw": raw})
            .execute()
        )
        sent_id = (response or {}).get("id")
        if not sent_id:
            raise GmailError("Gmail no ha retornat cap identificador de correu")
        logger.info("gmail: correu %s enviat", sent_id)
        return sent_id


def build_mailbox_service(config: GoogleConfig) -> Any:
    return build_service("gmail", "v1", config, [GMAIL_READONLY])


def build_mailer_service(config: GoogleConfig) -> Any:
    return build_service("gmail", "v1", config, [GMAIL_SEND])


def _headers(body: dict[str, Any]) -> dict[str, str]:
    return {
        str(header.get("name", "")).lower(): str(header.get("value", ""))
        for header in body.get("headers") or ()
    }


def _walk(part: dict[str, Any]) -> Iterator[dict[str, Any]]:
    yield part
    for child in part.get("parts") or ():
        yield from _walk(child)


def _body_text(body: dict[str, Any]) -> str:
    html_fallback = ""
    for part in _walk(body):
        mime = (part.get("mimeType") or "").lower()
        data = (part.get("body") or {}).get("data")
        if not data:
            continue
        if mime == "text/plain":
            return _decode(data).decode("utf-8", errors="replace").strip()
        if mime == "text/html" and not html_fallback:
            html_fallback = _decode(data).decode("utf-8", errors="replace")
    if html_fallback:
        return _TAG_RE.sub(" ", html_fallback).strip()
    return ""


def _received_at(payload: dict[str, Any]) -> datetime:
    internal = payload.get("internalDate")
    if internal is None:
        raise GmailError("Resposta de Gmail sense internalDate")
    return datetime.fromtimestamp(int(internal) / 1000, tz=timezone.utc)


def _decode(data: str | None) -> bytes:
    if not data:
        return b""
    padded = data + "=" * (-len(data) % 4)
    try:
        return base64.urlsafe_b64decode(padded)
    except (binascii.Error, ValueError) as exc:
        raise GmailError("Contingut de Gmail amb base64 invàlid") from exc


def normalized_scopes() -> Sequence[str]:
    return (GMAIL_READONLY, GMAIL_SEND)
