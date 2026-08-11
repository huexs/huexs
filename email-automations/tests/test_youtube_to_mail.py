from __future__ import annotations

from datetime import datetime, timezone

from email_automations.demo_adapters import MemoryMailer, MemoryYouTubeSource
from email_automations.models import OutgoingEmail, YouTubeVideo
from email_automations.state import StateStore
from email_automations.youtube_to_mail.processor import YouTubeToMailProcessor


def _video() -> YouTubeVideo:
    return YouTubeVideo(
        id="video-001",
        channel="Example Channel",
        title="Example video",
        url="https://www.youtube.com/watch?v=example",
        published_at=datetime(2030, 1, 10, tzinfo=timezone.utc),
    )


def _processor(tmp_path, mailer):
    return YouTubeToMailProcessor(
        source=MemoryYouTubeSource([_video()]),
        mailer=mailer,
        state=StateStore(tmp_path / "state.sqlite3"),
        recipient="videos@example.invalid",
    )


def test_notifies_each_video_once(tmp_path):
    mailer = MemoryMailer()
    processor = _processor(tmp_path, mailer)

    first = processor.run()
    second = processor.run()

    assert first.completed == 1
    assert second.skipped == 1
    assert len(mailer.sent) == 1


def test_initialization_marks_without_sending(tmp_path):
    mailer = MemoryMailer()
    processor = _processor(tmp_path, mailer)

    initialized = processor.run(initialize_without_sending=True)
    later = processor.run()

    assert initialized.completed == 1
    assert later.skipped == 1
    assert mailer.sent == []


def test_failed_send_can_retry(tmp_path):
    class OneFailureMailer:
        def __init__(self) -> None:
            self.calls = 0

        def send(self, email: OutgoingEmail) -> str:
            del email
            self.calls += 1
            if self.calls == 1:
                raise RuntimeError("temporary failure")
            return "sent-on-retry"

    mailer = OneFailureMailer()
    processor = _processor(tmp_path, mailer)

    first = processor.run()
    second = processor.run()

    assert first.failed == 1
    assert second.completed == 1
    assert mailer.calls == 2

