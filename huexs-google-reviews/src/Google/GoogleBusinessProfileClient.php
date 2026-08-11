<?php
/**
 * Cliente HTTP real de Google Business Profile (hosts fijos, reintentos con backoff).
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Google;

use Huexs\GoogleReviews\Auth\TokenService;
use Huexs\GoogleReviews\Google\Dto\AccountDto;
use Huexs\GoogleReviews\Google\Dto\LocationDto;
use Huexs\GoogleReviews\Google\Dto\ReviewsPage;
use Huexs\GoogleReviews\Google\Dto\ReviewTransformer;
use Huexs\GoogleReviews\Support\Logger;

class GoogleBusinessProfileClient implements GoogleClientInterface {

	private const HOST_ACCOUNTS  = 'https://mybusinessaccountmanagement.googleapis.com/v1';
	private const HOST_LOCATIONS = 'https://mybusinessbusinessinformation.googleapis.com/v1';
	private const HOST_REVIEWS   = 'https://mybusiness.googleapis.com/v4';

	private const MAX_RETRIES = 3;
	private const TIMEOUT     = 20;

	public function __construct(
		private TokenService $tokens,
		private Logger $logger
	) {}

	public function listAccounts(): array {
		$accounts  = array();
		$pageToken = null;
		do {
			$query = array( 'pageSize' => 20 );
			if ( $pageToken ) {
				$query['pageToken'] = $pageToken;
			}
			$data = $this->getJson( self::HOST_ACCOUNTS . '/accounts', $query );
			foreach ( (array) ( $data['accounts'] ?? array() ) as $raw ) {
				if ( is_array( $raw ) && ! empty( $raw['name'] ) ) {
					$accounts[] = new AccountDto(
						(string) $raw['name'],
						(string) ( $raw['accountName'] ?? '' ),
						(string) ( $raw['type'] ?? '' )
					);
				}
			}
			$pageToken = isset( $data['nextPageToken'] ) && is_string( $data['nextPageToken'] ) && '' !== $data['nextPageToken']
				? $data['nextPageToken'] : null;
		} while ( null !== $pageToken );

		return $accounts;
	}

	public function listLocations( string $accountName ): array {
		$this->assertResourceName( $accountName, 'accounts' );
		$locations = array();
		$pageToken = null;
		do {
			$query = array(
				'readMask' => 'name,title,storeCode',
				'pageSize' => 100,
			);
			if ( $pageToken ) {
				$query['pageToken'] = $pageToken;
			}
			$data = $this->getJson( self::HOST_LOCATIONS . '/' . $accountName . '/locations', $query );
			foreach ( (array) ( $data['locations'] ?? array() ) as $raw ) {
				if ( is_array( $raw ) && ! empty( $raw['name'] ) ) {
					$locations[] = new LocationDto(
						$accountName,
						(string) $raw['name'],
						(string) ( $raw['title'] ?? '' ),
						isset( $raw['storeCode'] ) && '' !== $raw['storeCode'] ? (string) $raw['storeCode'] : null
					);
				}
			}
			$pageToken = isset( $data['nextPageToken'] ) && is_string( $data['nextPageToken'] ) && '' !== $data['nextPageToken']
				? $data['nextPageToken'] : null;
		} while ( null !== $pageToken );

		return $locations;
	}

	public function listReviewsPage( string $accountName, string $locationId, ?string $pageToken = null ): ReviewsPage {
		$this->assertResourceName( $accountName, 'accounts' );
		if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $locationId ) ) {
			throw new GoogleApiException( 'configuration_error', 'ID de ubicación inválido.' );
		}

		$query = array( 'pageSize' => 50 );
		if ( $pageToken ) {
			$query['pageToken'] = $pageToken;
		}
		$url  = self::HOST_REVIEWS . '/' . $accountName . '/locations/' . $locationId . '/reviews';
		$data = $this->getJson( $url, $query );

		$reviews = array();
		foreach ( (array) ( $data['reviews'] ?? array() ) as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$dto = ReviewTransformer::fromApi( $raw );
			if ( null !== $dto ) {
				$reviews[] = $dto;
			}
		}

		return new ReviewsPage(
			$reviews,
			isset( $data['nextPageToken'] ) && is_string( $data['nextPageToken'] ) && '' !== $data['nextPageToken'] ? $data['nextPageToken'] : null,
			isset( $data['averageRating'] ) && is_numeric( $data['averageRating'] ) ? (float) $data['averageRating'] : null,
			isset( $data['totalReviewCount'] ) && is_numeric( $data['totalReviewCount'] ) ? (int) $data['totalReviewCount'] : null
		);
	}

	/**
	 * GET con Bearer, validación JSON y reintentos (429/5xx, máx. 3, backoff exponencial con jitter).
	 *
	 * @throws GoogleApiException
	 */
	private function getJson( string $url, array $query ): array {
		$accessToken = $this->tokens->getValidAccessToken();
		$fullUrl     = add_query_arg( urlencode_deep( $query ), $url );

		$attempt = 0;
		while ( true ) {
			$attempt++;
			$response = \wp_remote_get(
				$fullUrl,
				array(
					'timeout' => self::TIMEOUT,
					'headers' => array(
						'Authorization' => 'Bearer ' . $accessToken,
						'Accept'        => 'application/json',
						'User-Agent'    => 'huexs-google-reviews/' . ( defined( 'HGR_VERSION' ) ? HGR_VERSION : 'dev' ),
					),
				)
			);

			if ( \is_wp_error( $response ) ) {
				if ( $attempt <= self::MAX_RETRIES ) {
					$this->sleepBackoff( $attempt, null );
					continue;
				}
				throw new GoogleApiException( 'remote_unavailable', 'Error de red al contactar con Google: ' . $response->get_error_message() );
			}

			$code = (int) \wp_remote_retrieve_response_code( $response );
			$body = (string) \wp_remote_retrieve_body( $response );

			if ( 200 === $code ) {
				$data = json_decode( $body, true );
				if ( ! is_array( $data ) ) {
					throw new GoogleApiException( 'invalid_response', 'Google devolvió una respuesta que no es JSON válido.' );
				}
				return $data;
			}

			if ( 429 === $code || ( $code >= 500 && $code < 600 ) ) {
				if ( $attempt <= self::MAX_RETRIES ) {
					$retryAfter = \wp_remote_retrieve_header( $response, 'retry-after' );
					$this->sleepBackoff( $attempt, is_string( $retryAfter ) && ctype_digit( $retryAfter ) ? (int) $retryAfter : null );
					continue;
				}
				throw new GoogleApiException(
					429 === $code ? 'rate_limited' : 'remote_unavailable',
					429 === $code ? 'Google está limitando las peticiones (429). Espera y reintenta.' : 'Google no está disponible (HTTP ' . $code . ').'
				);
			}

			if ( 401 === $code ) {
				throw new GoogleApiException( 'auth_expired', 'Token no válido (401). Reconecta la cuenta de Google.' );
			}
			if ( 403 === $code ) {
				$isQuota = str_contains( strtolower( $body ), 'quota' ) || str_contains( strtolower( $body ), 'rate' );
				throw new GoogleApiException(
					$isQuota ? 'quota' : 'permission_denied',
					$isQuota
						? 'Cuota de la API de Business Profile agotada. Espera a que se renueve.'
						: 'Google denegó el acceso (403). Comprueba que la cuenta administra la ficha y que el proyecto tiene acceso a la API.'
				);
			}

			throw new GoogleApiException( 'invalid_response', 'Respuesta inesperada de Google (HTTP ' . $code . ').' );
		}
	}

	private function sleepBackoff( int $attempt, ?int $retryAfter ): void {
		$seconds = null !== $retryAfter
			? min( $retryAfter, 30 )
			: min( 2 ** $attempt, 16 ) + ( random_int( 0, 1000 ) / 1000 );
		usleep( (int) ( $seconds * 1_000_000 ) );
	}

	private function assertResourceName( string $name, string $prefix ): void {
		if ( ! preg_match( '/^' . $prefix . '\/[A-Za-z0-9_-]+$/', $name ) ) {
			throw new GoogleApiException( 'configuration_error', 'Nombre de recurso de Google inválido.' );
		}
	}
}
