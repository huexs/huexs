"""Consentiment OAuth d'un sol ús per a Gmail, Drive i YouTube.

Executa-ho una vegada a la màquina del propietari:

    python -m tools.authorize --config config.local.json

El token es desa a la ruta indicada per `google.token_path`, fora del
repositori i amb permisos restrictius. No imprimeix cap secret.
"""

from __future__ import annotations

import argparse
import sys
from typing import Sequence

from email_automations.adapters.google_auth import (
    ALL_SCOPES,
    authorize_interactively,
    validate_client_secrets,
)
from email_automations.cli_common import friendly_config_errors
from email_automations.config import DEFAULT_CONFIG_PATH, load_config


@friendly_config_errors
def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="python -m tools.authorize")
    parser.add_argument("--config", default=DEFAULT_CONFIG_PATH)
    parser.add_argument(
        "--scope",
        action="append",
        dest="scopes",
        help="Àmbit concret; per defecte es demanen tots els mínims necessaris",
    )
    parser.add_argument(
        "--check",
        action="store_true",
        help="Comprova el fitxer de client OAuth sense obrir cap navegador",
    )
    args = parser.parse_args(argv)

    config = load_config(args.config)
    if config.google.auth_mode != "oauth_user":
        print(
            "google.auth_mode no és 'oauth_user': amb compte de servei no cal "
            "consentiment interactiu.",
            file=sys.stderr,
        )
        return 2

    scopes = tuple(args.scopes) if args.scopes else ALL_SCOPES

    if args.check:
        from pathlib import Path

        client_id = validate_client_secrets(Path(config.google.client_secrets_path))
        print(f"client OAuth correcte (aplicació d'escriptori): {_mask(client_id)}")
        print("àmbits que es demanaran:")
        for scope in scopes:
            print(f"  - {scope}")
        return 0

    token_path = authorize_interactively(config.google, scopes)
    print(f"token desat a {token_path}")
    return 0


def _mask(client_id: str) -> str:
    return f"{client_id[:8]}…{client_id[-14:]}" if len(client_id) > 24 else "…"


if __name__ == "__main__":
    sys.exit(main())
