from __future__ import annotations

from datetime import datetime, timezone

from email_automations.demo_adapters import (
    MemoryFileStore,
    MemoryMailer,
    MemoryMailbox,
)
from email_automations.gmail_invoices.processor import (
    GmailInvoicesProcessor,
    ProviderRule,
)
from email_automations.models import Attachment, MailMessage
from email_automations.state import StateStore


def _message(*, sender: str = "billing@example.invalid") -> MailMessage:
    return MailMessage(
        id="invoice-message",
        thread_id="invoice-thread",
        sender=sender,
        subject="Monthly invoice",
        body_text="Attached.",
        received_at=datetime(2030, 1, 10, tzinfo=timezone.utc),
        attachments=(
            Attachment("invoice.pdf", "application/pdf", b"pdf"),
        ),
    )


def _processor(tmp_path, message: MailMessage):
    mailer = MemoryMailer()
    files = MemoryFileStore()
    processor = GmailInvoicesProcessor(
        mailbox=MemoryMailbox([message]),
        mailer=mailer,
        file_store=files,
        state=StateStore(tmp_path / "state.sqlite3"),
        label="Automation/Invoices",
        review_recipient="review@example.invalid",
        provider_rules=[
            ProviderRule(
                slug="example-provider",
                sender_contains=("billing@example.invalid",),
            )
        ],
    )
    return processor, mailer, files


def test_saves_pdf_once(tmp_path):
    processor, mailer, files = _processor(tmp_path, _message())

    first = processor.run()
    second = processor.run()

    assert first.completed == 1
    assert second.skipped == 1
    assert list(files.saved) == [
        "2030-01-10-example-provider-invoice.pdf"
    ]
    assert mailer.sent == []


def test_unknown_provider_goes_to_review_once(tmp_path):
    processor, mailer, files = _processor(
        tmp_path,
        _message(sender="unknown@example.invalid"),
    )

    first = processor.run()
    second = processor.run()

    assert first.review == 1
    assert second.skipped == 1
    assert files.saved == {}
    assert len(mailer.sent) == 1
    assert mailer.sent[0].recipient == "review@example.invalid"


def test_dry_run_has_no_effect(tmp_path):
    processor, _mailer, files = _processor(tmp_path, _message())

    dry = processor.run(dry_run=True)
    real = processor.run()

    assert dry.skipped == 1
    assert real.completed == 1
    assert len(files.saved) == 1

