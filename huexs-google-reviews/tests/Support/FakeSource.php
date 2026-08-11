<?php

declare(strict_types=1);

namespace Huexs\GoogleReviews\Tests\Support;

use Huexs\GoogleReviews\Source\BusinessResult;
use Huexs\GoogleReviews\Source\LocationReviews;
use Huexs\GoogleReviews\Source\ReviewSourceInterface;
use Huexs\GoogleReviews\Source\SourceException;

/**
 * Fuente configurable para pruebas: sirve resultados fijos o falla a voluntad.
 */
final class FakeSource implements ReviewSourceInterface {

	/** @var array<string, LocationReviews> Clave: ref_key. */
	private array $results = array();

	/** @var array<string, SourceException> Clave: ref_key. */
	private array $failures = array();

	/** @var BusinessResult[] */
	public array $searchResults = array();

	public int $fetchCalls = 0;

	public function __construct( private string $sourceId = self::SOURCE_HUEXS ) {}

	public function setResult( string $refKey, LocationReviews $result ): void {
		$this->results[ $refKey ] = $result;
	}

	public function failWith( string $refKey, string $code, string $message = 'Fallo simulado.' ): void {
		$this->failures[ $refKey ] = new SourceException( $code, $message );
	}

	public function id(): string {
		return $this->sourceId;
	}

	public function isConfigured(): bool {
		return true;
	}

	public function supportsSearch(): bool {
		return true;
	}

	public function searchBusinesses( string $query ): array {
		return $this->searchResults;
	}

	public function fetchReviews( object $location ): LocationReviews {
		$this->fetchCalls++;
		$key = (string) $location->ref_key;

		if ( isset( $this->failures[ $key ] ) ) {
			throw $this->failures[ $key ];
		}

		return $this->results[ $key ] ?? new LocationReviews( array(), null, null );
	}
}
