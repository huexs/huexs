<?php
/**
 * Clave propia de Google Places para el modo directo.
 *
 * Resolución, igual que las credenciales OAuth:
 *   1. Constante HGR_GOOGLE_PLACES_KEY en wp-config.php (recomendado: fuera de la
 *      base de datos y fuera de Git).
 *   2. Opción cifrada, para hostings donde no se puede editar wp-config.php.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Support;

class PlacesKey {

	private const OPTION = 'hgr_encrypted_places_key';

	public function __construct( private Crypto $crypto ) {}

	public function value(): string {
		if ( defined( 'HGR_GOOGLE_PLACES_KEY' ) && HGR_GOOGLE_PLACES_KEY ) {
			return (string) HGR_GOOGLE_PLACES_KEY;
		}
		$blob = get_option( self::OPTION, '' );
		if ( ! is_string( $blob ) || '' === $blob ) {
			return '';
		}
		return (string) ( $this->crypto->decrypt( $blob ) ?? '' );
	}

	public function has(): bool {
		return '' !== $this->value();
	}

	public function source(): string {
		if ( defined( 'HGR_GOOGLE_PLACES_KEY' ) && HGR_GOOGLE_PLACES_KEY ) {
			return 'constant';
		}
		return $this->has() ? 'option' : 'none';
	}

	/** @throws \RuntimeException Si no hay criptografía disponible. */
	public function store( string $key ): void {
		$key = trim( $key );
		if ( '' === $key ) {
			$this->clear();
			return;
		}
		$blob = $this->crypto->encrypt( $key );
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $blob, '', false );
		} else {
			update_option( self::OPTION, $blob, false );
		}
	}

	public function clear(): void {
		delete_option( self::OPTION );
	}

	public function masked(): string {
		$key = $this->value();
		if ( strlen( $key ) <= 10 ) {
			return $key ? str_repeat( '•', strlen( $key ) ) : '';
		}
		return substr( $key, 0, 6 ) . str_repeat( '•', 10 ) . substr( $key, -4 );
	}
}
