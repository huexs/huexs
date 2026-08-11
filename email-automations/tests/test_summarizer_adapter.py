from __future__ import annotations

import json
from dataclasses import dataclass
from typing import Any

import pytest

from email_automations.adapters.summarizer import (
    ClaudeSummarizer,
    KeywordSummarizer,
    SummarizerError,
)


@dataclass
class _Block:
    text: str
    type: str = "text"


@dataclass
class _Response:
    content: list[_Block]
    stop_reason: str = "end_turn"


class _FakeMessages:
    def __init__(self, result: Any) -> None:
        self.result = result
        self.calls: list[dict[str, Any]] = []

    def create(self, **kwargs: Any) -> Any:
        self.calls.append(kwargs)
        if isinstance(self.result, Exception):
            raise self.result
        return self.result


class _FakeClient:
    def __init__(self, result: Any) -> None:
        self.messages = _FakeMessages(result)


def _json_response(payload: dict[str, Any], **kwargs: Any) -> _Response:
    return _Response(content=[_Block(json.dumps(payload))], **kwargs)


def test_returns_summary_when_relevant() -> None:
    client = _FakeClient(
        _json_response({"relevant": True, "summary": "Excursió dimarts."})
    )

    summary = ClaudeSummarizer(client).summarize(
        source_text="Sortida del grup A", targets=["Grup A"]
    )

    assert summary == "Excursió dimarts."
    call = client.messages.calls[0]
    assert call["model"] == "claude-opus-5"
    assert call["output_config"]["format"]["type"] == "json_schema"
    assert "Grup A" in call["messages"][0]["content"]


def test_returns_none_when_irrelevant() -> None:
    client = _FakeClient(_json_response({"relevant": False, "summary": ""}))

    assert (
        ClaudeSummarizer(client).summarize(source_text="Res", targets=["Grup A"])
        is None
    )


def test_relevant_with_empty_summary_is_none() -> None:
    client = _FakeClient(_json_response({"relevant": True, "summary": "   "}))

    assert (
        ClaudeSummarizer(client).summarize(source_text="Res", targets=["Grup A"])
        is None
    )


def test_source_text_is_truncated() -> None:
    client = _FakeClient(_json_response({"relevant": False, "summary": ""}))

    ClaudeSummarizer(client, max_source_chars=10).summarize(
        source_text="x" * 500, targets=["Grup A"]
    )

    assert "x" * 11 not in client.messages.calls[0]["messages"][0]["content"]


def test_refusal_raises() -> None:
    client = _FakeClient(
        _json_response({"relevant": True, "summary": "x"}, stop_reason="refusal")
    )

    with pytest.raises(SummarizerError):
        ClaudeSummarizer(client).summarize(source_text="Res", targets=["Grup A"])


def test_invalid_json_raises() -> None:
    client = _FakeClient(_Response(content=[_Block("no és json")]))

    with pytest.raises(SummarizerError):
        ClaudeSummarizer(client).summarize(source_text="Res", targets=["Grup A"])


def test_api_error_is_not_a_silent_success() -> None:
    client = _FakeClient(RuntimeError("429"))

    with pytest.raises(RuntimeError, match="429"):
        ClaudeSummarizer(client).summarize(source_text="Res", targets=["Grup A"])


def test_keyword_summarizer_matches_targets() -> None:
    summarizer = KeywordSummarizer()

    assert summarizer.summarize(source_text="Avís del grup a", targets=["Grup A"])
    assert summarizer.summarize(source_text="Avís general", targets=["Grup A"]) is None
