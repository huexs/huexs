from __future__ import annotations

from dataclasses import dataclass, field
from datetime import datetime, timezone


@dataclass(frozen=True)
class Attachment:
    filename: str
    content_type: str
    data: bytes


@dataclass(frozen=True)
class MailMessage:
    id: str
    thread_id: str
    sender: str
    subject: str
    body_text: str
    received_at: datetime
    attachments: tuple[Attachment, ...] = field(default_factory=tuple)


@dataclass(frozen=True)
class OutgoingEmail:
    recipient: str
    subject: str
    text_body: str


@dataclass(frozen=True)
class YouTubeVideo:
    id: str
    channel: str
    title: str
    url: str
    published_at: datetime


def utc_now() -> datetime:
    return datetime.now(timezone.utc)

