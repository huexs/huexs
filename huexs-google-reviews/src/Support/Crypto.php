<?php
/**
 * Cifrado autenticado para tokens y secretos.
 *
 * Preferencia: libsodium (XSalsa20-Poly1305). Alternativa: AES-256-GCM con OpenSSL.
 * La clave se deriva del material secreto de WordPress (AUTH_KEY + SECURE_AUTH_KEY).
 * Si no hay primitiva segura disponible, se niega a cifrar (fail-safe).
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Support;

class Crypto {

	private const PREFIX_SODIUM  = 'v1s:';
	private const PREFIX_OPENSSL = 'v1o:';

	public function isAvailable(): bool {
		return $this->sodiumAvailable() || $this->opensslAvailable();
	}

	public function backend(): string {
		if ( $this->sodiumAvailable() ) {
			return 'sodium';
		}
		if ( $this->opensslAvailable() ) {
			return 'openssl';
		}
		return 'none';
	}

	/**
	 * @throws \RuntimeException Si no hay criptografía segura disponible.
	 */
	public function encrypt( string $plaintext ): string {
		$key = $this->deriveKey();
		if ( $this->sodiumAvailable() ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
			return self::PREFIX_SODIUM . base64_encode( $nonce . $cipher );
		}
		if ( $this->opensslAvailable() ) {
			$iv     = random_bytes( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false === $cipher ) {
				throw new \RuntimeException( 'Fallo de cifrado OpenSSL.' );
			}
			return self::PREFIX_OPENSSL . base64_encode( $iv . $tag . $cipher );
		}
		throw new \RuntimeException( 'No hay criptografía segura disponible (falta Sodium y OpenSSL AES-256-GCM).' );
	}

	/** Devuelve null si el blob es inválido o fue manipulado. */
	public function decrypt( string $blob ): ?string {
		$key = $this->deriveKey();
		if ( str_starts_with( $blob, self::PREFIX_SODIUM ) && $this->sodiumAvailable() ) {
			$raw = base64_decode( substr( $blob, strlen( self::PREFIX_SODIUM ) ), true );
			if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
				return null;
			}
			$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			return false === $plain ? null : $plain;
		}
		if ( str_starts_with( $blob, self::PREFIX_OPENSSL ) && $this->opensslAvailable() ) {
			$raw = base64_decode( substr( $blob, strlen( self::PREFIX_OPENSSL ) ), true );
			if ( false === $raw || strlen( $raw ) < 12 + 16 ) {
				return null;
			}
			$iv     = substr( $raw, 0, 12 );
			$tag    = substr( $raw, 12, 16 );
			$cipher = substr( $raw, 28 );
			$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			return false === $plain ? null : $plain;
		}
		return null;
	}

	private function deriveKey(): string {
		$material = 'huexs-google-reviews|v1|';
		$material .= defined( 'AUTH_KEY' ) ? AUTH_KEY : '';
		$material .= defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '';
		if ( strlen( $material ) < 40 ) {
			throw new \RuntimeException( 'Material de clave insuficiente: define AUTH_KEY y SECURE_AUTH_KEY en wp-config.php.' );
		}
		return hash( 'sha256', $material, true );
	}

	private function sodiumAvailable(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_secretbox_open' );
	}

	private function opensslAvailable(): bool {
		return function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', array_map( 'strtolower', openssl_get_cipher_methods() ), true );
	}
}
