from __future__ import annotations

from datetime import datetime, timedelta, timezone
from pathlib import Path

import pytest

from email_automations.adapters.youtube import (
    UploadsPlaylistCache,
    YouTubeApiSource,
    YouTubeError,
)
from tests.fakes import FakeYouTubeService


def _iso(hours_ago: float) -> str:
    moment = datetime.now(timezone.utc) - timedelta(hours=hours_ago)
    return moment.replace(microsecond=0).isoformat().replace("+00:00", "Z")


def _subscriptions(*channels: str) -> dict:
    return {
        "items": [
            {"snippet": {"title": f"Canal {c}", "resourceId": {"channelId": c}}}
            for c in channels
        ]
    }


def _uploads(playlist_id: str) -> dict:
    return {
        "items": [
            {"contentDetails": {"relatedPlaylists": {"uploads": playlist_id}}}
        ]
    }


def _playlist_items(*entries: tuple[str, str]) -> dict:
    return {
        "items": [
            {
                "contentDetails": {"videoId": video_id, "videoPublishedAt": published},
                "snippet": {"title": f"Vídeo {video_id}"},
            }
            for video_id, published in entries
        ]
    }


def _source(service: FakeYouTubeService, tmp_path: Path, **kwargs) -> YouTubeApiSource:
    return YouTubeApiSource(
        service, cache=UploadsPlaylistCache(tmp_path / "cache.json"), **kwargs
    )


def test_returns_recent_videos_newest_first(tmp_path: Path) -> None:
    service = FakeYouTubeService()
    service.subscriptions_list.responses.append(_subscriptions("c1", "c2"))
    service.channels_list.responses.extend([_uploads("UU1"), _uploads("UU2")])
    service.playlist_items_list.responses.extend(
        [
            _playlist_items(("old", _iso(100)), ("v1", _iso(10))),
            _playlist_items(("v2", _iso(2))),
        ]
    )

    videos = _source(service, tmp_path).list_recent_videos(
        lookback_hours=48, limit=10
    )

    assert [video.id for video in videos] == ["v2", "v1"]
    assert videos[0].channel == "Canal c2"
    assert videos[0].url == "https://www.youtube.com/watch?v=v2"
    assert all(video.published_at.tzinfo is timezone.utc for video in videos)


def test_applies_limit_after_sorting(tmp_path: Path) -> None:
    service = FakeYouTubeService()
    service.subscriptions_list.responses.append(_subscriptions("c1"))
    service.channels_list.responses.append(_uploads("UU1"))
    service.playlist_items_list.responses.append(
        _playlist_items(("a", _iso(5)), ("b", _iso(1)))
    )

    videos = _source(service, tmp_path).list_recent_videos(
        lookback_hours=48, limit=1
    )

    assert [video.id for video in videos] == ["b"]


def test_channel_limit_and_pagination(tmp_path: Path) -> None:
    service = FakeYouTubeService()
    service.subscriptions_list.responses.append(_subscriptions("c1", "c2"))
    service.channels_list.responses.append(_uploads("UU1"))
    service.playlist_items_list.responses.append(_playlist_items(("a", _iso(1))))

    videos = _source(service, tmp_path, max_channels=1).list_recent_videos(
        lookback_hours=48, limit=10
    )

    assert [video.id for video in videos] == ["a"]
    assert len(service.channels_list.calls) == 1


def test_uploads_playlist_is_cached_across_runs(tmp_path: Path) -> None:
    service = FakeYouTubeService()
    service.subscriptions_list.responses.append(_subscriptions("c1"))
    service.channels_list.responses.append(_uploads("UU1"))
    service.playlist_items_list.responses.append(_playlist_items(("a", _iso(1))))
    _source(service, tmp_path).list_recent_videos(lookback_hours=48, limit=5)

    service.subscriptions_list.responses.append(_subscriptions("c1"))
    service.playlist_items_list.responses.append(_playlist_items(("b", _iso(1))))
    videos = _source(service, tmp_path).list_recent_videos(lookback_hours=48, limit=5)

    assert [video.id for video in videos] == ["b"]
    assert len(service.channels_list.calls) == 1


def test_invalid_timestamp_raises(tmp_path: Path) -> None:
    service = FakeYouTubeService()
    service.subscriptions_list.responses.append(_subscriptions("c1"))
    service.channels_list.responses.append(_uploads("UU1"))
    service.playlist_items_list.responses.append(_playlist_items(("a", "ahir")))

    with pytest.raises(YouTubeError):
        _source(service, tmp_path).list_recent_videos(lookback_hours=48, limit=5)


def test_remote_error_is_not_a_silent_success(tmp_path: Path) -> None:
    service = FakeYouTubeService()
    service.subscriptions_list.responses.append(RuntimeError("quotaExceeded"))

    with pytest.raises(RuntimeError, match="quotaExceeded"):
        _source(service, tmp_path).list_recent_videos(lookback_hours=48, limit=5)
