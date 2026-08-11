<?php
/**
 * Clave de licencia de Huexs y límites del plan (cacheados desde /v1/account).
 *
 * El gating real lo aplica el servidor; estos límites solo sirven para que la
 * interfaz sea coherente y no ofrezca lo que el plan no cubre.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Support;

class License {

	private const OPTION_KEY  = 'hgr_license_key';
	private const OPTION_PLAN = 'hgr_plan_cache';
	private const PLAN_TTL    = 12 * 3600;

	public const DEFAULT_LIMITS = array(
		'max_reviews'             => 5,
		'max_locations'           => 1,
		'layouts'                 => array( 'list', 'grid', 'carousel', 'badge' ),
		'branding_required'       => true,
		'min_sync_interval_hours' => 24,
	);

	public function __construct( private Crypto $crypto ) {}

	public function key(): string {
		if ( defined( 'HGR_LICENSE_KEY' ) && HGR_LICENSE_KEY ) {
			return (string) HGR_LICENSE_KEY;
		}
		$blob = get_option( self::OPTION_KEY, '' );
		if ( ! is_string( $blob ) || '' === $blob ) {
			return '';
		}
		return (string) ( $this->crypto->decrypt( $blob ) ?? '' );
	}

	public function has(): bool {
		return '' !== $this->key();
	}

	/** @throws \RuntimeException Si no hay criptografía disponible. */
	public function store( string $key ): void {
		$key = trim( $key );
		if ( '' === $key ) {
			$this->clear();
			return;
		}
		$blob = $this->crypto->encrypt( $key );
		if ( false === get_option( self::OPTION_KEY, false ) ) {
			add_option( self::OPTION_KEY, $blob, '', false );
		} else {
			update_option( self::OPTION_KEY, $blob, false );
		}
		$this->forgetPlan();
	}

	public function clear(): void {
		delete_option( self::OPTION_KEY );
		$this->forgetPlan();
	}

	public function maskedKey(): string {
		$key = $this->key();
		if ( strlen( $key ) <= 8 ) {
			return $key ? str_repeat( '•', strlen( $key ) ) : '';
		}
		return substr( $key, 0, 4 ) . str_repeat( '•', 8 ) . substr( $key, -4 );
	}

	// ---- Plan ----

	public function plan(): string {
		return (string) ( $this->planData()['plan'] ?? 'free' );
	}

	public function isPro(): bool {
		return 'pro' === $this->plan();
	}

	public function limits(): array {
		$limits = $this->planData()['limits'] ?? array();
		return is_array( $limits ) ? array_merge( self::DEFAULT_LIMITS, $limits ) : self::DEFAULT_LIMITS;
	}

	public function maxReviews(): int {
		return max( 1, (int) $this->limits()['max_reviews'] );
	}

	public function maxLocations(): int {
		return max( 1, (int) $this->limits()['max_locations'] );
	}

	public function allowedLayouts(): array {
		$layouts = $this->limits()['layouts'];
		return is_array( $layouts ) && $layouts ? array_values( array_map( 'strval', $layouts ) ) : self::DEFAULT_LIMITS['layouts'];
	}

	public function brandingRequired(): bool {
		return (bool) $this->limits()['branding_required'];
	}

	public function minSyncHours(): int {
		return max( 1, (int) $this->limits()['min_sync_interval_hours'] );
	}

	/** Cachea la respuesta de /v1/account. */
	public function cachePlan( array $account ): void {
		set_transient( self::OPTION_PLAN, $account, self::PLAN_TTL );
	}

	public function forgetPlan(): void {
		delete_transient( self::OPTION_PLAN );
	}

	public function planData(): array {
		$cached = get_transient( self::OPTION_PLAN );
		return is_array( $cached ) ? $cached : array(
			'plan'   => 'free',
			'limits' => self::DEFAULT_LIMITS,
		);
	}
}
