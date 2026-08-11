from __future__ import annotations

import io

import pytest

from email_automations.adapters.pdf import PdfExtractionError, PypdfTextExtractor


def _blank_pdf() -> bytes:
    from pypdf import PdfWriter

    writer = PdfWriter()
    writer.add_blank_page(width=200, height=200)
    buffer = io.BytesIO()
    writer.write(buffer)
    return buffer.getvalue()


def test_scanned_or_empty_pdf_returns_empty_text() -> None:
    assert PypdfTextExtractor().extract(_blank_pdf()) == ""


def test_corrupt_pdf_raises_explicitly() -> None:
    with pytest.raises(PdfExtractionError):
        PypdfTextExtractor().extract(b"no soc un pdf")
