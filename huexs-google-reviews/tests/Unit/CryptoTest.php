<?php

declare(strict_types=1);

namespace Huexs\GoogleReviews\Tests\Unit;

use Huexs\GoogleReviews\Support\Crypto;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase {

	public function testBackendAvailable(): void {
		$crypto = new Crypto();
		self::assertTrue( $crypto->isAvailable(), 'El entorno de test debe disponer de Sodium u OpenSSL.' );
		self::assertContains( $crypto->backend(), array( 'sodium', 'openssl' ) );
	}

	public function testEncryptDecryptRoundtrip(): void {
		$crypto = new Crypto();
		$secret = json_encode(
			array(
				'access_token'  => 'ya29.token-de-prueba',
				'refresh_token' => '1//refresh-de-prueba',
				'expires_at'    => 1234567890,
			)
		);

		$blob = $crypto->encrypt( (string) $secret );

		self::assertNotSame( $secret, $blob );
		self::assertStringNotContainsString( 'ya29', $blob );
		self::assertSame( $secret, $crypto->decrypt( $blob ) );
	}

	public function testNoncesMakeCiphertextsUnique(): void {
		$crypto = new Crypto();
		self::assertNotSame( $crypto->encrypt( 'mismo-texto' ), $crypto->encrypt( 'mismo-texto' ) );
	}

	public function testTamperedBlobReturnsNull(): void {
		$crypto = new Crypto();
		$blob   = $crypto->encrypt( 'contenido-sensible' );

		// Alteramos un byte del payload manteniendo el prefijo de versión.
		$prefix  = substr( $blob, 0, 4 );
		$payload = base64_decode( substr( $blob, 4 ), true );
		$mid     = intdiv( strlen( (string) $payload ), 2 );
		$payload[ $mid ] = chr( ord( $payload[ $mid ] ) ^ 0xFF );

		self::assertNull( $crypto->decrypt( $prefix . base64_encode( (string) $payload ) ) );
	}

	public function testGarbageBlobReturnsNull(): void {
		$crypto = new Crypto();
		self::assertNull( $crypto->decrypt( 'no-es-un-blob' ) );
		self::assertNull( $crypto->decrypt( '' ) );
	}
}
