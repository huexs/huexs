<?php
/**
 * Resolución de credenciales OAuth: constantes de wp-config.php primero, opción cifrada después.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Support;

class Credentials {

	private const OPTION = 'hgr_encrypted_credentials';

	public function __construct( private Crypto $crypto ) {}

	public function source(): string {
		if ( defined( 'HGR_GOOGLE_CLIENT_ID' ) && defined( 'HGR_GOOGLE_CLIENT_SECRET' ) && HGR_GOOGLE_CLIENT_ID && HGR_GOOGLE_CLIENT_SECRET ) {
			return 'constants';
		}
		return $this->stored() ? 'options' : 'none';
	}

	public function has(): bool {
		return 'none' !== $this->source();
	}

	public function clientId(): string {
		if ( defined( 'HGR_GOOGLE_CLIENT_ID' ) && HGR_GOOGLE_CLIENT_ID ) {
			return (string) HGR_GOOGLE_CLIENT_ID;
		}
		return $this->stored()['client_id'] ?? '';
	}

	public function clientSecret(): string {
		if ( defined( 'HGR_GOOGLE_CLIENT_SECRET' ) && HGR_GOOGLE_CLIENT_SECRET ) {
			return (string) HGR_GOOGLE_CLIENT_SECRET;
		}
		return $this->stored()['client_secret'] ?? '';
	}

	/**
	 * Guarda credenciales cifradas (solo cuando no hay constantes).
	 *
	 * @throws \RuntimeException Si no hay criptografía disponible.
	 */
	public function store( string $clientId, string $clientSecret ): void {
		$blob = $this->crypto->encrypt(
			(string) \wp_json_encode(
				array(
					'client_id'     => $clientId,
					'client_secret' => $clientSecret,
				)
			)
		);
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $blob, '', false );
		} else {
			update_option( self::OPTION, $blob, false );
		}
	}

	public function clearStored(): void {
		delete_option( self::OPTION );
	}

	/** ID de cliente parcialmente enmascarado para pantallas de administración. */
	public function maskedClientId(): string {
		$id = $this->clientId();
		if ( strlen( $id ) <= 12 ) {
			return $id ? substr( $id, 0, 4 ) . '…' : '';
		}
		return substr( $id, 0, 8 ) . '…' . substr( $id, -8 );
	}

	private function stored(): ?array {
		$blob = get_option( self::OPTION, '' );
		if ( ! is_string( $blob ) || '' === $blob ) {
			return null;
		}
		$plain = $this->crypto->decrypt( $blob );
		if ( null === $plain ) {
			return null;
		}
		$data = json_decode( $plain, true );
		return is_array( $data ) && ! empty( $data['client_id'] ) ? $data : null;
	}
}
