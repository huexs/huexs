<?php

declare(strict_types=1);

namespace Huexs\GoogleReviews\Tests\Unit;

use Huexs\GoogleReviews\Source\DirectPlacesSource;
use Huexs\GoogleReviews\Source\SourceException;
use Huexs\GoogleReviews\Support\Crypto;
use Huexs\GoogleReviews\Support\License;
use Huexs\GoogleReviews\Support\PlacesKey;
use Huexs\GoogleReviews\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Modo directo: el sitio habla con Google Places sin licencia ni servidor central.
 */
final class DirectPlacesSourceTest extends TestCase {

	private FakeHttpClient $http;
	private DirectPlacesSource $source;
	private PlacesKey $key;

	protected function setUp(): void {
		hgr_test_reset_state();
		$this->http = new FakeHttpClient();
		$this->key  = new PlacesKey( new Crypto() );
		$this->key->store( 'test-places-key' );
		$this->source = new DirectPlacesSource( $this->http, $this->key );
	}

	private function location( string $placeId = 'ChIJabc' ): object {
		return (object) array(
			'id'       => 1,
			'source'   => DirectPlacesSource::SOURCE_ID,
			'ref_key'  => $placeId,
			'place_id' => $placeId,
			'title'    => 'Mi Negocio',
		);
	}

	public function testIsConfiguredWithStoredKey(): void {
		self::assertTrue( $this->source->isConfigured() );
		self::assertTrue( $this->source->supportsSearch() );
	}

	public function testKeyIsStoredEncryptedNotInPlainText(): void {
		$stored = get_option( 'hgr_encrypted_places_key', '' );

		self::assertNotSame( '', $stored );
		self::assertStringNotContainsString( 'test-places-key', $stored, 'La clave nunca debe quedar en claro.' );
		self::assertSame( 'test-places-key', $this->key->value() );
	}

	public function testWithoutKeyNothingIsUnlockedAndNoRequestIsMade(): void {
		$this->key->clear();

		self::assertFalse( $this->source->isConfigured() );
		self::assertFalse( ( new License( new Crypto() ) )->isUnlocked() );

		$this->expectException( SourceException::class );
		$this->source->fetchReviews( $this->location() );
	}

	public function testSendsGoogleHeadersNotBearer(): void {
		$this->http->on( 'places:searchText', array( 'places' => array() ) );

		$this->source->searchBusinesses( 'mi negocio' );

		$headers = $this->http->sentHeaders[0];
		self::assertSame( 'test-places-key', $headers['X-Goog-Api-Key'] );
		self::assertArrayHasKey( 'X-Goog-FieldMask', $headers );
		self::assertArrayNotHasKey( 'Authorization', $headers, 'El modo directo no usa licencia.' );
	}

	public function testSearchMapsGooglePayload(): void {
		$this->http->on(
			'places:searchText',
			array(
				'places' => array(
					array(
						'id'               => 'ChIJ123',
						'displayName'      => array( 'text' => 'Huexs Señalética' ),
						'formattedAddress' => 'Barcelona',
						'rating'           => 4.8,
						'userRatingCount'  => 127,
					),
					array( 'displayName' => array( 'text' => 'Sin id' ) ),
				),
			)
		);

		$results = $this->source->searchBusinesses( 'huexs' );

		self::assertCount( 1, $results );
		self::assertSame( 'ChIJ123', $results[0]->placeId );
		self::assertSame( 'Huexs Señalética', $results[0]->name );
		self::assertSame( 127, $results[0]->reviewCount );
	}

	public function testShortQueryNeverReachesGoogle(): void {
		try {
			$this->source->searchBusinesses( 'ab' );
			self::fail( 'Debía rechazar la consulta.' );
		} catch ( SourceException $e ) {
			self::assertSame( 'configuration_error', $e->errorCode() );
		}
		self::assertSame( array(), $this->http->requestedUrls );
	}

