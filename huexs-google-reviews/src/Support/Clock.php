<?php
/**
 * Reloj inyectable (UTC) para poder congelar el tiempo en tests.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Support;

class Clock {

	public function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	/** Fecha-hora UTC en formato MySQL. */
	public function nowString(): string {
		return $this->now()->format( 'Y-m-d H:i:s' );
	}

	public function timestamp(): int {
		return $this->now()->getTimestamp();
	}
}
