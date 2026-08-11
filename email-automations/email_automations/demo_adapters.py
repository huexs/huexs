from __future__ import annotations

from collections.abc import Sequence

from .models import MailMessage, OutgoingEmail, YouTubeVideo


class MemoryMailbox:
    def __init__(self, messages: Sequence[MailMessage]) -> None:
        self.messages = list(messages)

    def list_messages(self, *, label: str, limit: int) -> list[MailMessage]:
        del label
        return self.messages[:limit]


class MemoryMailer:
    def __init__(self) -> None:
        self.sent: list[OutgoingEmail] = []

    def send(self, email: OutgoingEmail) -> str:
        self.sent.append(email)
        return f"demo-mail-{len(self.sent)}"


class MemoryFileStore:
    def __init__(self) -> None:
        self.saved: dict[str, bytes] = {}

    def save(self, *, filename: str, data: bytes, content_type: str) -> str:
        del content_type
        self.saved[filename] = data
        return f"memory://{filename}"


class KeywordSummarizer:
    """Deterministic demo summarizer; replace it in production."""

    def summarize(
        self, *, source_text: str, targets: Sequence[str]
    ) -> str | None:
        matching = [target for target in targets if target.lower() in source_text.lower()]
        if not matching:
            return None
        excerpt = " ".join(source_text.split())[:400]
        return f"Relevant per a {', '.join(matching)}: {excerpt}"


class EmptyPdfExtractor:
    def extract(self, data: bytes) -> str:
        del data
        return ""


class MemoryYouTubeSource:
    def __init__(self, videos: Sequence[YouTubeVideo]) -> None:
        self.videos = list(videos)

    def list_recent_videos(
        self, *, lookback_hours: int, limit: int
    ) -> list[YouTubeVideo]:
        del lookback_hours
        return self.videos[:limit]

