<?php

declare(strict_types=1);

namespace Huexs\GoogleReviews\Tests\Unit;

use Huexs\GoogleReviews\Support\Logger;
use PHPUnit\Framework\TestCase;

final class LoggerRedactionTest extends TestCase {

	public function testRedactsAccessTokens(): void {
		$redacted = Logger::redact( 'Error con access_token=ya29.a0AfH6SMBxyz-123 en la petición' );
		self::assertStringNotContainsString( 'ya29.a0AfH6SMBxyz-123', $redacted );
	}

	public function testRedactsBearerHeaders(): void {
		$redacted = Logger::redact( 'Authorization: Bearer ya29.SECRETO-abc_def/xyz==' );
		self::assertStringNotContainsString( 'SECRETO', $redacted );
		self::assertStringContainsString( 'Bearer [REDACTADO]', $redacted );
	}

	public function testRedactsRefreshTokensAndSecrets(): void {
		$redacted = Logger::redact( '{"refresh_token":"1//abcdefghijklmnopqrstuvwxyz1234567890","client_secret":"GOCSPX-abc123"}' );
		self::assertStringNotContainsString( 'abcdefghijklmnopqrstuvwxyz1234567890', $redacted );
		self::assertStringNotContainsString( 'GOCSPX-abc123', $redacted );
	}

	public function testKeepsHarmlessText(): void {
		$message = 'Sincronización completada: 12 reseñas vistas.';
		self::assertSame( $message, Logger::redact( $message ) );
	}
}
