<?php

declare(strict_types=1);

namespace Huexs\GoogleReviews\Tests\Unit;

use Huexs\GoogleReviews\Google\Dto\ReviewTransformer;
use PHPUnit\Framework\TestCase;

final class ReviewTransformerTest extends TestCase {

	public function testStarsFromEnum(): void {
		self::assertSame( 1, ReviewTransformer::starsToInt( 'ONE' ) );
		self::assertSame( 5, ReviewTransformer::starsToInt( 'FIVE' ) );
		self::assertSame( 3, ReviewTransformer::starsToInt( 'three' ) );
		self::assertSame( 4, ReviewTransformer::starsToInt( 4 ) );
		self::assertSame( 2, ReviewTransformer::starsToInt( '2' ) );
	}

	public function testInvalidStarsReturnNull(): void {
		self::assertNull( ReviewTransformer::starsToInt( 'SEVEN' ) );
		self::assertNull( ReviewTransformer::starsToInt( 0 ) );
		self::assertNull( ReviewTransformer::starsToInt( 6 ) );
		self::assertNull( ReviewTransformer::starsToInt( null ) );
		self::assertNull( ReviewTransformer::starsToInt( array() ) );
	}

	public function testParseRfc3339ToUtc(): void {
		self::assertSame( '2026-07-01 10:15:30', ReviewTransformer::parseRfc3339( '2026-07-01T10:15:30Z' ) );
		// Con desfase horario se normaliza a UTC.
		self::assertSame( '2026-06-20 16:45:00', ReviewTransformer::parseRfc3339( '2026-06-20T18:45:00+02:00' ) );
		self::assertSame( '2026-01-01 00:00:00', ReviewTransformer::parseRfc3339( '2025-12-31T19:00:00-05:00' ) );
	}

	public function testParseInvalidDatesReturnNull(): void {
		self::assertNull( ReviewTransformer::parseRfc3339( 'no-es-fecha-válida-###' ) );
		self::assertNull( ReviewTransformer::parseRfc3339( '' ) );
		self::assertNull( ReviewTransformer::parseRfc3339( null ) );
	}

	public function testFromApiCompleteReview(): void {
		$fixture = json_decode( (string) file_get_contents( __DIR__ . '/../Fixtures/reviews-page-1.json' ), true );
		$dto     = ReviewTransformer::fromApi( $fixture['reviews'][0] );

		self::assertNotNull( $dto );
		self::assertSame( 'rev-001', $dto->googleReviewId );
		self::assertSame( 'María García', $dto->reviewerName );
		self::assertSame( 'https://lh3.googleusercontent.com/a/photo-maria', $dto->reviewerPhotoUrl );
		self::assertSame( 5, $dto->starRating );
		self::assertStringContainsString( "Servicio excelente.\n", (string) $dto->comment );
		self::assertSame( '2026-07-01 10:15:30', $dto->createTime );
		self::assertSame( '¡Gracias María! Un placer atenderte.', $dto->replyComment );
		self::assertSame( '2026-07-02 09:00:00', $dto->replyUpdateTime );
	}

	public function testFromApiWithoutCommentOrPhoto(): void {
		$fixture = json_decode( (string) file_get_contents( __DIR__ . '/../Fixtures/reviews-page-1.json' ), true );
		$dto     = ReviewTransformer::fromApi( $fixture['reviews'][2] );

		self::assertNotNull( $dto );
		self::assertNull( $dto->comment );
		self::assertNull( $dto->reviewerPhotoUrl );
		self::assertNull( $dto->replyComment );
	}

	public function testFromApiUnicodePreserved(): void {
		$fixture = json_decode( (string) file_get_contents( __DIR__ . '/../Fixtures/reviews-page-1.json' ), true );
		$dto     = ReviewTransformer::fromApi( $fixture['reviews'][1] );

		self::assertNotNull( $dto );
		self::assertStringContainsString( 'Ünïcödé ✓ 星', (string) $dto->comment );
	}

	public function testFromApiIdFallbackFromResourceName(): void {
		$fixture = json_decode( (string) file_get_contents( __DIR__ . '/../Fixtures/reviews-page-2.json' ), true );
		$dto     = ReviewTransformer::fromApi( $fixture['reviews'][1] );

		self::assertNotNull( $dto );
		self::assertSame( 'rev-005', $dto->googleReviewId );
	}

	public function testFromApiInvalidReviewsAreDiscarded(): void {
		$fixture = json_decode( (string) file_get_contents( __DIR__ . '/../Fixtures/review-invalid.json' ), true );

		self::assertNull( ReviewTransformer::fromApi( $fixture['reviews'][0] ) );
		self::assertNull( ReviewTransformer::fromApi( $fixture['reviews'][1] ) );
	}
}
