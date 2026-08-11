<?php
/**
 * Fuente por defecto: API central de Huexs.
 *
 * El cliente solo necesita una clave de licencia y buscar su negocio por nombre.
 * Ver docs/API_CONTRACT.md.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Source;

use Huexs\GoogleReviews\Support\HttpClientInterface;
use Huexs\GoogleReviews\Support\License;

class HuexsApiSource implements ReviewSourceInterface {

	private const DEFAULT_BASE_URL   = 'https://api.huexs.com/v1';
	private const MAX_PAGES          = 100;
	private const SEARCH_MIN_LENGTH  = 3;

	public function __construct(
		private HttpClientInterface $http,
		private License $license
	) {}

	public function id(): string {
		return self::SOURCE_HUEXS;
	}

	public function isConfigured(): bool {
		return $this->license->has();
	}

	public function supportsSearch(): bool {
		return true;
	}

	public static function baseUrl(): string {
		$base = defined( 'HGR_API_BASE_URL' ) && HGR_API_BASE_URL ? (string) HGR_API_BASE_URL : self::DEFAULT_BASE_URL;
		return rtrim( $base, '/' );
	}

	public function searchBusinesses( string $query ): array {
		$query = trim( $query );
		if ( mb_strlen( $query ) < self::SEARCH_MIN_LENGTH ) {
			throw new SourceException( 'configuration_error', 'Escribe al menos 3 caracteres para buscar tu negocio.' );
		}
		$this->assertConfigured();

		$url = \add_query_arg(
			\urlencode_deep(
				array(
					'q'        => $query,
					'language' => $this->language(),
					'region'   => $this->region(),
				)
			),
			self::baseUrl() . '/businesses/search'
		);

		$data    = $this->http->getJson( $url, $this->headers() );
		$results = array();
		foreach ( (array) ( $data['results'] ?? array() ) as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$result = BusinessResult::fromApi( $raw );
			if ( null !== $result ) {
				$results[] = $result;
			}
		}
		return $results;
	}

	public function fetchReviews( object $location ): LocationReviews {
		$this->assertConfigured();

		$placeId = (string) ( $location->place_id ?? '' );
		if ( '' === $placeId ) {
			throw new SourceException( 'configuration_error', 'La ubicación no tiene un identificador de negocio válido.' );
		}

		$reviews   = array();
		$cursor    = null;
		$pages     = 0;
		$rating    = null;
		$count     = null;
		$truncated = false;
		$label     = '';
		$publicUrl = null;
		$name      = null;

		do {
			$url = \add_query_arg(
				\urlencode_deep( array_filter( array(
					'limit'  => 50,
					'cursor' => $cursor,
				), static fn( $v ) => null !== $v ) ),
				self::baseUrl() . '/locations/' . rawurlencode( $placeId ) . '/reviews'
			);

			$data = $this->http->getJson( $url, $this->headers() );

			foreach ( (array) ( $data['reviews'] ?? array() ) as $raw ) {
				if ( ! is_array( $raw ) ) {
					continue;
				}
				$dto = HuexsReviewMapper::map( $raw );
				if ( null !== $dto ) {
					$reviews[] = $dto;
				}
			}

			$rating    = isset( $data['rating'] ) && is_numeric( $data['rating'] ) ? (float) $data['rating'] : $rating;
			$count     = isset( $data['review_count'] ) && is_numeric( $data['review_count'] ) ? (int) $data['review_count'] : $count;
			$truncated = ! empty( $data['truncated'] ) ? true : $truncated;
			$label     = isset( $data['source'] ) && is_string( $data['source'] ) ? $data['source'] : $label;
			$name      = isset( $data['name'] ) && is_string( $data['name'] ) && '' !== $data['name'] ? $data['name'] : $name;

			if ( isset( $data['public_url'] ) && is_string( $data['public_url'] ) && str_starts_with( $data['public_url'], 'https://' ) ) {
				$publicUrl = $data['public_url'];
			}

			$cursor = isset( $data['next_cursor'] ) && is_string( $data['next_cursor'] ) && '' !== $data['next_cursor']
				? $data['next_cursor']
				: null;

			$pages++;
			if ( $pages >= self::MAX_PAGES && null !== $cursor ) {
				throw new SourceException( 'invalid_response', 'Paginación anómala: demasiadas páginas de reseñas.' );
			}
		} while ( null !== $cursor );

		return new LocationReviews( $reviews, $rating, $count, $truncated, $label, $publicUrl, $name );
	}

	/**
	 * Consulta el plan y lo cachea.
	 *
	 * @throws SourceException
	 */
	public function refreshAccount(): array {
		$this->assertConfigured();
		$data = $this->http->getJson( self::baseUrl() . '/account', $this->headers() );
		$this->license->cachePlan( $data );
		return $data;
	}

	/**
	 * Inicia el flujo OAuth Pro delegado en el servidor de Huexs.
	 *
	 * @return array{authorize_url:string, state:string}
	 * @throws SourceException
	 */
	public function startOauth( string $returnUrl ): array {
		$this->assertConfigured();
		$data = $this->http->postJson(
			self::baseUrl() . '/oauth/start',
			array( 'return_url' => $returnUrl ),
			$this->headers()
		);
		$url   = isset( $data['authorize_url'] ) && is_string( $data['authorize_url'] ) ? $data['authorize_url'] : '';
		$state = isset( $data['state'] ) && is_string( $data['state'] ) ? $data['state'] : '';
		if ( '' === $url || ! str_starts_with( $url, 'https://' ) || '' === $state ) {
			throw new SourceException( 'invalid_response', 'El servidor no devolvió una URL de autorización válida.' );
		}
		return array(
			'authorize_url' => $url,
			'state'         => $state,
		);
	}

	/** @throws SourceException */
	public function oauthStatus( string $state ): array {
		$this->assertConfigured();
		return $this->http->getJson(
			\add_query_arg( \urlencode_deep( array( 'state' => $state ) ), self::baseUrl() . '/oauth/status' ),
			$this->headers()
		);
	}

	private function assertConfigured(): void {
		if ( ! $this->isConfigured() ) {
			throw new SourceException( 'invalid_license', 'Falta la clave de licencia de Huexs.' );
		}
	}

	private function headers(): array {
		return array(
			'Authorization'  => 'Bearer ' . $this->license->key(),
			'X-Huexs-Site'   => \home_url(),
			'X-Huexs-Plugin' => defined( 'HGR_VERSION' ) ? HGR_VERSION : 'dev',
		);
	}

	private function language(): string {
		$locale = function_exists( 'get_locale' ) ? (string) \get_locale() : 'es_ES';
		return strtolower( substr( $locale, 0, 2 ) ) ?: 'es';
	}

	private function region(): string {
		$locale = function_exists( 'get_locale' ) ? (string) \get_locale() : 'es_ES';
		$parts  = explode( '_', $locale );
		return isset( $parts[1] ) ? strtoupper( substr( $parts[1], 0, 2 ) ) : 'ES';
	}
}
