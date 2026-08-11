"""Ordre CLI de `gmail_school`."""

from __future__ import annotations

import sys
from typing import Sequence

from ..adapters.gmail import (
    GmailMailbox,
    GmailMailer,
    build_mailbox_service,
    build_mailer_service,
)
from ..adapters.pdf import PypdfTextExtractor
from ..adapters.summarizer import (
    ClaudeSummarizer,
    KeywordSummarizer,
    build_anthropic_client,
)
from ..cli_common import (
    build_parser,
    configure_logging,
    health_ok,
    load,
    open_state,
    report,
)
from ..config import Config
from .processor import AUTOMATION, GmailSchoolProcessor


def build_summarizer(config: Config):
    settings = config.summarizer
    if settings.type == "claude":
        return ClaudeSummarizer(
            build_anthropic_client(settings),
            model=settings.model,
            max_source_chars=settings.max_source_chars,
            max_tokens=settings.max_tokens,
        )
    return KeywordSummarizer()


def build_processor(config: Config) -> GmailSchoolProcessor:
    settings = config.gmail_school
    return GmailSchoolProcessor(
        mailbox=GmailMailbox(
            build_mailbox_service(config.google),
            max_attachment_bytes=settings.max_attachment_bytes,
        ),
        mailer=GmailMailer(
            build_mailer_service(config.google), sender=config.google.sender
        ),
        summarizer=build_summarizer(config),
        pdf_extractor=PypdfTextExtractor(),
        state=open_state(config),
        label=settings.label,
        summary_recipient=settings.summary_recipient,
        targets=settings.targets,
    )


def main(argv: Sequence[str] | None = None) -> int:
    args = build_parser(AUTOMATION, with_limit=True).parse_args(argv)
    configure_logging(args.verbose)
    config = load(args)
    settings = config.gmail_school
    settings.validate()
    config.summarizer.validate()

    if args.health_check:
        mailbox = GmailMailbox(build_mailbox_service(config.google))
        mailbox.list_messages(label=settings.label, limit=1)
        build_summarizer(config)
        open_state(config)
        return health_ok(
            AUTOMATION,
            f"label={settings.label} summarizer={config.summarizer.type} "
            f"targets={len(settings.targets)}",
        )

    processor = build_processor(config)
    result = processor.run(
        limit=args.limit or settings.limit,
        dry_run=args.dry_run,
    )
    return report(AUTOMATION, result)


if __name__ == "__main__":
    sys.exit(main())
