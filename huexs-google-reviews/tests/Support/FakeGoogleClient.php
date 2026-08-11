<?php

declare(strict_types=1);

namespace Huexs\GoogleReviews\Tests\Support;

use Huexs\GoogleReviews\Google\Dto\AccountDto;
use Huexs\GoogleReviews\Google\Dto\LocationDto;
use Huexs\GoogleReviews\Google\Dto\ReviewsPage;
use Huexs\GoogleReviews\Google\Dto\ReviewTransformer;
use Huexs\GoogleReviews\Google\GoogleApiException;
use Huexs\GoogleReviews\Google\GoogleClientInterface;

/**
 * Fake configurable: sirve páginas de fixtures y puede fallar en una página concreta.
 */
final class FakeGoogleClient implements GoogleClientInterface {

	/** @var AccountDto[] */
	public array $accounts = array();

	/** @var array<string, LocationDto[]> */
	public array $locationsByAccount = array();

	/** @var array<string, ReviewsPage[]> Clave "accountName|locationId". */
	private array $reviewPages = array();

	/** @var array<string, int> Índice de página que lanzará excepción. */
	private array $failAtPage = array();

	public int $reviewRequests = 0;

	/** Carga páginas desde un fixture JSON con el formato de la API v4. */
	public function loadReviewPagesFromFixtures( string $accountName, string $locationId, array $fixtureFiles ): void {
		$pages = array();
		foreach ( $fixtureFiles as $file ) {
			$raw     = json_decode( (string) file_get_contents( $file ), true );
			$reviews = array();
			foreach ( (array) ( $raw['reviews'] ?? array() ) as $item ) {
				$dto = ReviewTransformer::fromApi( $item );
				if ( null !== $dto ) {
					$reviews[] = $dto;
				}
			}
			$pages[] = new ReviewsPage(
				$reviews,
				isset( $raw['nextPageToken'] ) && '' !== $raw['nextPageToken'] ? (string) $raw['nextPageToken'] : null,
				isset( $raw['averageRating'] ) ? (float) $raw['averageRating'] : null,
				isset( $raw['totalReviewCount'] ) ? (int) $raw['totalReviewCount'] : null
			);
		}
		$this->reviewPages[ $accountName . '|' . $locationId ] = $pages;
	}

	/** @param ReviewsPage[] $pages */
	public function setReviewPages( string $accountName, string $locationId, array $pages ): void {
		$this->reviewPages[ $accountName . '|' . $locationId ] = $pages;
	}

	public function failAt( string $accountName, string $locationId, int $pageIndex ): void {
		$this->failAtPage[ $accountName . '|' . $locationId ] = $pageIndex;
	}

	public function listAccounts(): array {
		return $this->accounts;
	}

	public function listLocations( string $accountName ): array {
		return $this->locationsByAccount[ $accountName ] ?? array();
	}

	public function listReviewsPage( string $accountName, string $locationId, ?string $pageToken = null ): ReviewsPage {
		$this->reviewRequests++;
		$key   = $accountName . '|' . $locationId;
		$pages = $this->reviewPages[ $key ] ?? array();
		$index = null === $pageToken ? 0 : (int) $pageToken;

		if ( isset( $this->failAtPage[ $key ] ) && $index === $this->failAtPage[ $key ] ) {
			throw new GoogleApiException( 'remote_unavailable', 'Fallo simulado en la página ' . $index . '.' );
		}
		if ( ! isset( $pages[ $index ] ) ) {
			return new ReviewsPage( array(), null, null, null );
		}

		$page = $pages[ $index ];
		// El token de la página fake es simplemente el índice siguiente.
		$next = null !== $page->nextPageToken ? (string) ( $index + 1 ) : null;
		return new ReviewsPage( $page->reviews, $next, $page->averageRating, $page->totalReviewCount );
	}
}
