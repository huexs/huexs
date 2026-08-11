from __future__ import annotations

import base64
from datetime import timezone

import pytest

from email_automations.adapters.gmail import GmailError, GmailMailbox, GmailMailer
from email_automations.models import OutgoingEmail
from tests.fakes import FakeGmailService


def _b64(text: str) -> str:
    return base64.urlsafe_b64encode(text.encode("utf-8")).decode("ascii").rstrip("=")


def _labels() -> dict:
    return {"labels": [{"id": "Label_7", "name": "Automation/Invoices"}]}


def _message(message_id: str = "m1") -> dict:
    return {
        "id": message_id,
        "threadId": "t1",
        "internalDate": "1893495600000",
        "payload": {
            "mimeType": "multipart/mixed",
            "headers": [
                {"name": "From", "value": "billing@example.invalid"},
                {"name": "Subject", "value": "Invoice 42"},
            ],
            "parts": [
                {
                    "mimeType": "text/plain",
                    "body": {"data": _b64("Adjuntem la factura.")},
                },
                {
                    "mimeType": "application/pdf",
                    "filename": "invoice.pdf",
                    "body": {"attachmentId": "att-1", "size": 12},
                },
            ],
        },
    }


def test_normalizes_message_and_downloads_pdf() -> None:
    service = FakeGmailService()
    service.labels_list.responses.append(_labels())
    service.messages_list.responses.append({"messages": [{"id": "m1"}]})
    service.messages_get.responses.append(_message())
    service.attachments_get.responses.append({"data": _b64("%PDF-1.4")})

    [message] = GmailMailbox(service).list_messages(
        label="Automation/Invoices", limit=10
    )

    assert message.id == "m1"
    assert message.thread_id == "t1"
    assert message.sender == "billing@example.invalid"
    assert message.subject == "Invoice 42"
    assert message.body_text == "Adjuntem la factura."
    assert message.received_at.tzinfo is timezone.utc
    assert [a.filename for a in message.attachments] == ["invoice.pdf"]
    assert message.attachments[0].data == b"%PDF-1.4"


def test_falls_back_to_html_body() -> None:
    service = FakeGmailService()
    service.labels_list.responses.append(_labels())
    service.messages_list.responses.append({"messages": [{"id": "m1"}]})
    payload = _message()
    payload["payload"]["parts"] = [
        {"mimeType": "text/html", "body": {"data": _b64("<p>Hola <b>grup A</b></p>")}}
    ]
    service.messages_get.responses.append(payload)

    [message] = GmailMailbox(service).list_messages(
        label="Automation/Invoices", limit=1
    )

    assert "Hola" in message.body_text
    assert "<p>" not in message.body_text


def test_paginates_and_respects_limit() -> None:
    service = FakeGmailService()
    service.labels_list.responses.append(_labels())
    service.messages_list.responses.extend(
        [
            {"messages": [{"id": "m1"}, {"id": "m2"}], "nextPageToken": "p2"},
            {"messages": [{"id": "m3"}]},
        ]
    )
    for index in range(3):
        service.messages_get.responses.append(_message(f"m{index + 1}"))
    service.attachments_get.responses.extend([{"data": _b64("x")}] * 3)

    messages = GmailMailbox(service).list_messages(
        label="Automation/Invoices", limit=3
    )

    assert [m.id for m in messages] == ["m1", "m2", "m3"]
    assert service.messages_list.calls[1]["pageToken"] == "p2"


def test_limit_zero_makes_no_remote_calls() -> None:
    service = FakeGmailService()

    assert GmailMailbox(service).list_messages(label="Automation/Invoices", limit=0) == []
    assert service.labels_list.calls == []


def test_unknown_label_raises() -> None:
    service = FakeGmailService()
    service.labels_list.responses.append({"labels": []})

    with pytest.raises(GmailError):
        GmailMailbox(service).list_messages(label="Automation/Missing", limit=1)


def test_remote_error_is_not_a_silent_success() -> None:
    service = FakeGmailService()
    service.labels_list.responses.append(_labels())
    service.messages_list.responses.append(RuntimeError("503"))

    with pytest.raises(RuntimeError, match="503"):
        GmailMailbox(service).list_messages(label="Automation/Invoices", limit=1)


def test_oversized_attachment_is_skipped() -> None:
    service = FakeGmailService()
    service.labels_list.responses.append(_labels())
    service.messages_list.responses.append({"messages": [{"id": "m1"}]})
    service.messages_get.responses.append(_message())

    [message] = GmailMailbox(service, max_attachment_bytes=1).list_messages(
        label="Automation/Invoices", limit=1
    )

    assert message.attachments == ()
    assert service.attachments_get.calls == []


def test_mailer_returns_provider_id_and_encodes_body() -> None:
    service = FakeGmailService()
    service.messages_send.responses.append({"id": "sent-1"})

    mail_id = GmailMailer(service).send(
        OutgoingEmail(
            recipient="review@example.invalid", subject="Revisió", text_body="Cos"
        )
    )

    assert mail_id == "sent-1"
    raw = service.messages_send.calls[0]["body"]["raw"]
    decoded = base64.urlsafe_b64decode(raw + "=" * (-len(raw) % 4)).decode("utf-8")
    assert "review@example.invalid" in decoded
    assert "Cos" in decoded


def test_mailer_without_id_raises() -> None:
    service = FakeGmailService()
    service.messages_send.responses.append({})

    with pytest.raises(GmailError):
        GmailMailer(service).send(
            OutgoingEmail(recipient="a@example.invalid", subject="s", text_body="b")
        )
