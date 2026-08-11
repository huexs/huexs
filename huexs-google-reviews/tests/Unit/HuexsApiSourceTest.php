<?php

declare(strict_types=1);

namespace Huexs\GoogleReviews\Tests\Unit;

use Huexs\GoogleReviews\Source\HuexsApiSource;
use Huexs\GoogleReviews\Source\SourceException;
use Huexs\GoogleReviews\Support\Crypto;
use Huexs\GoogleReviews\Support\License;
use Huexs\GoogleReviews\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class HuexsApiSourceTest extends TestCase {

	private FakeHttpClient $http;
	private License $license;
	private HuexsApiSource $source;

	protected function setUp(): void {
		hgr_test_reset_state();
		$this->http    = new FakeHttpClient();
		$this->license = new License( new Crypto() );
		$this->license->store( 'HGR-TEST-1234' );
		$this->source = new HuexsApiSource( $this->http, $this->license );
	}

	private function location( string $placeId = 'ChIJabc' ): object {
		return (object) array(
			'id'       => 1,
			'source'   => 'huexs',
			'ref_key'  => $placeId,
			'place_id' => $placeId,
			'title'    => 'Mi Negocio',
		);
	}

	public function testRequiresLicense(): void {
		$this->license->clear();

		$this->expectException( SourceException::class );
		$this->expectExceptionMessage( 'Falta la clave de licencia' );
		$this->source->searchBusinesses( 'mi negocio' );
	}

	public function testSendsLicenseAndSiteHeaders(): void {
		$this->http->on( '/businesses/search', array( 'results' => array() ) );

		$this->source->searchBusinesses( 'mi negocio' );

		$headers = $this->http->sentHeaders[0];
		self::assertSame( 'Bearer HGR-TEST-1234', $headers['Authorization'] );
		self::assertArrayHasKey( 'X-Huexs-Site', $headers );
	}

	public function testShortQueryIsRejectedBeforeAnyRequest(): void {
		try {
			$this->source->searchBusinesses( 'ab' );
			self::fail( 'Debía rechazar una consulta demasiado corta.' );
		} catch ( SourceException $e ) {
			self::assertSame( 'configuration_error', $e->errorCode() );
		}
		self::assertSame( array(), $this->http->requestedUrls, 'No debe llamar a la API con consultas cortas.' );
	}

	public function testSearchMapsResultsAndSkipsInvalidOnes(): void {
		$this->http->on(
			'/businesses/search',
			array(
				'results' => array(
					array(
						'place_id'          => 'ChIJ123',
						'name'              => 'Huexs Señalética',
						'formatted_address' => 'Carrer d\'Exemple 12, Barcelona',
						'rating'            => 4.8,
						'review_count'      => 127,
					),
					array( 'name' => 'Sin place_id' ),
					array( 'place_id' => 'ChIJ999' ),
				),
			)
		);

		$results = $this->source->searchBusinesses( 'huexs' );

		self::assertCount( 1, $results );
		self::assertSame( 'ChIJ123', $results[0]->placeId );
		self::assertSame( 'Huexs Señalética', $results[0]->name );
		self::assertSame( 4.8, $results[0]->rating );
		self::assertSame( 127, $results[0]->reviewCount );
	}

	public function testFetchReviewsMapsPayload(): void {
		$this->http->on(
			'/reviews',
			array(
				'place_id'     => 'ChIJabc',
				'name'         => 'Mi Negocio',
				'public_url'   => 'https://maps.google.com/?cid=99',
				'rating'       => 4.6,
				'review_count' => 210,
				'source'       => 'places',
				'truncated'    => true,
				'reviews'      => array(
					array(
						'id'               => 'rev-1',
						'author_name'      => 'María García',
						'author_photo_url' => 'https://lh3.googleusercontent.com/a/foto',
						'rating'           => 5,
						'text'             => "Excelente.\nRepetiré.",
						'created_at'       => '2026-07-01T10:15:30Z',
						'updated_at'       => '2026-07-01T10:15:30Z',
						'reply'            => array(
							'text'       => '¡Gracias!',
							'updated_at' => '2026-07-02T09:00:00Z',
						),
					),
				),
			)
		);

		$result = $this->source->fetchReviews( $this->location() );

		self::assertCount( 1, $result->reviews );
		self::assertSame( 4.6, $result->rating );
		self::assertSame( 210, $result->reviewCount );
		self::assertTrue( $result->truncated );
		self::assertSame( 'places', $result->sourceLabel );
		self::assertSame( 'https://maps.google.com/?cid=99', $result->publicUrl );

		$review = $result->reviews[0];
		self::assertSame( 'rev-1', $review->googleReviewId );
		self::assertSame( 'María García', $review->reviewerName );
		self::assertSame( 5, $review->starRating );
		self::assertSame( '2026-07-01 10:15:30', $review->createTime );
		self::assertSame( '¡Gracias!', $review->replyComment );
	}

	public function testFetchReviewsFollowsCursorPagination(): void {
		$this->http->on(
			'/reviews',
			array(
				'rating'      => 4.5,
				'reviews'     => array( array( 'id' => 'r1', 'rating' => 5 ) ),
				'next_cursor' => 'cursor-2',
			)
		);
		$this->http->on(
			'/reviews',
			array(
				'reviews'     => array( array( 'id' => 'r2', 'rating' => 4 ) ),
				'next_cursor' => null,
			)
		);

		$result = $this->source->fetchReviews( $this->location() );

		self::assertCount( 2, $result->reviews );
		self::assertCount( 2, $this->http->requestedUrls );
		self::assertStringContainsString( 'cursor=cursor-2', $this->http->requestedUrls[1] );
	}

	public function testFailureMidPaginationPropagatesAndYieldsNoPartialResult(): void {
		$this->http->on(
			'/reviews',
			array(
				'reviews'     => array( array( 'id' => 'r1', 'rating' => 5 ) ),
				'next_cursor' => 'cursor-2',
			)
		);
		$this->http->failOn( 'cursor=cursor-2', 'upstream_error' );

		// Contrato: nunca se devuelve un resultado parcial; se lanza excepción.
		$this->expectException( SourceException::class );
		$this->source->fetchReviews( $this->location() );
	}

	public function testInvalidReviewsAreSkipped(): void {
		$this->http->on(
			'/reviews',
			array(
				'reviews' => array(
					array( 'id' => 'ok', 'rating' => 4 ),
					array( 'rating' => 5 ),                       // sin id
					array( 'id' => 'sin-estrellas' ),             // sin rating
					array( 'id' => 'fuera-de-rango', 'rating' => 9 ),
				),
			)
		);

		$result = $this->source->fetchReviews( $this->location() );

		self::assertCount( 1, $result->reviews );
		self::assertSame( 'ok', $result->reviews[0]->googleReviewId );
	}

	public function testNonHttpsAvatarIsDiscarded(): void {
		$this->http->on(
			'/reviews',
			array(
				'reviews' => array(
					array(
						'id'               => 'r1',
						'rating'           => 5,
						'author_photo_url' => 'http://inseguro.example.com/foto.jpg',
					),
				),
			)
		);

		$result = $this->source->fetchReviews( $this->location() );

		self::assertNull( $result->reviews[0]->reviewerPhotoUrl );
	}

	public function testLocationWithoutPlaceIdIsRejected(): void {
		$location = (object) array(
			'id'       => 1,
			'ref_key'  => '',
			'place_id' => '',
		);

		$this->expectException( SourceException::class );
		$this->source->fetchReviews( $location );
	}

	public function testRefreshAccountCachesPlan(): void {
		$this->http->on(
			'/account',
			array(
				'plan'   => 'pro',
				'limits' => array(
					'max_reviews'       => 200,
					'branding_required' => false,
				),
			)
		);

		$this->source->refreshAccount();

		self::assertSame( 'pro', $this->license->plan() );
		self::assertTrue( $this->license->isPro() );
		self::assertSame( 200, $this->license->maxReviews() );
		self::assertFalse( $this->license->brandingRequired() );
	}

	public function testStartOauthRejectsNonHttpsUrl(): void {
		$this->http->on(
			'/oauth/start',
			array(
				'authorize_url' => 'http://inseguro.example.com/auth',
				'state'         => 'abc',
			)
		);

		$this->expectException( SourceException::class );
		$this->source->startOauth( 'https://sitio.com/wp-admin/' );
	}
}
