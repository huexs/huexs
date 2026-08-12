"""Credencials de Google amb permisos mínims.

Cap secret viu dins del repositori: només rutes locals llegides de la
configuració. Les biblioteques de Google s'importen de manera mandrosa perquè
els tests de contracte no necessitin instal·lar-les.
"""

from __future__ import annotations

import json
import os
from pathlib import Path
from typing import Any, Sequence

from ..config import ConfigError, GoogleConfig

GMAIL_READONLY = "https://www.googleapis.com/auth/gmail.readonly"
GMAIL_SEND = "https://www.googleapis.com/auth/gmail.send"
DRIVE_FILE = "https://www.googleapis.com/auth/drive.file"
YOUTUBE_READONLY = "https://www.googleapis.com/auth/youtube.readonly"

ALL_SCOPES: tuple[str, ...] = (
    GMAIL_READONLY,
    GMAIL_SEND,
    DRIVE_FILE,
    YOUTUBE_READONLY,
)


def build_credentials(config: GoogleConfig, scopes: Sequence[str]) -> Any:
    """Retorna credencials de Google per als àmbits demanats."""
    config.validate()
    if config.auth_mode == "service_account":
        return _service_account_credentials(config, scopes)
    return _oauth_user_credentials(config, scopes)


def build_service(
    api: str,
    version: str,
    config: GoogleConfig,
    scopes: Sequence[str],
) -> Any:
    from googleapiclient.discovery import build  # type: ignore[import-not-found]

    return build(
        api,
        version,
        credentials=build_credentials(config, scopes),
        cache_discovery=False,
    )


def authorize_interactively(config: GoogleConfig, scopes: Sequence[str]) -> Path:
    """Executa el consentiment OAuth i desa el token fora del repositori."""
    from google_auth_oauthlib.flow import (  # type: ignore[import-not-found]
        InstalledAppFlow,
    )

    secrets_path = _existing_path(
        config.client_secrets_path, "google.client_secrets_path"
    )
    validate_client_secrets(secrets_path)
    flow = InstalledAppFlow.from_client_secrets_file(str(secrets_path), list(scopes))
    credentials = flow.run_local_server(port=0)
    return _store_token(config, credentials)


def validate_client_secrets(path: Path) -> str:
    """Comprova que el fitxer és un client OAuth d'aplicació d'escriptori.

    Retorna el client_id (no és secret) perquè es pugui mostrar en un missatge
    de diagnòstic. Mai llegeix ni retorna el client_secret.
    """
    try:
        content = json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        raise ConfigError(
            f"{path} no és JSON vàlid. Torna a descarregar-lo de Google Cloud."
        ) from exc
    if not isinstance(content, dict):
        raise ConfigError(f"{path} no té la forma d'un client OAuth de Google")
    if "web" in content:
        raise ConfigError(
            f"{path} és un client OAuth de tipus 'Aplicació web'. Aquest flux "
            "necessita un client de tipus 'Aplicació d'escriptori': crea'n un de "
            "nou a Google Cloud i substitueix el fitxer."
        )
    section = content.get("installed")
    if not isinstance(section, dict) or not section.get("client_id"):
        raise ConfigError(
            f"{path} no conté una secció 'installed' amb client_id. Descarrega el "
            "JSON del client OAuth d'aplicació d'escriptori des de Google Cloud."
        )
    return str(section["client_id"])


def _oauth_user_credentials(config: GoogleConfig, scopes: Sequence[str]) -> Any:
    from google.auth.transport.requests import (  # type: ignore[import-not-found]
        Request,
    )
    from google.oauth2.credentials import (  # type: ignore[import-not-found]
        Credentials,
    )

    token_path = _existing_path(
        config.token_path,
        "google.token_path",
        hint="Executa 'python -m tools.authorize' per crear-lo.",
    )
    credentials = Credentials.from_authorized_user_file(str(token_path), list(scopes))
    if not credentials.valid:
        if not (credentials.expired and credentials.refresh_token):
            raise ConfigError(
                "El token de Google no és vàlid i no es pot refrescar. "
                "Torna a executar 'python -m tools.authorize'."
            )
        credentials.refresh(Request())
        _store_token(config, credentials)
    return credentials


def _service_account_credentials(config: GoogleConfig, scopes: Sequence[str]) -> Any:
    from google.oauth2 import service_account  # type: ignore[import-not-found]

    key_path = _existing_path(
        config.service_account_path, "google.service_account_path"
    )
    credentials = service_account.Credentials.from_service_account_file(
        str(key_path), scopes=list(scopes)
    )
    return credentials.with_subject(config.delegated_user)


def _store_token(config: GoogleConfig, credentials: Any) -> Path:
    token_path = Path(config.token_path)
    token_path.parent.mkdir(parents=True, exist_ok=True)
    token_path.write_text(credentials.to_json(), encoding="utf-8")
    os.chmod(token_path, 0o600)
    return token_path


def _existing_path(value: str, name: str, *, hint: str = "") -> Path:
    if not value:
        raise ConfigError(f"Falta '{name}' a la configuració local")
    path = Path(value)
    if not path.exists():
        suffix = f" {hint}" if hint else ""
        raise ConfigError(f"No s'ha trobat el fitxer de '{name}': {path}.{suffix}")
    return path
