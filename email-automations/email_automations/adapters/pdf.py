"""Adaptador `PdfTextExtractor` basat en pypdf."""

from __future__ import annotations

import io
import logging

logger = logging.getLogger(__name__)


class PdfExtractionError(RuntimeError):
    """El PDF no s'ha pogut llegir."""


class PypdfTextExtractor:
    """Extreu text d'un PDF digital.

    Un PDF escanejat retorna text buit: cal un pas d'OCR separat, que no forma
    part d'aquest paquet.
    """

    def extract(self, data: bytes) -> str:
        from pypdf import PdfReader
        from pypdf.errors import PyPdfError

        try:
            reader = PdfReader(io.BytesIO(data))
            pages = [page.extract_text() or "" for page in reader.pages]
        except (PyPdfError, ValueError, OSError) as exc:
            raise PdfExtractionError(f"PDF il·legible: {exc}") from exc
        text = "\n".join(pages).strip()
        if not text:
            logger.info("pdf: sense text extraïble (probablement escanejat)")
        return text
