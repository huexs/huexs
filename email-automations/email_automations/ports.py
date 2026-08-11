from __future__ import annotations

from collections.abc import Sequence
from typing import Protocol

from .models import MailMessage, OutgoingEmail, YouTubeVideo


class Mailbox(Protocol):
    def list_messages(self, *, label: str, limit: int) -> Sequence[MailMessage]:
        """Return candidate messages, newest first."""


class Mailer(Protocol):
    def send(self, email: OutgoingEmail) -> str:
        """Send an email and return the provider message identifier."""


class FileStore(Protocol):
    def save(self, *, filename: str, data: bytes, content_type: str) -> str:
        """Persist a file and return a stable locator or identifier."""


class Summarizer(Protocol):
    def summarize(self, *, source_text: str, targets: Sequence[str]) -> str | None:
        """Return a relevant summary, or None when the message is irrelevant."""


class PdfTextExtractor(Protocol):
    def extract(self, data: bytes) -> str:
        """Extract text from a PDF."""


class YouTubeSource(Protocol):
    def list_recent_videos(
        self, *, lookback_hours: int, limit: int
    ) -> Sequence[YouTubeVideo]:
        """Return recent videos from the authenticated user's subscriptions."""

