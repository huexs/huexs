"""Ordre CLI de `gmail_invoices`."""

from __future__ import annotations

import sys
from typing import Sequence

from ..adapters.gmail import (
    GmailMailbox,
    GmailMailer,
    build_mailbox_service,
    build_mailer_service,
)
from ..adapters.storage import DriveFileStore, LocalFileStore, build_drive_service
from ..cli_common import (
    build_parser,
    configure_logging,
    friendly_config_errors,
    health_ok,
    load,
    open_state,
    report,
)
from ..config import Config
from .processor import AUTOMATION, GmailInvoicesProcessor, ProviderRule


def build_file_store(config: Config):
    if config.storage.type == "drive":
        return DriveFileStore(
            build_drive_service(config.google), folder_id=config.storage.drive_folder_id
        )
    return LocalFileStore(config.storage.local_root)


def build_processor(config: Config) -> GmailInvoicesProcessor:
    settings = config.gmail_invoices
    rules = [
        ProviderRule(
            slug=str(rule["slug"]),
            sender_contains=tuple(rule.get("sender_contains", ())),
            subject_contains=tuple(rule.get("subject_contains", ())),
        )
        for rule in settings.providers
    ]
    return GmailInvoicesProcessor(
        mailbox=GmailMailbox(
            build_mailbox_service(config.google),
            max_attachment_bytes=settings.max_attachment_bytes,
        ),
        mailer=GmailMailer(
            build_mailer_service(config.google), sender=config.google.sender
        ),
        file_store=build_file_store(config),
        state=open_state(config),
        label=settings.label,
        review_recipient=settings.review_recipient,
        provider_rules=rules,
    )


@friendly_config_errors
def main(argv: Sequence[str] | None = None) -> int:
    args = build_parser(AUTOMATION, with_limit=True).parse_args(argv)
    configure_logging(args.verbose)
    config = load(args)
    settings = config.gmail_invoices
    settings.validate()
    config.storage.validate()

    if args.health_check:
        mailbox = GmailMailbox(build_mailbox_service(config.google))
        mailbox.list_messages(label=settings.label, limit=1)
        open_state(config)
        return health_ok(
            AUTOMATION,
            f"label={settings.label} storage={config.storage.type} "
            f"providers={len(settings.providers)}",
        )

    processor = build_processor(config)
    result = processor.run(
        limit=args.limit or settings.limit,
        dry_run=args.dry_run,
    )
    return report(AUTOMATION, result)


if __name__ == "__main__":
    sys.exit(main())
