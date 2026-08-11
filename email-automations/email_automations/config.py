from __future__ import annotations

import json
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Sequence

DEFAULT_CONFIG_PATH = "config.local.json"


class ConfigError(ValueError):
    """Configuració local invàlida o incompleta."""


@dataclass(frozen=True)
class GoogleConfig:
    auth_mode: str = "oauth_user"
    client_secrets_path: str = "credentials/client_secret.json"
    token_path: str = "credentials/token.json"
    service_account_path: str = "credentials/service-account.json"
    delegated_user: str = ""
    sender: str = "me"

    def validate(self) -> None:
        if self.auth_mode not in {"oauth_user", "service_account"}:
            raise ConfigError(
                "google.auth_mode ha de ser 'oauth_user' o 'service_account'"
            )
        if self.auth_mode == "service_account" and not self.delegated_user:
            raise ConfigError(
                "google.delegated_user és obligatori amb auth_mode=service_account"
            )


@dataclass(frozen=True)
class StorageConfig:
    type: str = "local"
    drive_folder_id: str = ""
    local_root: str = "data/files"

    def validate(self) -> None:
        if self.type not in {"drive", "local"}:
            raise ConfigError("storage.type ha de ser 'drive' o 'local'")
        if self.type == "drive" and not self.drive_folder_id:
            raise ConfigError("storage.drive_folder_id és obligatori amb type=drive")


@dataclass(frozen=True)
class SummarizerConfig:
    type: str = "keyword"
    model: str = "claude-opus-5"
    api_key_env: str = "ANTHROPIC_API_KEY"
    max_source_chars: int = 20000
    max_tokens: int = 1024

    def validate(self) -> None:
        if self.type not in {"keyword", "claude"}:
            raise ConfigError("summarizer.type ha de ser 'keyword' o 'claude'")


@dataclass(frozen=True)
class InvoicesConfig:
    label: str = ""
    review_recipient: str = ""
    providers: tuple[dict[str, Any], ...] = ()
    limit: int = 50
    max_attachment_bytes: int = 20 * 1024 * 1024

    def validate(self) -> None:
        _require(self.label, "gmail_invoices.label")
        _require(self.review_recipient, "gmail_invoices.review_recipient")


@dataclass(frozen=True)
class SchoolConfig:
    label: str = ""
    summary_recipient: str = ""
    targets: tuple[str, ...] = ()
    limit: int = 50
    max_attachment_bytes: int = 20 * 1024 * 1024

    def validate(self) -> None:
        _require(self.label, "gmail_school.label")
        _require(self.summary_recipient, "gmail_school.summary_recipient")


@dataclass(frozen=True)
class YouTubeConfig:
    recipient: str = ""
    lookback_hours: int = 48
    max_videos: int = 25
    max_channels: int = 50
    cache_path: str = "data/youtube_uploads.json"

    def validate(self) -> None:
        _require(self.recipient, "youtube_to_mail.recipient")


@dataclass(frozen=True)
class Config:
    database_path: str = "data/automations.sqlite3"
    google: GoogleConfig = field(default_factory=GoogleConfig)
    storage: StorageConfig = field(default_factory=StorageConfig)
    summarizer: SummarizerConfig = field(default_factory=SummarizerConfig)
    gmail_invoices: InvoicesConfig = field(default_factory=InvoicesConfig)
    gmail_school: SchoolConfig = field(default_factory=SchoolConfig)
    youtube_to_mail: YouTubeConfig = field(default_factory=YouTubeConfig)


def load_config(path: str | Path = DEFAULT_CONFIG_PATH) -> Config:
    config_path = Path(path)
    if not config_path.exists():
        raise ConfigError(
            f"No s'ha trobat {config_path}. Copia config.example.json i edita'l."
        )
    try:
        raw = json.loads(config_path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        raise ConfigError(f"{config_path} no és JSON vàlid: {exc}") from exc
    if not isinstance(raw, dict):
        raise ConfigError(f"{config_path} ha de contenir un objecte JSON")
    return from_dict(raw)


def from_dict(raw: dict[str, Any]) -> Config:
    return Config(
        database_path=raw.get("database_path", "data/automations.sqlite3"),
        google=GoogleConfig(**_section(raw, "google")),
        storage=StorageConfig(**_section(raw, "storage")),
        summarizer=SummarizerConfig(**_section(raw, "summarizer")),
        gmail_invoices=InvoicesConfig(
            **_section(raw, "gmail_invoices", tuples=("providers",))
        ),
        gmail_school=SchoolConfig(
            **_section(raw, "gmail_school", tuples=("targets",))
        ),
        youtube_to_mail=YouTubeConfig(**_section(raw, "youtube_to_mail")),
    )


def _section(
    raw: dict[str, Any],
    name: str,
    *,
    tuples: Sequence[str] = (),
) -> dict[str, Any]:
    value = raw.get(name, {})
    if not isinstance(value, dict):
        raise ConfigError(f"La secció '{name}' ha de ser un objecte JSON")
    section = dict(value)
    for key in tuples:
        if key in section:
            if not isinstance(section[key], list):
                raise ConfigError(f"'{name}.{key}' ha de ser una llista")
            section[key] = tuple(section[key])
    return section


def _require(value: str, name: str) -> None:
    if not value:
        raise ConfigError(f"Falta '{name}' a la configuració local")
