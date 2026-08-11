from __future__ import annotations

from datetime import datetime, timezone

from email_automations.demo_adapters import (
    EmptyPdfExtractor,
    KeywordSummarizer,
    MemoryMailer,
    MemoryMailbox,
)
from email_automations.gmail_school.processor import GmailSchoolProcessor
from email_automations.models import MailMessage
from email_automations.state import StateStore


def _message(message_id: str, body: str) -> MailMessage:
    return MailMessage(
        id=message_id,
        thread_id="shared-thread",
        sender="school@example.invalid",
        subject="Announcement",
        body_text=body,
        received_at=datetime(2030, 1, 10, tzinfo=timezone.utc),
    )


def _processor(tmp_path, messages):
    mailer = MemoryMailer()
    processor = GmailSchoolProcessor(
        mailbox=MemoryMailbox(messages),
        mailer=mailer,
        summarizer=KeywordSummarizer(),
        pdf_extractor=EmptyPdfExtractor(),
        state=StateStore(tmp_path / "state.sqlite3"),
        label="Automation/School",
        summary_recipient="summary@example.invalid",
        targets=["Group A"],
    )
    return processor, mailer


def test_sends_one_summary_per_thread(tmp_path):
    processor, mailer = _processor(
        tmp_path,
        [
            _message("school-message-1", "Group A activity."),
            _message("school-message-2", "Group A reminder."),
        ],
    )

    result = processor.run()

    assert result.completed == 1
    assert result.skipped == 1
    assert len(mailer.sent) == 1


def test_irrelevant_thread_is_completed_without_email(tmp_path):
    processor, mailer = _processor(
        tmp_path,
        [_message("school-message-1", "Message for another group.")],
    )

    first = processor.run()
    second = processor.run()

    assert first.skipped == 1
    assert second.skipped == 1
    assert mailer.sent == []

