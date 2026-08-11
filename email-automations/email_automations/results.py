from __future__ import annotations

from dataclasses import dataclass


@dataclass
class RunResult:
    discovered: int = 0
    completed: int = 0
    skipped: int = 0
    review: int = 0
    failed: int = 0

