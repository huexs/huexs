from __future__ import annotations

import json
from pathlib import Path

import pytest

from email_automations.config import ConfigError, load_config


def _write(tmp_path: Path, payload: dict) -> Path:
    path = tmp_path / "config.local.json"
    path.write_text(json.dumps(payload), encoding="utf-8")
    return path


def test_loads_example_shape(tmp_path: Path) -> None:
    example_path = Path(__file__).resolve().parent.parent / "config.example.json"
    example = json.loads(example_path.read_text(encoding="utf-8"))
    config = load_config(_write(tmp_path, example))

    assert config.database_path == "data/automations.sqlite3"
    assert config.gmail_invoices.label == "Automation/Invoices"
    assert config.gmail_school.targets == ("Group A", "General announcements")
    assert config.youtube_to_mail.lookback_hours == 48
    config.gmail_invoices.validate()
    config.gmail_school.validate()
    config.youtube_to_mail.validate()
    config.google.validate()
    config.storage.validate()
    config.summarizer.validate()


def test_missing_file_is_explicit(tmp_path: Path) -> None:
    with pytest.raises(ConfigError, match="No s'ha trobat"):
        load_config(tmp_path / "absent.json")


def test_invalid_json_is_explicit(tmp_path: Path) -> None:
    path = tmp_path / "config.local.json"
    path.write_text("{", encoding="utf-8")

    with pytest.raises(ConfigError, match="JSON"):
        load_config(path)


def test_missing_required_field_is_reported(tmp_path: Path) -> None:
    config = load_config(_write(tmp_path, {"gmail_school": {"label": "X"}}))

    with pytest.raises(ConfigError, match="summary_recipient"):
        config.gmail_school.validate()


def test_service_account_requires_delegated_user(tmp_path: Path) -> None:
    config = load_config(
        _write(tmp_path, {"google": {"auth_mode": "service_account"}})
    )

    with pytest.raises(ConfigError, match="delegated_user"):
        config.google.validate()


def test_drive_storage_requires_folder(tmp_path: Path) -> None:
    config = load_config(_write(tmp_path, {"storage": {"type": "drive"}}))

    with pytest.raises(ConfigError, match="drive_folder_id"):
        config.storage.validate()


def test_unknown_summarizer_type_is_rejected(tmp_path: Path) -> None:
    config = load_config(_write(tmp_path, {"summarizer": {"type": "màgia"}}))

    with pytest.raises(ConfigError, match="summarizer.type"):
        config.summarizer.validate()
