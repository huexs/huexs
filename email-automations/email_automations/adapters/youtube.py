"""Adaptador `YouTubeSource` sobre la YouTube Data API.

Per reduir quota, l'identificador de la llista de pujades de cada canal es
cacheja en un fitxer local i només es demana un cop per canal.
"""

from __future__ import annotations

import json
import logging
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Iterator

from ..config import GoogleConfig
from ..models import YouTubeVideo
from .google_auth import YOUTUBE_READONLY, build_service

logger = logging.getLogger(__name__)

_PAGE_SIZE = 50


class YouTubeError(RuntimeError):
    """Resposta de YouTube que no es pot normalitzar."""


class UploadsPlaylistCache:
    def __init__(self, path: str | Path) -> None:
        self.path = Path(path)
        self._entries: dict[str, str] = {}
        if self.path.exists():
            try:
                loaded = json.loads(self.path.read_text(encoding="utf-8"))
            except json.JSONDecodeError:
                logger.warning("youtube: cache il·legible, es reconstruirà")
                loaded = {}
            if isinstance(loaded, dict):
                self._entries = {str(k): str(v) for k, v in loaded.items()}

    def get(self, channel_id: str) -> str | None:
        return self._entries.get(channel_id)

    def set(self, channel_id: str, playlist_id: str) -> None:
        if self._entries.get(channel_id) == playlist_id:
            return
        self._entries[channel_id] = playlist_id
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self.path.write_text(
            json.dumps(self._entries, sort_keys=True), encoding="utf-8"
        )


class YouTubeApiSource:
    def __init__(
        self,
        service: Any,
        *,
        cache: UploadsPlaylistCache,
        max_channels: int = 50,
    ) -> None:
        self.service = service
        self.cache = cache
        self.max_channels = max_channels

    def list_recent_videos(
        self, *, lookback_hours: int, limit: int
    ) -> list[YouTubeVideo]:
        if limit <= 0:
            return []
        cutoff = datetime.now(timezone.utc) - timedelta(hours=lookback_hours)
        videos: list[YouTubeVideo] = []
        for channel_id, channel_title in self._subscriptions():
            playlist_id = self._uploads_playlist(channel_id)
            videos.extend(
                self._recent_from_playlist(
                    playlist_id, channel_title, cutoff=cutoff, limit=limit
                )
            )
        videos.sort(key=lambda video: video.published_at, reverse=True)
        logger.info("youtube: %d vídeos dins la finestra", len(videos))
        return videos[:limit]

    def _subscriptions(self) -> Iterator[tuple[str, str]]:
        page_token: str | None = None
        seen = 0
        while seen < self.max_channels:
            response = (
                self.service.subscriptions()
                .list(
                    part="snippet",
                    mine=True,
                    maxResults=min(_PAGE_SIZE, self.max_channels - seen),
                    pageToken=page_token,
                )
                .execute()
            )
            for item in (response or {}).get("items") or ():
                snippet = item.get("snippet") or {}
                channel_id = ((snippet.get("resourceId") or {}).get("channelId"))
                if not channel_id:
                    raise YouTubeError("Subscripció sense channelId")
                yield channel_id, snippet.get("title") or channel_id
                seen += 1
                if seen >= self.max_channels:
                    return
            page_token = (response or {}).get("nextPageToken")
            if not page_token:
                return

    def _uploads_playlist(self, channel_id: str) -> str:
        cached = self.cache.get(channel_id)
        if cached:
            return cached
        response = (
            self.service.channels()
            .list(part="contentDetails", id=channel_id)
            .execute()
        )
        items = (response or {}).get("items") or []
        if not items:
            raise YouTubeError(f"El canal {channel_id} no té contentDetails")
        playlist_id = (
            ((items[0].get("contentDetails") or {}).get("relatedPlaylists") or {})
        ).get("uploads")
        if not playlist_id:
            raise YouTubeError(f"El canal {channel_id} no té llista de pujades")
        self.cache.set(channel_id, playlist_id)
        return playlist_id

    def _recent_from_playlist(
        self,
        playlist_id: str,
        channel_title: str,
        *,
        cutoff: datetime,
        limit: int,
    ) -> Iterator[YouTubeVideo]:
        response = (
            self.service.playlistItems()
            .list(
                part="snippet,contentDetails",
                playlistId=playlist_id,
                maxResults=min(_PAGE_SIZE, limit),
            )
            .execute()
        )
        for item in (response or {}).get("items") or ():
            snippet = item.get("snippet") or {}
            details = item.get("contentDetails") or {}
            video_id = details.get("videoId") or (
                (snippet.get("resourceId") or {}).get("videoId")
            )
            if not video_id:
                raise YouTubeError("Element de llista sense videoId")
            published_at = _parse_timestamp(
                details.get("videoPublishedAt") or snippet.get("publishedAt")
            )
            if published_at < cutoff:
                continue
            yield YouTubeVideo(
                id=video_id,
                channel=channel_title,
                title=snippet.get("title") or "(sense títol)",
                url=f"https://www.youtube.com/watch?v={video_id}",
                published_at=published_at,
            )


def build_youtube_service(config: GoogleConfig) -> Any:
    return build_service("youtube", "v3", config, [YOUTUBE_READONLY])


def _parse_timestamp(value: str | None) -> datetime:
    if not value:
        raise YouTubeError("Vídeo sense data de publicació")
    normalized = value.replace("Z", "+00:00")
    try:
        parsed = datetime.fromisoformat(normalized)
    except ValueError as exc:
        raise YouTubeError(f"Data de publicació invàlida: {value}") from exc
    if parsed.tzinfo is None:
        return parsed.replace(tzinfo=timezone.utc)
    return parsed.astimezone(timezone.utc)
