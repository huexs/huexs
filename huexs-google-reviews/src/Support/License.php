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

	/**
	 * Sin restricciones. Se aplica cuando el sitio no depende del servicio central
	 * (modo directo o modo avanzado): no hay nada que limitar porque no hay plan.
	 */
	public const UNLOCKED_LIMITS = array(
		'max_reviews'             => 200,
		'max_locations'           => 50,
		'layouts'                 => array( 'list', 'grid', 'carousel', 'badge', 'floating', 'sidebar' ),
		'branding_required'       => false,
		'min_sync_interval_hours' => 6,
	);

	public function __construct( private Crypto $crypto ) {}

	/**
	 * ¿Este sitio funciona sin licencia?
	 *
	 * Ocurre con la clave de Places propia (modo directo) o con OAuth propio
	 * (modo avanzado). En ambos casos el sitio no consume el servicio central.
	 */
	public function isUnlocked(): bool {
		if ( ( new PlacesKey( $this->crypto ) )->has() ) {
			return true;
		}
		return \Huexs\GoogleReviews\Plugin::settings()['advanced_mode'] && $this->tokenConnected();
	}

	/** Se requiere licencia solo si el sitio depende de la API central. */
	public function isRequired(): bool {
		return ! $this->isUnlocked();
	}

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
		if ( $this->isUnlocked() ) {
			return 'full';
		}
		return (string) ( $this->planData()['plan'] ?? 'free' );
	}

	public function isPro(): bool {
		return in_array( $this->plan(), array( 'pro', 'full' ), true );
	}

	public function limits(): array {
		if ( $this->isUnlocked() ) {
			return self::UNLOCKED_LIMITS;
		}
		$limits = $this->planData()['limits'] ?? array();
		return is_array( $limits ) ? array_merge( self::DEFAULT_LIMITS, $limits ) : self::DEFAULT_LIMITS;
	}

	/** Comprueba el token OAuth sin acoplar License al TokenStore. */
	private function tokenConnected(): bool {
		$store = new \Huexs\GoogleReviews\Auth\TokenStore( $this->crypto );
		return $store->isConnected();
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