	public function testFetchReviewsMapsGooglePayload(): void {
		$this->http->on(
			'places/ChIJabc',
			array(
				'id'              => 'ChIJabc',
				'displayName'     => array( 'text' => 'Huexs Señalética' ),
				'rating'          => 4.8,
				'userRatingCount' => 127,
				'googleMapsUri'   => 'https://maps.google.com/?cid=99',
				'reviews'         => array(
					array(
						'name'              => 'places/ChIJabc/reviews/rev1',
						'rating'            => 5,
						'originalText'      => array( 'text' => "Excelente.\nRepetiré.", 'languageCode' => 'es' ),
						'authorAttribution' => array(
							'displayName' => 'María García',
							'photoUri'    => 'https://lh3.googleusercontent.com/a/foto',
						),
						'publishTime'       => '2026-07-01T10:15:30Z',
					),
				),
			)
		);

		$result = $this->source->fetchReviews( $this->location() );

		self::assertCount( 1, $result->reviews );
		self::assertSame( 4.8, $result->rating );
		self::assertSame( 127, $result->reviewCount );
		self::assertSame( 'places', $result->sourceLabel );
		self::assertSame( 'https://maps.google.com/?cid=99', $result->publicUrl );

		$review = $result->reviews[0];
		self::assertSame( 'places/ChIJabc/reviews/rev1', $review->googleReviewId );
		self::assertSame( 'María García', $review->reviewerName );
		self::assertSame( '2026-07-01 10:15:30', $review->createTime );
		self::assertNull( $review->replyComment, 'Places no expone respuestas del propietario.' );
	}

	public function testTruncatedWhenGoogleTotalExceedsServedReviews(): void {
		$this->http->on(
			'places/ChIJabc',
			array(
				'id'              => 'ChIJabc',
				'rating'          => 4.8,
				'userRatingCount' => 127,
				'reviews'         => array(
					array( 'rating' => 5, 'publishTime' => '2026-07-01T10:00:00Z' ),
				),
			)
		);

		$result = $this->source->fetchReviews( $this->location() );

		self::assertTrue( $result->truncated, 'Google dice 127 pero solo devuelve 1: está recortado.' );
		self::assertSame( 127, $result->reviewCount, 'El total real se conserva para insignia y burbuja.' );
	}

	public function testDerivesStableIdWhenGoogleOmitsName(): void {
		$payload = array(
			'id'      => 'ChIJabc',
			'reviews' => array(
				array(
					'rating'            => 4,
					'publishTime'       => '2026-07-01T10:00:00Z',
					'authorAttribution' => array( 'displayName' => 'Autor' ),
				),
			),
		);

		$this->http->on( 'places/ChIJabc', $payload );
		$first = $this->source->fetchReviews( $this->location() )->reviews[0]->googleReviewId;

		$this->http->on( 'places/ChIJabc', $payload );
		$second = $this->source->fetchReviews( $this->location() )->reviews[0]->googleReviewId;

		self::assertMatchesRegularExpression( '/^derived-[a-f0-9]{24}$/', $first );
		self::assertSame( $first, $second, 'Un id inestable haría que el plugin borrase e insertase en bucle.' );
	}

	public function testDiscardsReviewsWithInvalidRating(): void {
		$this->http->on(
			'places/ChIJabc',
			array(
				'id'      => 'ChIJabc',
				'reviews' => array(
					array( 'rating' => 9, 'publishTime' => '2026-07-01T10:00:00Z' ),
					array( 'rating' => 4, 'publishTime' => '2026-07-01T10:00:00Z' ),
				),
			)
		);

		self::assertCount( 1, $this->source->fetchReviews( $this->location() )->reviews );
	}

	public function testDiscardsNonHttpsAvatar(): void {
		$this->http->on(
			'places/ChIJabc',
			array(
				'id'      => 'ChIJabc',
				'reviews' => array(
					array(
						'rating'            => 5,
						'publishTime'       => '2026-07-01T10:00:00Z',
						'authorAttribution' => array( 'photoUri' => 'http://inseguro.test/foto.jpg' ),
					),
				),
			)
		);

		self::assertNull( $this->source->fetchReviews( $this->location() )->reviews[0]->reviewerPhotoUrl );
	}

	public function testLicenseIsUnlockedAndUnrestricted(): void {
		$license = new License( new Crypto() );

		self::assertTrue( $license->isUnlocked() );
		self::assertFalse( $license->isRequired(), 'Con clave propia no debe exigirse licencia.' );
		self::assertSame( 'full', $license->plan() );
		self::assertTrue( $license->isPro() );
		self::assertFalse( $license->brandingRequired(), 'El modo completo no lleva marca.' );
		self::assertSame( 200, $license->maxReviews() );
		self::assertSame( 50, $license->maxLocations() );
		self::assertContains( 'floating', $license->allowedLayouts() );
		self::assertContains( 'sidebar', $license->allowedLayouts() );
		self::assertSame( 6, $license->minSyncHours() );
	}
}
