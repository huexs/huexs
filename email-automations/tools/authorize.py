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
)
from email_automations.config import DEFAULT_CONFIG_PATH, load_config


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="python -m tools.authorize")
    parser.add_argument("--config", default=DEFAULT_CONFIG_PATH)
    parser.add_argument(
        "--scope",
        action="append",
        dest="scopes",
        help="Àmbit concret; per defecte es demanen tots els mínims necessaris",
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
    token_path = authorize_interactively(config.google, scopes)
    print(f"token desat a {token_path}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
