"""Adaptadors `FileStore`.

Tots dos són idempotents pel nom: si el fitxer ja existeix, es retorna el
localitzador existent en comptes de crear-ne un duplicat. Això evita duplicats
quan un tall passa entre desar el fitxer i marcar l'element com a completat.
"""

from __future__ import annotations

import logging
from pathlib import Path
from typing import Any, Callable

from ..config import GoogleConfig
from .google_auth import DRIVE_FILE, build_service

logger = logging.getLogger(__name__)


class FileStoreError(RuntimeError):
    """El magatzem no ha pogut confirmar la creació del fitxer."""


class DriveFileStore:
    def __init__(
        self,
        service: Any,
        *,
        folder_id: str,
        media_factory: Callable[[bytes, str], Any] | None = None,
    ) -> None:
        self.service = service
        self.folder_id = folder_id
        self._media_factory = media_factory or _media_in_memory_upload

    def save(self, *, filename: str, data: bytes, content_type: str) -> str:
        existing = self._find(filename)
        if existing:
            logger.info("drive: %s ja existia, no es duplica", filename)
            return existing
        created = (
            self.service.files()
            .create(
                body={"name": filename, "parents": [self.folder_id]},
                media_body=self._media_factory(data, content_type),
                fields="id",
                supportsAllDrives=True,
            )
            .execute()
        )
        file_id = (created or {}).get("id")
        if not file_id:
            raise FileStoreError("Drive no ha retornat cap identificador de fitxer")
        logger.info("drive: fitxer creat %s", file_id)
        return _drive_url(file_id)

    def _find(self, filename: str) -> str | None:
        escaped = filename.replace("\\", "\\\\").replace("'", "\\'")
        response = (
            self.service.files()
            .list(
                q=(
                    f"name = '{escaped}' and '{self.folder_id}' in parents "
                    "and trashed = false"
                ),
                fields="files(id)",
                pageSize=1,
                supportsAllDrives=True,
                includeItemsFromAllDrives=True,
            )
            .execute()
        )
        files = (response or {}).get("files") or []
        if not files:
            return None
        return _drive_url(files[0]["id"])


class LocalFileStore:
    def __init__(self, root: str | Path) -> None:
        self.root = Path(root)
        self.root.mkdir(parents=True, exist_ok=True)

    def save(self, *, filename: str, data: bytes, content_type: str) -> str:
        del content_type
        target = self.root / Path(filename).name
        if target.exists():
            logger.info("local: %s ja existia, no es duplica", target.name)
            return target.resolve().as_uri()
        temporary = target.with_name(target.name + ".part")
        temporary.write_bytes(data)
        temporary.replace(target)
        logger.info("local: fitxer creat %s", target.name)
        return target.resolve().as_uri()


def build_drive_service(config: GoogleConfig) -> Any:
    return build_service("drive", "v3", config, [DRIVE_FILE])


def _media_in_memory_upload(data: bytes, content_type: str) -> Any:
    from googleapiclient.http import (  # type: ignore[import-not-found]
        MediaInMemoryUpload,
    )

    return MediaInMemoryUpload(data, mimetype=content_type)


def _drive_url(file_id: str) -> str:
    return f"https://drive.google.com/file/d/{file_id}/view"
