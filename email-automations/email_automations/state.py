from __future__ import annotations

import json
import sqlite3
from contextlib import contextmanager
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Iterator


class StateStore:
    """SQLite-backed claims with leases, retries and durable completion."""

    def __init__(self, path: str | Path) -> None:
        self.path = Path(path)
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self._initialize()

    @contextmanager
    def _connect(self) -> Iterator[sqlite3.Connection]:
        connection = sqlite3.connect(self.path)
        connection.row_factory = sqlite3.Row
        connection.execute("PRAGMA journal_mode=WAL")
        connection.execute("PRAGMA foreign_keys=ON")
        try:
            yield connection
            connection.commit()
        finally:
            connection.close()

    def _initialize(self) -> None:
        with self._connect() as connection:
            connection.executescript(
                """
                CREATE TABLE IF NOT EXISTS items (
                    automation TEXT NOT NULL,
                    item_id TEXT NOT NULL,
                    status TEXT NOT NULL,
                    attempts INTEGER NOT NULL DEFAULT 0,
                    lease_until TEXT,
                    last_error TEXT,
                    metadata_json TEXT NOT NULL DEFAULT '{}',
                    updated_at TEXT NOT NULL,
                    PRIMARY KEY (automation, item_id)
                );

                CREATE INDEX IF NOT EXISTS items_status_idx
                    ON items (automation, status, lease_until);
                """
            )

    def claim(
        self,
        automation: str,
        item_id: str,
        *,
        lease_seconds: int = 300,
        metadata: dict[str, Any] | None = None,
    ) -> bool:
        now = datetime.now(timezone.utc)
        lease_until = now + timedelta(seconds=lease_seconds)
        payload = json.dumps(metadata or {}, sort_keys=True)
        with self._connect() as connection:
            connection.execute("BEGIN IMMEDIATE")
            row = connection.execute(
                """
                SELECT status, lease_until
                FROM items
                WHERE automation = ? AND item_id = ?
                """,
                (automation, item_id),
            ).fetchone()
            if row is not None:
                if row["status"] == "complete":
                    return False
                if row["status"] == "processing" and row["lease_until"]:
                    existing_lease = datetime.fromisoformat(row["lease_until"])
                    if existing_lease > now:
                        return False

            connection.execute(
                """
                INSERT INTO items (
                    automation, item_id, status, attempts, lease_until,
                    last_error, metadata_json, updated_at
                ) VALUES (?, ?, 'processing', 1, ?, NULL, ?, ?)
                ON CONFLICT(automation, item_id) DO UPDATE SET
                    status = 'processing',
                    attempts = attempts + 1,
                    lease_until = excluded.lease_until,
                    last_error = NULL,
                    metadata_json = excluded.metadata_json,
                    updated_at = excluded.updated_at
                """,
                (
                    automation,
                    item_id,
                    lease_until.isoformat(),
                    payload,
                    now.isoformat(),
                ),
            )
        return True

    def complete(
        self,
        automation: str,
        item_id: str,
        *,
        metadata: dict[str, Any] | None = None,
    ) -> None:
        self._set_status(
            automation,
            item_id,
            status="complete",
            error=None,
            metadata=metadata,
        )

    def fail(self, automation: str, item_id: str, error: Exception | str) -> None:
        self._set_status(
            automation,
            item_id,
            status="failed",
            error=str(error)[:1000],
            metadata=None,
        )

    def is_complete(self, automation: str, item_id: str) -> bool:
        with self._connect() as connection:
            row = connection.execute(
                """
                SELECT 1 FROM items
                WHERE automation = ? AND item_id = ? AND status = 'complete'
                """,
                (automation, item_id),
            ).fetchone()
        return row is not None

    def _set_status(
        self,
        automation: str,
        item_id: str,
        *,
        status: str,
        error: str | None,
        metadata: dict[str, Any] | None,
    ) -> None:
        now = datetime.now(timezone.utc).isoformat()
        with self._connect() as connection:
            if metadata is None:
                connection.execute(
                    """
                    UPDATE items
                    SET status = ?, lease_until = NULL, last_error = ?,
                        updated_at = ?
                    WHERE automation = ? AND item_id = ?
                    """,
                    (status, error, now, automation, item_id),
                )
            else:
                connection.execute(
                    """
                    UPDATE items
                    SET status = ?, lease_until = NULL, last_error = ?,
                        metadata_json = ?, updated_at = ?
                    WHERE automation = ? AND item_id = ?
                    """,
                    (
                        status,
                        error,
                        json.dumps(metadata, sort_keys=True),
                        now,
                        automation,
                        item_id,
                    ),
                )

