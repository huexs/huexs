from __future__ import annotations

from datetime import datetime
from pathlib import Path

from email_automations.demo_adapters import (
    EmptyPdfExtractor,
    KeywordSummarizer,
    MemoryFileStore,
    MemoryMailer,
    MemoryMailbox,
    MemoryYouTubeSource,
)
from email_automations.gmail_invoices.processor import (
    GmailInvoicesProcessor,
    ProviderRule,
)
from email_automations.gmail_school.processor import GmailSchoolProcessor
from email_automations.models import Attachment, MailMessage, YouTubeVideo
from email_automations.state import StateStore
from email_automations.youtube_to_mail.processor import YouTubeToMailProcessor


def main() -> None:
    state = StateStore(Path("data/demo.sqlite3"))

    invoice = MailMessage(
        id="message-invoice-001",
        thread_id="thread-invoice-001",
        sender="billing@example.invalid",
        subject="Invoice for the current period",
        body_text="Please find the invoice attached.",
        received_at=datetime.fromisoformat("2030-01-10T09:00:00+00:00"),
        attachments=(
            Attachment("invoice.pdf", "application/pdf", b"demo-pdf"),
        ),
    )
    invoice_mailer = MemoryMailer()
    invoice_store = MemoryFileStore()
    invoice_result = GmailInvoicesProcessor(
        mailbox=MemoryMailbox([invoice]),
        mailer=invoice_mailer,
        file_store=invoice_store,
        state=state,
        label="Automation/Invoices",
        review_recipient="review@example.invalid",
        provider_rules=[
            ProviderRule(
                slug="example-provider",
                sender_contains=("billing@example.invalid",),
            )
        ],
    ).run()

    school = MailMessage(
        id="message-school-001",
        thread_id="thread-school-001",
        sender="school@example.invalid",
        subject="General announcement",
        body_text="Group A has an activity next week.",
        received_at=datetime.fromisoformat("2030-01-10T10:00:00+00:00"),
    )
    school_mailer = MemoryMailer()
    school_result = GmailSchoolProcessor(
        mailbox=MemoryMailbox([school]),
        mailer=school_mailer,
        summarizer=KeywordSummarizer(),
        pdf_extractor=EmptyPdfExtractor(),
        state=state,
        label="Automation/School",
        summary_recipient="summary@example.invalid",
        targets=["Group A"],
    ).run()

    video = YouTubeVideo(
        id="video-001",
        channel="Example Channel",
        title="A new example video",
        url="https://www.youtube.com/watch?v=example",
        published_at=datetime.fromisoformat("2030-01-10T11:00:00+00:00"),
    )
    video_mailer = MemoryMailer()
    video_result = YouTubeToMailProcessor(
        source=MemoryYouTubeSource([video]),
        mailer=video_mailer,
        state=state,
        recipient="videos@example.invalid",
    ).run()

    print("gmail_invoices", invoice_result)
    print("gmail_school", school_result)
    print("youtube_to_mail", video_result)


if __name__ == "__main__":
    main()

