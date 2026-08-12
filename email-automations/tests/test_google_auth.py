from __future__ import annotations

import json
from pathlib import Path

import pytest

from email_automations.adapters.google_auth import validate_client_secrets
from email_automations.config import ConfigError


def _write(tmp_path: Path, payload: object) -> Path:
    path = tmp_path / "client_secret.json"
    path.write_text(json.dumps(payload), encoding="utf-8")
    return path


def test_accepts_desktop_client(tmp_path: Path) -> None:
    path = _write(
        tmp_path,
        {
            "installed": {
                "client_id": "123456789012-abcdefghijklmnop.apps.googleusercontent.com",
                "client_secret": "no-és-real",
                "redirect_uris": ["http://localhost"],
            }
        },
    )

    client_id = validate_client_secrets(path)

    assert client_id.endswith(".apps.googleusercontent.com")


def test_rejects_web_client_with_actionable_message(tmp_path: Path) -> None:
    path = _write(tmp_path, {"web": {"client_id": "x", "client_secret": "y"}})

    with pytest.raises(ConfigError, match="Aplicació d'escriptori"):
        validate_client_secrets(path)


def test_rejects_file_without_client_id(tmp_path: Path) -> None:
    path = _write(tmp_path, {"installed": {"redirect_uris": ["http://localhost"]}})

    with pytest.raises(ConfigError, match="client_id"):
        validate_client_secrets(path)


def test_rejects_invalid_json(tmp_path: Path) -> None:
    path = tmp_path / "client_secret.json"
    path.write_text("{", encoding="utf-8")

    with pytest.raises(ConfigError, match="JSON"):
        validate_client_secrets(path)
