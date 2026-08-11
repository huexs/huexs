<?php
/**
 * Almacén cifrado del token OAuth (una conexión por instalación).
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Auth;

use Huexs\GoogleReviews\Support\Crypto;

class TokenStore {

	private const OPTION = 'hgr_encrypted_token';

	public function __construct( private Crypto $crypto ) {}

	/**
	 * @param array $token {access_token, refresh_token, expires_at, scope, status}
	 * @throws \RuntimeException Si no hay criptografía disponible.
	 */
	public function save( array $token ): void {
		$token['status'] = $token['status'] ?? 'active';
		$blob            = $this->crypto->encrypt( (string) \wp_json_encode( $token ) );
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $blob, '', false );
		} else {
			update_option( self::OPTION, $blob, false );
		}
	}

	public function get(): ?array {
		$blob = get_option( self::OPTION, '' );
		if ( ! is_string( $blob ) || '' === $blob ) {
			return null;
		}
		$plain = $this->crypto->decrypt( $blob );
		if ( null === $plain ) {
			return null;
		}
		$data = json_decode( $plain, true );
		return is_array( $data ) ? $data : null;
	}

	public function isConnected(): bool {
		$token = $this->get();
		return null !== $token && ! empty( $token['refresh_token'] ) && 'active' === ( $token['status'] ?? 'active' );
	}

	public function isExpired(): bool {
		$token = $this->get();
		return null !== $token && 'expired' === ( $token['status'] ?? '' );
	}

	public function markExpired(): void {
		$token = $this->get();
		if ( null !== $token ) {
			$token['status'] = 'expired';
			$this->save( $token );
		}
	}

	public function clear(): void {
		delete_option( self::OPTION );
	}
}
