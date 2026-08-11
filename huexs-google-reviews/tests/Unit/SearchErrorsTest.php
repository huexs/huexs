<?php

declare(strict_types=1);

namespace Huexs\GoogleReviews\Tests\Unit;

use Huexs\GoogleReviews\Source\DirectPlacesSource;
use Huexs\GoogleReviews\Source\SourceException;
use Huexs\GoogleReviews\Support\Crypto;
use Huexs\GoogleReviews\Support\PlacesKey;
use Huexs\GoogleReviews\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Los errores de la clave de Google deben identificarse como tales.
 *
 * El transporte traduce 401/403 a `invalid_license` porque su caso habitual es la
 * API de Huexs. En modo directo eso mandaría al administrador a revisar una licencia
 * que no existe, en lugar de su clave de Google.
 */
final class SearchErrorsTest extends TestCase {

	private FakeHttpClient $http;
	private DirectPlacesSource $source;

	protected function setUp(): void {
		hgr_test_reset_state();
		$this->http = new FakeHttpClient();
		$key        = new PlacesKey( new Crypto() );
		$key->store( 'AIzaSyTest' );
		$this->source = new DirectPlacesSource( $this->http, $key );
	}

	public function testRejectedKeyIsNotReportedAsLicenseProblem(): void {
		$this->http->failOn(
			'places:searchText',
			'invalid_license',
			'Places API (New) has not been used in project 12345 before or it is disabled.'
		);

		try {
			$this->source->searchBusinesses( 'rotulamax barcelona' );
			self::fail( 'Debía propagar el error.' );
		} catch ( SourceException $e ) {
			self::assertSame( 'places_key_rejected', $e->errorCode() );
			self::assertStringNotContainsString( 'licencia de Huexs', $e->getMessage() );
		}
	}

	public function testGoogleMessageReachesTheAdministrator(): void {
		$this->http->failOn(
			'places:searchText',
			'invalid_license',
			'API keys with referer restrictions cannot be used with this API.'
		);

		try {
			$this->source->searchBusinesses( 'rotulamax barcelona' );
			self::fail( 'Debía propagar el error.' );
		} catch ( SourceException $e ) {
			// El motivo real de Google tiene que llegar entero: es lo único que
			// permite distinguir "API no habilitada" de "restricción por referente".
			self::assertStringContainsString( 'referer restrictions', $e->getMessage() );
			self::assertStringContainsString( 'Places API (New)', $e->suggestedAction() );
			self::assertStringContainsString( 'dirección IP', $e->suggestedAction() );
		}
	}

	public function testFetchReviewsAlsoTranslatesTheError(): void {
		$this->http->failOn( 'places/ChIJabc', 'invalid_license', 'REQUEST_DENIED' );

		$location = (object) array(
			'id'       => 1,
			'ref_key'  => 'ChIJabc',
			'place_id' => 'ChIJabc',
		);

		try {
			$this->source->fetchReviews( $location );
			self::fail( 'Debía propagar el error.' );
		} catch ( SourceException $e ) {
			self::assertSame( 'places_key_rejected', $e->errorCode() );
		}
	}

	public function testOtherErrorsKeepTheirOriginalCode(): void {
		$this->http->failOn( 'places:searchText', 'rate_limited', 'Demasiadas peticiones.' );

		try {
			$this->source->searchBusinesses( 'rotulamax barcelona' );
			self::fail( 'Debía propagar el error.' );
		} catch ( SourceException $e ) {
			self::assertSame( 'rate_limited', $e->errorCode(), 'Solo se reetiqueta invalid_license.' );
		}
	}

	public function testEmptyResultIsNotAnError(): void {
		$this->http->on( 'places:searchText', array( 'places' => array() ) );

		self::assertSame( array(), $this->source->searchBusinesses( 'negocio inexistente xyz' ) );
	}
}
