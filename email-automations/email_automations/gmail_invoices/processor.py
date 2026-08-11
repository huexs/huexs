from __future__ import annotations

import re
from dataclasses import dataclass
from pathlib import PurePath
from typing import Sequence

from ..models import Attachment, MailMessage, OutgoingEmail
from ..ports import FileStore, Mailbox, Mailer
from ..results import RunResult
from ..state import StateStore

AUTOMATION = "gmail_invoices"


@dataclass(frozen=True)
class ProviderRule:
    slug: str
    sender_contains: tuple[str, ...] = ()
    subject_contains: tuple[str, ...] = ()

    def matches(self, message: MailMessage) -> bool:
        sender = message.sender.lower()
        subject = message.subject.lower()
        sender_ok = not self.sender_contains or any(
            value.lower() in sender for value in self.sender_contains
        )
        subject_ok = not self.subject_contains or any(
            value.lower() in subject for value in self.subject_contains
        )
        return sender_ok and subject_ok


class GmailInvoicesProcessor:
    def __init__(
        self,
        *,
        mailbox: Mailbox,
        mailer: Mailer,
        file_store: FileStore,
        state: StateStore,
        label: str,
        review_recipient: str,
        provider_rules: Sequence[ProviderRule],
    ) -> None:
        self.mailbox = mailbox
        self.mailer = mailer
        self.file_store = file_store
        self.state = state
        self.label = label
        self.review_recipient = review_recipient
        self.provider_rules = tuple(provider_rules)

    def run(self, *, limit: int = 100, dry_run: bool = False) -> RunResult:
        messages = self.mailbox.list_messages(label=self.label, limit=limit)
        result = RunResult(discovered=len(messages))
        for message in messages:
            if dry_run:
                result.skipped += 1
                continue
            if not self.state.claim(
                AUTOMATION,
                message.id,
                metadata={"thread_id": message.thread_id},
            ):
                result.skipped += 1
                continue
            try:
                rule = next(
                    (rule for rule in self.provider_rules if rule.matches(message)),
                    None,
                )
                pdfs = tuple(
                    attachment
                    for attachment in message.attachments
                    if _is_pdf(attachment)
                )
                if rule is None or not pdfs:
                    reason = (
                        "proveïdor desconegut"
                        if rule is None
                        else "cap adjunt PDF"
                    )
                    mail_id = self.mailer.send(
                        OutgoingEmail(
                            recipient=self.review_recipient,
                            subject=f"[gmail_invoices] Revisió: {message.subject}",
                            text_body=(
                                f"Cal revisar el missatge {message.id}: {reason}. "
                                "No s'ha desat cap fitxer."
                            ),
                        )
                    )
                    self.state.complete(
                        AUTOMATION,
                        message.id,
                        metadata={"outcome": "review", "review_mail_id": mail_id},
                    )
                    result.review += 1
                    continue

                locators = []
                for index, attachment in enumerate(pdfs, start=1):
                    filename = _invoice_filename(
                        provider_slug=rule.slug,
                        message=message,
                        attachment=attachment,
                        index=index,
                        total=len(pdfs),
                    )
                    locators.append(
                        self.file_store.save(
                            filename=filename,
                            data=attachment.data,
                            content_type="application/pdf",
                        )
                    )
                self.state.complete(
                    AUTOMATION,
                    message.id,
                    metadata={"outcome": "saved", "files": locators},
                )
                result.completed += 1
            except Exception as exc:
                self.state.fail(AUTOMATION, message.id, exc)
                result.failed += 1
        return result


def _is_pdf(attachment: Attachment) -> bool:
    return (
        attachment.content_type.lower() == "application/pdf"
        or PurePath(attachment.filename).suffix.lower() == ".pdf"
    )


def _invoice_filename(
    *,
    provider_slug: str,
    message: MailMessage,
    attachment: Attachment,
    index: int,
    total: int,
) -> str:
    slug = re.sub(r"[^a-z0-9-]+", "-", provider_slug.lower()).strip("-")
    date = message.received_at.date().isoformat()
    suffix = f"-{index}" if total > 1 else ""
    source_stem = re.sub(
        r"[^a-z0-9-]+",
        "-",
        PurePath(attachment.filename).stem.lower(),
    ).strip("-")
    source_part = f"-{source_stem}" if source_stem else ""
    return f"{date}-{slug}{source_part}{suffix}.pdf"

