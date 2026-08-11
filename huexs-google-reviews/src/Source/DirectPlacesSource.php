<?php
/**
 * Modo directo: este WordPress habla con Google Places sin intermediarios.
 *
 * Pensado para los sitios propios de Huexs. No requiere licencia ni servidor central:
 * basta con definir la clave en wp-config.php, donde queda fuera de Git y del ZIP.
 *
 *     define( 'HGR_GOOGLE_PLACES_KEY', '…' );
 *
 * Todas las funciones quedan desbloqueadas. La única limitación es de Google, no del
 * plugin: Places API devuelve como máximo 5 reseñas por ficha y no incluye las
 * respuestas del propietario.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Source;

use Huexs\GoogleReviews\Support\HttpClientInterface;
use Huexs\GoogleReviews\Support\PlacesKey;

class DirectPlacesSource implements ReviewSourceInterface {

	public const SOURCE_ID = 'places_direct';

	private const SEARCH_URL  = 'https://places.googleapis.com/v1/places:searchText';
	private const DETAILS_URL = 'https://places.googleapis.com/v1/places/';

	private const SEARCH_FIELDS  = 'places.id,places.displayName,places.formattedAddress,places.rating,places.userRatingCount';
	private const DETAILS_FIELDS = 'id,displayName,formattedAddress,rating,userRatingCount,googleMapsUri,reviews';

	public function __construct(
		private HttpClientInterface $http,
		private PlacesKey $key
	) {}

	public function id(): string {
		return self::SOURCE_ID;
	}

	public function isConfigured(): bool {
		return $this->key->has();
	}

	public function supportsSearch(): bool {
		return true;
	}

	public function searchBusinesses( string $query ): array {
		$query = trim( $query );
		if ( mb_strlen( $query ) < 3 ) {
			throw new SourceException( 'configuration_error', 'Escribe al menos 3 caracteres para buscar tu negocio.' );
		}
		$this->assertConfigured();

		try {
			$data = $this->http->postJson(
				self::SEARCH_URL,
				array(
					'textQuery'      => $query,
					'languageCode'   => $this->language(),
					'regionCode'     => $this->region(),
					'maxResultCount' => 10,
				),
				$this->headers( self::SEARCH_FIELDS )
			);
		} catch ( SourceException $e ) {
			throw $this->translate( $e );
		}

		return PlacesMapper::searchResults( $data );
	}

	public function fetchReviews( object $location ): LocationReviews {
		$this->assertConfigured();

		$placeId = (string) ( $location->place_id ?? $location->ref_key ?? '' );
		if ( '' === $placeId ) {
			throw new SourceException( 'configuration_error', 'La ubicación no tiene un identificador de negocio válido.' );
		}

		$url = self::DETAILS_URL . rawurlencode( $placeId )
			. '?languageCode=' . rawurlencode( $this->language() );

		try {
			$data = $this->http->getJson( $url, $this->headers( self::DETAILS_FIELDS ) );
		} catch ( SourceException $e ) {
			throw $this->translate( $e );
		}

		return PlacesMapper::place( $data, $placeId );
	}

	/**
	 * Reetiqueta los errores del transporte al vocabulario de esta fuente.
	 *
	 * El cliente HTTP traduce 401/403 a `invalid_license` porque su caso habitual es
	 * la API de Huexs. Aquí no hay licencia de por medio: un 403 significa que la
	 * clave de Google está mal configurada, y decir lo contrario manda al
	 * administrador a buscar el problema donde no está.
	 */
	private function translate( SourceException $e ): SourceException {
		if ( 'invalid_license' !== $e->errorCode() ) {
			return $e;
		}

		return new SourceException(
			'places_key_rejected',
			'Google rechazó la clave de Places. Respuesta de Google: ' . $e->getMessage(),
			$e
		);
	}

	private function assertConfigured(): void {
		if ( ! $this->key->has() ) {
			throw new SourceException(
				'configuration_error',
				'Falta la clave de Google Places. Defínela en wp-config.php como HGR_GOOGLE_PLACES_KEY o guárdala en la pantalla Conexión.'
			);
		}
	}

	private function headers( string $fieldMask ): array {
		return array(
			'X-Goog-Api-Key'   => $this->key->value(),
			'X-Goog-FieldMask' => $fieldMask,
			// Muchas claves de Google están restringidas por referente HTTP, porque es
			// lo que Google propone por defecto. Una llamada de servidor no envía
			// referente y Google la bloquea con "Requests from referer <empty>".
			// Declarar el dominio del propio sitio es exacto: la petición sale de él.
			// Con restricción por IP esta cabecera es inocua.
			'Referer'          => \home_url( '/' ),
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
