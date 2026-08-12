"""Ordre CLI de `youtube_to_mail`."""

from __future__ import annotations

import sys
from typing import Sequence

from ..adapters.gmail import GmailMailer, build_mailer_service
from ..adapters.youtube import (
    UploadsPlaylistCache,
    YouTubeApiSource,
    build_youtube_service,
)
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
from .processor import AUTOMATION, YouTubeToMailProcessor


def build_source(config: Config) -> YouTubeApiSource:
    settings = config.youtube_to_mail
    return YouTubeApiSource(
        build_youtube_service(config.google),
        cache=UploadsPlaylistCache(settings.cache_path),
        max_channels=settings.max_channels,
    )


def build_processor(config: Config) -> YouTubeToMailProcessor:
    settings = config.youtube_to_mail
    return YouTubeToMailProcessor(
        source=build_source(config),
        mailer=GmailMailer(
            build_mailer_service(config.google), sender=config.google.sender
        ),
        state=open_state(config),
        recipient=settings.recipient,
        lookback_hours=settings.lookback_hours,
        max_videos=settings.max_videos,
    )


@friendly_config_errors
def main(argv: Sequence[str] | None = None) -> int:
    parser = build_parser(AUTOMATION)
    parser.add_argument(
        "--initialize-without-sending",
        action="store_true",
        help="Registra els vídeos actuals sense enviar cap correu",
    )
    args = parser.parse_args(argv)
    configure_logging(args.verbose)
    config = load(args)
    settings = config.youtube_to_mail
    settings.validate()

    if args.health_check:
        source = build_source(config)
        source.list_recent_videos(lookback_hours=settings.lookback_hours, limit=1)
        open_state(config)
        return health_ok(
            AUTOMATION,
            f"lookback_hours={settings.lookback_hours} "
            f"max_videos={settings.max_videos}",
        )

    processor = build_processor(config)
    result = processor.run(
        dry_run=args.dry_run,
        initialize_without_sending=args.initialize_without_sending,
    )
    return report(AUTOMATION, result)


if __name__ == "__main__":
    sys.exit(main())
