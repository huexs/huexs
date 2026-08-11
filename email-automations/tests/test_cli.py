"""Comprova el cablejat de les tres ordres: configuració, adaptadors i estat.

Els constructors de serveis de Google es substitueixen per dobles, de manera
que no cal cap credencial ni accés a la xarxa.
"""

from __future__ import annotations

import base64
import io
import json
from datetime import datetime, timedelta, timezone
from pathlib import Path

import pytest

from email_automations.gmail_invoices import cli as invoices_cli
from email_automations.gmail_school import cli as school_cli
from email_automations.youtube_to_mail import cli as youtube_cli
from tests.fakes import FakeGmailService, FakeYouTubeService


def _b64(text: str) -> str:
    return _b64_bytes(text.encode("utf-8"))


def _b64_bytes(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).decode("ascii").rstrip("=")


def _blank_pdf() -> bytes:
    from pypdf import PdfWriter

    writer = PdfWriter()
    writer.add_blank_page(width=200, height=200)
    buffer = io.BytesIO()
    writer.write(buffer)
    return buffer.getvalue()


def _config(tmp_path: Path, **overrides) -> Path:
    payload = {
        "database_path": str(tmp_path / "state.sqlite3"),
        "storage": {"type": "local", "local_root": str(tmp_path / "files")},
        "summarizer": {"type": "keyword"},
        "gmail_invoices": {
            "label": "Automation/Invoices",
            "review_recipient": "review@example.invalid",
            "providers": [
                {
                    "slug": "example-provider",
                    "sender_contains": ["billing@example.invalid"],
                }
            ],
        },
        "gmail_school": {
            "label": "Automation/School",
            "summary_recipient": "summary@example.invalid",
            "targets": ["Grup A"],
        },
        "youtube_to_mail": {
            "recipient": "videos@example.invalid",
            "cache_path": str(tmp_path / "youtube.json"),
        },
    }
    payload.update(overrides)
    path = tmp_path / "config.local.json"
    path.write_text(json.dumps(payload), encoding="utf-8")
    return path


def _gmail_with_invoice(label_name: str) -> FakeGmailService:
    service = FakeGmailService()
    service.labels_list.handler = lambda _: {
        "labels": [{"id": "L1", "name": label_name}]
    }
    service.messages_list.handler = lambda _: {"messages": [{"id": "m1"}]}
    service.messages_get.handler = lambda _: {
        "id": "m1",
        "threadId": "t1",
        "internalDate": "1893495600000",
        "payload": {
            "headers": [
                {"name": "From", "value": "billing@example.invalid"},
                {"name": "Subject", "value": "Invoice 42"},
            ],
            "parts": [
                {"mimeType": "text/plain", "body": {"data": _b64("Grup A: sortida")}},
                {
                    "mimeType": "application/pdf",
                    "filename": "invoice.pdf",
                    "body": {"attachmentId": "a1", "size": 8},
                },
            ],
        },
    }
    service.attachments_get.handler = lambda _: {"data": _b64_bytes(_blank_pdf())}
    service.messages_send.handler = lambda _: {"id": "sent-1"}
    return service


@pytest.fixture()
def gmail(monkeypatch) -> FakeGmailService:
    service = _gmail_with_invoice("Automation/Invoices")
    for module in (invoices_cli, school_cli):
        monkeypatch.setattr(module, "build_mailbox_service", lambda _c: service)
        monkeypatch.setattr(module, "build_mailer_service", lambda _c: service)
    monkeypatch.setattr(youtube_cli, "build_mailer_service", lambda _c: service)
    return service


def test_invoices_dry_run_has_no_external_effects(tmp_path, gmail, capsys) -> None:
    code = invoices_cli.main(["--config", str(_config(tmp_path)), "--dry-run"])

    assert code == 0
    assert "discovered=1 completed=0 skipped=1" in capsys.readouterr().out
    assert gmail.messages_send.calls == []
    assert not (tmp_path / "files").exists() or not list((tmp_path / "files").iterdir())


def test_invoices_saves_pdf_and_is_idempotent(tmp_path, gmail, capsys) -> None:
    config = _config(tmp_path)

    assert invoices_cli.main(["--config", str(config)]) == 0
    first = capsys.readouterr().out
    assert invoices_cli.main(["--config", str(config)]) == 0
    second = capsys.readouterr().out

    assert "completed=1" in first
    assert "skipped=1" in second
    saved = list((tmp_path / "files").iterdir())
    assert [path.name for path in saved] == ["2030-01-01-example-provider-invoice.pdf"]
    assert gmail.messages_send.calls == []


def test_invoices_health_check(tmp_path, gmail, capsys) -> None:
    assert invoices_cli.main(["--config", str(_config(tmp_path)), "--health-check"]) == 0
    assert "health=ok" in capsys.readouterr().out


def test_school_summarizes_once_per_thread(tmp_path, gmail, capsys) -> None:
    config = _config(tmp_path)
    gmail.labels_list.handler = lambda _: {
        "labels": [{"id": "L2", "name": "Automation/School"}]
    }

    assert school_cli.main(["--config", str(config)]) == 0
    first = capsys.readouterr().out
    assert school_cli.main(["--config", str(config)]) == 0
    second = capsys.readouterr().out

    assert "completed=1" in first
    assert "skipped=1" in second
    assert len(gmail.messages_send.calls) == 1


def test_youtube_initializes_without_sending(tmp_path, gmail, monkeypatch, capsys) -> None:
    published = (
        (datetime.now(timezone.utc) - timedelta(hours=1))
        .replace(microsecond=0)
        .isoformat()
        .replace("+00:00", "Z")
    )
    service = FakeYouTubeService()
    service.subscriptions_list.handler = lambda _: {
        "items": [{"snippet": {"title": "Canal", "resourceId": {"channelId": "c1"}}}]
    }
    service.channels_list.handler = lambda _: {
        "items": [{"contentDetails": {"relatedPlaylists": {"uploads": "UU1"}}}]
    }
    service.playlist_items_list.handler = lambda _: {
        "items": [
            {
                "contentDetails": {"videoId": "v1", "videoPublishedAt": published},
                "snippet": {"title": "Vídeo"},
            }
        ]
    }
    monkeypatch.setattr(youtube_cli, "build_youtube_service", lambda _c: service)
    config = _config(tmp_path)

    assert (
        youtube_cli.main(
            ["--config", str(config), "--initialize-without-sending"]
        )
        == 0
    )
    assert "completed=1" in capsys.readouterr().out
    assert gmail.messages_send.calls == []

    assert youtube_cli.main(["--config", str(config)]) == 0
    assert "skipped=1" in capsys.readouterr().out
    assert gmail.messages_send.calls == []
