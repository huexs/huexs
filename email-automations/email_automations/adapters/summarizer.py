"""Adaptadors `Summarizer`.

`ClaudeSummarizer` fa una sola crida a l'API de Claude i retorna un resum curt,
o `None` quan el missatge no afecta cap dels objectius configurats. El client
s'injecta perquè els tests de contracte no necessitin ni clau ni xarxa.
"""

from __future__ import annotations

import json
import logging
import os
from collections.abc import Sequence
from typing import Any

from ..config import ConfigError, SummarizerConfig

logger = logging.getLogger(__name__)

SYSTEM_PROMPT = (
    "Ets un assistent que resumeix comunicacions escolars per a una família. "
    "Decideix si el missatge afecta algun dels objectius indicats (curs, grup, "
    "tipus d'avís). Si no l'afecta, marca'l com a irrellevant. Si l'afecta, "
    "escriu un resum breu en la llengua del missatge original amb dates, "
    "terminis, material necessari i qualsevol acció que la família hagi de fer."
)

_RESPONSE_SCHEMA: dict[str, Any] = {
    "type": "object",
    "properties": {
        "relevant": {
            "type": "boolean",
            "description": "Cert només si el missatge afecta algun objectiu.",
        },
        "summary": {
            "type": "string",
            "description": "Resum breu; cadena buida si no és rellevant.",
        },
    },
    "required": ["relevant", "summary"],
    "additionalProperties": False,
}


class SummarizerError(RuntimeError):
    """El resumidor no ha pogut produir un resultat utilitzable."""


class KeywordSummarizer:
    """Resumidor local sense dependències externes.

    Serveix per a proves en sec i per a instal·lacions que no vulguin enviar
    contingut a un servei remot.
    """

    def __init__(self, *, max_chars: int = 400) -> None:
        self.max_chars = max_chars

    def summarize(
        self, *, source_text: str, targets: Sequence[str]
    ) -> str | None:
        lowered = source_text.lower()
        matching = [target for target in targets if target.lower() in lowered]
        if not matching:
            return None
        excerpt = " ".join(source_text.split())[: self.max_chars]
        return f"Rellevant per a {', '.join(matching)}: {excerpt}"


class ClaudeSummarizer:
    def __init__(
        self,
        client: Any,
        *,
        model: str = "claude-opus-5",
        max_source_chars: int = 20000,
        max_tokens: int = 1024,
    ) -> None:
        self.client = client
        self.model = model
        self.max_source_chars = max_source_chars
        self.max_tokens = max_tokens

    def summarize(
        self, *, source_text: str, targets: Sequence[str]
    ) -> str | None:
        payload = self._build_prompt(source_text, targets)
        response = self.client.messages.create(
            model=self.model,
            max_tokens=self.max_tokens,
            system=SYSTEM_PROMPT,
            output_config={
                "effort": "low",
                "format": {"type": "json_schema", "schema": _RESPONSE_SCHEMA},
            },
            messages=[{"role": "user", "content": payload}],
        )
        if getattr(response, "stop_reason", None) == "refusal":
            raise SummarizerError("Claude ha declinat resumir el missatge")
        parsed = _parse(response)
        if not parsed.get("relevant"):
            return None
        summary = str(parsed.get("summary") or "").strip()
        if not summary:
            return None
        logger.info("summarizer: resum generat (%d caràcters)", len(summary))
        return summary

    def _build_prompt(self, source_text: str, targets: Sequence[str]) -> str:
        trimmed = source_text[: self.max_source_chars]
        goals = "\n".join(f"- {target}" for target in targets) or "- (cap)"
        return (
            "Objectius rellevants per a aquesta família:\n"
            f"{goals}\n\n"
            "Missatge escolar:\n"
            "<missatge>\n"
            f"{trimmed}\n"
            "</missatge>"
        )


def build_anthropic_client(config: SummarizerConfig) -> Any:
    import anthropic  # type: ignore[import-not-found]

    api_key = os.environ.get(config.api_key_env)
    if not api_key:
        raise ConfigError(
            f"La variable d'entorn {config.api_key_env} no està definida. "
            "Els secrets han de viure fora del repositori."
        )
    return anthropic.Anthropic(api_key=api_key)


def _parse(response: Any) -> dict[str, Any]:
    blocks = getattr(response, "content", None) or []
    for block in blocks:
        if getattr(block, "type", None) != "text":
            continue
        try:
            parsed = json.loads(block.text)
        except json.JSONDecodeError as exc:
            raise SummarizerError("Claude ha retornat un JSON invàlid") from exc
        if not isinstance(parsed, dict):
            raise SummarizerError("Claude ha retornat un JSON amb forma inesperada")
        return parsed
    raise SummarizerError("Claude no ha retornat cap bloc de text")
