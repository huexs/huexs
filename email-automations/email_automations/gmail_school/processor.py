from __future__ import annotations

from collections.abc import Sequence

from ..models import MailMessage, OutgoingEmail
from ..ports import Mailbox, Mailer, PdfTextExtractor, Summarizer
from ..results import RunResult
from ..state import StateStore

AUTOMATION = "gmail_school"


class GmailSchoolProcessor:
    def __init__(
        self,
        *,
        mailbox: Mailbox,
        mailer: Mailer,
        summarizer: Summarizer,
        pdf_extractor: PdfTextExtractor,
        state: StateStore,
        label: str,
        summary_recipient: str,
        targets: Sequence[str],
    ) -> None:
        self.mailbox = mailbox
        self.mailer = mailer
        self.summarizer = summarizer
        self.pdf_extractor = pdf_extractor
        self.state = state
        self.label = label
        self.summary_recipient = summary_recipient
        self.targets = tuple(targets)

    def run(self, *, limit: int = 100, dry_run: bool = False) -> RunResult:
        messages = self.mailbox.list_messages(label=self.label, limit=limit)
        result = RunResult(discovered=len(messages))
        for message in messages:
            item_id = f"thread:{message.thread_id}"
            if dry_run:
                result.skipped += 1
                continue
            if not self.state.claim(
                AUTOMATION,
                item_id,
                metadata={"message_id": message.id},
            ):
                result.skipped += 1
                continue
            try:
                source_text = _source_text(message, self.pdf_extractor)
                summary = self.summarizer.summarize(
                    source_text=source_text,
                    targets=self.targets,
                )
                if not summary:
                    self.state.complete(
                        AUTOMATION,
                        item_id,
                        metadata={"outcome": "irrelevant"},
                    )
                    result.skipped += 1
                    continue

                mail_id = self.mailer.send(
                    OutgoingEmail(
                        recipient=self.summary_recipient,
                        subject=f"[gmail_school] {message.subject}",
                        text_body=summary,
                    )
                )
                self.state.complete(
                    AUTOMATION,
                    item_id,
                    metadata={"outcome": "sent", "mail_id": mail_id},
                )
                result.completed += 1
            except Exception as exc:
                self.state.fail(AUTOMATION, item_id, exc)
                result.failed += 1
        return result


def _source_text(
    message: MailMessage,
    pdf_extractor: PdfTextExtractor,
) -> str:
    parts = [
        f"Assumpte: {message.subject}",
        f"Remitent: {message.sender}",
        message.body_text,
    ]
    for attachment in message.attachments:
        if (
            attachment.content_type.lower() == "application/pdf"
            or attachment.filename.lower().endswith(".pdf")
        ):
            extracted = pdf_extractor.extract(attachment.data).strip()
            if extracted:
                parts.append(f"Adjunt {attachment.filename}:\n{extracted}")
    return "\n\n".join(part for part in parts if part)

