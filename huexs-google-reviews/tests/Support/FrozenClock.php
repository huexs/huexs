<?php

declare(strict_types=1);

namespace Huexs\GoogleReviews\Tests\Support;

use Huexs\GoogleReviews\Support\Clock;

final class FrozenClock extends Clock {

	private \DateTimeImmutable $now;

	public function __construct( string $datetime = '2026-08-11 10:00:00' ) {
		$this->now = new \DateTimeImmutable( $datetime, new \DateTimeZone( 'UTC' ) );
	}

	public function now(): \DateTimeImmutable {
		return $this->now;
	}

	public function advance( string $interval ): void {
		$this->now = $this->now->add( new \DateInterval( $interval ) );
	}
}
