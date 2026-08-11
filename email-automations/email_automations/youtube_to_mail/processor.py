from __future__ import annotations

from ..models import OutgoingEmail, YouTubeVideo
from ..ports import Mailer, YouTubeSource
from ..results import RunResult
from ..state import StateStore

AUTOMATION = "youtube_to_mail"


class YouTubeToMailProcessor:
    def __init__(
        self,
        *,
        source: YouTubeSource,
        mailer: Mailer,
        state: StateStore,
        recipient: str,
        lookback_hours: int = 48,
        max_videos: int = 25,
    ) -> None:
        self.source = source
        self.mailer = mailer
        self.state = state
        self.recipient = recipient
        self.lookback_hours = lookback_hours
        self.max_videos = max_videos

    def run(
        self,
        *,
        dry_run: bool = False,
        initialize_without_sending: bool = False,
    ) -> RunResult:
        videos = self.source.list_recent_videos(
            lookback_hours=self.lookback_hours,
            limit=self.max_videos,
        )
        result = RunResult(discovered=len(videos))
        for video in videos:
            if dry_run:
                result.skipped += 1
                continue
            if not self.state.claim(
                AUTOMATION,
                video.id,
                metadata={"channel": video.channel, "title": video.title},
            ):
                result.skipped += 1
                continue
            try:
                if initialize_without_sending:
                    self.state.complete(
                        AUTOMATION,
                        video.id,
                        metadata={"outcome": "initialized"},
                    )
                    result.completed += 1
                    continue

                mail_id = self.mailer.send(_notification(video, self.recipient))
                self.state.complete(
                    AUTOMATION,
                    video.id,
                    metadata={"outcome": "sent", "mail_id": mail_id},
                )
                result.completed += 1
            except Exception as exc:
                self.state.fail(AUTOMATION, video.id, exc)
                result.failed += 1
        return result


def _notification(video: YouTubeVideo, recipient: str) -> OutgoingEmail:
    published = video.published_at.isoformat()
    return OutgoingEmail(
        recipient=recipient,
        subject=f"[youtube_to_mail] {video.channel}: {video.title}",
        text_body=(
            f"Canal: {video.channel}\n"
            f"Títol: {video.title}\n"
            f"Publicat: {published}\n"
            f"Enllaç: {video.url}"
        ),
    )

