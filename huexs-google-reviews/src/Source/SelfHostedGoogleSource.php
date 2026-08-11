<?php
/**
 * Modo avanzado: proyecto propio de Google Cloud + OAuth directo, sin pasar por Huexs.
 *
 * Envuelve el cliente de Business Profile ya existente y traduce sus errores al
 * vocabulario común de fuentes.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Source;

use Huexs\GoogleReviews\Auth\TokenStore;
use Huexs\GoogleReviews\Google\GoogleApiException;
use Huexs\GoogleReviews\Google\GoogleClientInterface;

class SelfHostedGoogleSource implements ReviewSourceInterface {

	private const MAX_PAGES = 200;

	public function __construct(
		private GoogleClientInterface $client,
		private TokenStore $tokens
	) {}

	public function id(): string {
		return self::SOURCE_GOOGLE;
	}

	public function isConfigured(): bool {
		return $this->tokens->isConnected();
	}

	public function supportsSearch(): bool {
		// Business Profile solo expone las fichas que administra la cuenta conectada:
		// no hay búsqueda libre por nombre, se listan y se eligen.
		return false;
	}

	public function searchBusinesses( string $query ): array {
		throw new SourceException( 'not_supported', 'El modo avanzado no busca por nombre: lista las fichas que administra la cuenta conectada.' );
	}

	/**
	 * Descubre las fichas administradas por la cuenta conectada.
	 *
	 * @return BusinessResult[]
	 * @throws SourceException
	 */
	public function listManagedLocations(): array {
		try {
			$results = array();
			foreach ( $this->client->listAccounts() as $account ) {
				foreach ( $this->client->listLocations( $account->name ) as $location ) {
					$results[] = new BusinessResult(
						$account->name . '/' . $location->locationName,
						$location->title,
						(string) ( $location->storeCode ?? '' ),
						null,
						null
					);
				}
			}
			return $results;
		} catch ( GoogleApiException $e ) {
			throw $this->translate( $e );
		}
	}

	public function fetchReviews( object $location ): LocationReviews {
		$accountName = (string) ( $location->google_account_name ?? '' );
		$locationId  = (string) ( $location->google_location_id ?? '' );
		if ( '' === $accountName || '' === $locationId ) {
			throw new SourceException( 'configuration_error', 'La ubicación no tiene identificadores de Google válidos.' );
		}

		try {
			$reviews   = array();
			$pageToken = null;
			$pages     = 0;
			$rating    = null;
			$count     = null;

			do {
				$page = $this->client->listReviewsPage( $accountName, $locationId, $pageToken );
				foreach ( $page->reviews as $dto ) {
					$reviews[] = $dto;
				}
				$rating    = $page->averageRating ?? $rating;
				$count     = $page->totalReviewCount ?? $count;
				$pageToken = $page->nextPageToken;

				$pages++;
				if ( $pages >= self::MAX_PAGES && null !== $pageToken ) {
					throw new GoogleApiException( 'invalid_response', 'Paginación anómala: demasiadas páginas de reseñas.' );
				}
			} while ( null !== $pageToken );

			return new LocationReviews(
				$reviews,
				$rating,
				$count ?? count( $reviews ),
				false,
				'business_profile',
				isset( $location->public_google_url ) ? (string) $location->public_google_url : null,
				isset( $location->title ) ? (string) $location->title : null
			);
		} catch ( GoogleApiException $e ) {
			throw $this->translate( $e );
		}
	}

	private function translate( GoogleApiException $e ): SourceException {
		return new SourceException( $e->errorCode(), $e->getMessage(), $e );
	}
}
