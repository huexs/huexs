<?php
/**
 * Estado anti-CSRF del flujo OAuth: aleatorio, ligado al usuario y con caducidad de 10 minutos.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Auth;

class OAuthStateStore {

	private const TTL = 600;

	public function create( int $userId ): string {
		$state = bin2hex( random_bytes( 32 ) );
		set_transient( 'hgr_oauth_state_' . hash( 'sha256', $state ), $userId, self::TTL );
		return $state;
	}

	/** Valida y consume el state (un solo uso). */
	public function validateAndConsume( string $state, int $userId ): bool {
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $state ) ) {
			return false;
		}
		$key    = 'hgr_oauth_state_' . hash( 'sha256', $state );
		$stored = get_transient( $key );
		delete_transient( $key );
		return false !== $stored && (int) $stored === $userId;
	}
}
