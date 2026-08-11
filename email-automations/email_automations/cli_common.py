"""Peces compartides per les tres ordres CLI.

Les ordres només mostren comptadors de `RunResult`: mai contingut de correus,
adjunts ni credencials.
"""

from __future__ import annotations

import argparse
import logging
from pathlib import Path

from .config import DEFAULT_CONFIG_PATH, Config, load_config
from .results import RunResult
from .state import StateStore


def build_parser(automation: str, *, with_limit: bool = False) -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog=f"python -m email_automations.{automation}.cli")
    parser.add_argument(
        "--config",
        default=DEFAULT_CONFIG_PATH,
        help="Ruta de la configuració local (per defecte: %(default)s)",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Enumera la feina pendent sense cap efecte extern",
    )
    parser.add_argument(
        "--health-check",
        action="store_true",
        help="Comprova configuració, base de dades i accés als serveis",
    )
    parser.add_argument(
        "--verbose",
        action="store_true",
        help="Registre detallat (sense contingut de missatges)",
    )
    if with_limit:
        parser.add_argument(
            "--limit",
            type=int,
            default=None,
            help="Nombre màxim d'elements a processar en aquesta execució",
        )
    return parser


def configure_logging(verbose: bool) -> None:
    logging.basicConfig(
        level=logging.DEBUG if verbose else logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
    )


def open_state(config: Config) -> StateStore:
    return StateStore(Path(config.database_path))


def report(automation: str, result: RunResult) -> int:
    print(
        f"{automation} discovered={result.discovered} completed={result.completed} "
        f"skipped={result.skipped} review={result.review} failed={result.failed}"
    )
    return 1 if result.failed else 0


def load(args: argparse.Namespace) -> Config:
    return load_config(args.config)


def health_ok(automation: str, details: str) -> int:
    print(f"{automation} health=ok {details}")
    return 0
