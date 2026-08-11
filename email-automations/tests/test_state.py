from __future__ import annotations

from email_automations.state import StateStore


def test_active_claim_blocks_concurrent_worker(tmp_path):
    state = StateStore(tmp_path / "state.sqlite3")

    assert state.claim("example", "item") is True
    assert state.claim("example", "item") is False


def test_failed_item_is_reclaimable(tmp_path):
    state = StateStore(tmp_path / "state.sqlite3")

    assert state.claim("example", "item") is True
    state.fail("example", "item", "temporary")
    assert state.claim("example", "item") is True


def test_completed_item_is_never_reclaimed(tmp_path):
    state = StateStore(tmp_path / "state.sqlite3")

    assert state.claim("example", "item") is True
    state.complete("example", "item")
    assert state.is_complete("example", "item") is True
    assert state.claim("example", "item") is False

