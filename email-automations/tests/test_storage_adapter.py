from __future__ import annotations

from pathlib import Path

import pytest

from email_automations.adapters.storage import (
    DriveFileStore,
    FileStoreError,
    LocalFileStore,
)
from tests.fakes import FakeDriveService


def _store(service: FakeDriveService) -> DriveFileStore:
    return DriveFileStore(
        service,
        folder_id="folder-1",
        media_factory=lambda data, content_type: {
            "data": data,
            "type": content_type,
        },
    )


def test_drive_creates_file_when_missing() -> None:
    service = FakeDriveService()
    service.files_list.responses.append({"files": []})
    service.files_create.responses.append({"id": "file-1"})

    locator = _store(service).save(
        filename="2030-01-10-provider.pdf",
        data=b"%PDF",
        content_type="application/pdf",
    )

    assert locator == "https://drive.google.com/file/d/file-1/view"
    assert service.files_create.calls[0]["body"]["parents"] == ["folder-1"]


def test_drive_is_idempotent_by_filename() -> None:
    service = FakeDriveService()
    service.files_list.responses.append({"files": [{"id": "existing"}]})

    locator = _store(service).save(
        filename="2030-01-10-provider.pdf",
        data=b"%PDF",
        content_type="application/pdf",
    )

    assert locator == "https://drive.google.com/file/d/existing/view"
    assert service.files_create.calls == []


def test_drive_escapes_quotes_in_query() -> None:
    service = FakeDriveService()
    service.files_list.responses.append({"files": []})
    service.files_create.responses.append({"id": "file-2"})

    _store(service).save(
        filename="o'brien.pdf", data=b"%PDF", content_type="application/pdf"
    )

    assert "o\\'brien.pdf" in service.files_list.calls[0]["q"]


def test_drive_without_id_raises() -> None:
    service = FakeDriveService()
    service.files_list.responses.append({"files": []})
    service.files_create.responses.append({})

    with pytest.raises(FileStoreError):
        _store(service).save(
            filename="x.pdf", data=b"%PDF", content_type="application/pdf"
        )


def test_drive_remote_error_propagates() -> None:
    service = FakeDriveService()
    service.files_list.responses.append(RuntimeError("500"))

    with pytest.raises(RuntimeError, match="500"):
        _store(service).save(
            filename="x.pdf", data=b"%PDF", content_type="application/pdf"
        )


def test_local_store_is_idempotent_and_ignores_directories(tmp_path: Path) -> None:
    store = LocalFileStore(tmp_path / "files")

    first = store.save(
        filename="../escape.pdf", data=b"one", content_type="application/pdf"
    )
    second = store.save(
        filename="escape.pdf", data=b"two", content_type="application/pdf"
    )

    assert first == second
    target = tmp_path / "files" / "escape.pdf"
    assert target.read_bytes() == b"one"
    assert list((tmp_path / "files").iterdir()) == [target]
