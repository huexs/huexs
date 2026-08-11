<?php
/**
 * Traduce el formato crudo de Google Places API (New) a los DTO internos.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Source;

use Huexs\GoogleReviews\Google\Dto\ReviewDto;
use Huexs\GoogleReviews\Google\Dto\ReviewTransformer;

final class PlacesMapper {

	/** @return BusinessResult[] */
	public static function searchResults( array $data ): array {
		$results = array();
		foreach ( (array) ( $data['places'] ?? array() ) as $place ) {
			if ( ! is_array( $place ) || empty( $place['id'] ) || empty( $place['displayName']['text'] ) ) {
				continue;
			}
			$results[] = new BusinessResult(
				(string) $place['id'],
				(string) $place['displayName']['text'],
				isset( $place['formattedAddress'] ) ? (string) $place['formattedAddress'] : '',
				isset( $place['rating'] ) && is_numeric( $place['rating'] ) ? (float) $place['rating'] : null,
				isset( $place['userRatingCount'] ) && is_numeric( $place['userRatingCount'] ) ? (int) $place['userRatingCount'] : null
			);
		}
		return $results;
	}

	public static function place( array $place, string $fallbackPlaceId ): LocationReviews {
		$reviews = array();
		foreach ( (array) ( $place['reviews'] ?? array() ) as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$dto = self::review( $raw, $fallbackPlaceId );
			if ( null !== $dto ) {
				$reviews[] = $dto;
			}
		}

		$total = isset( $place['userRatingCount'] ) && is_numeric( $place['userRatingCount'] )
			? (int) $place['userRatingCount']
			: null;

		return new LocationReviews(
			$reviews,
			isset( $place['rating'] ) && is_numeric( $place['rating'] ) ? (float) $place['rating'] : null,
			$total,
			// Places nunca devuelve más de 5: si el total real es mayor, está recortado.
			null !== $total && $total > count( $reviews ),
			'places',
			isset( $place['googleMapsUri'] ) && is_string( $place['googleMapsUri'] ) && str_starts_with( $place['googleMapsUri'], 'https://' )
				? $place['googleMapsUri']
				: null,
			isset( $place['displayName']['text'] ) ? (string) $place['displayName']['text'] : null
		);
	}

	private static function review( array $raw, string $placeId ): ?ReviewDto {
		$rating = ReviewTransformer::starsToInt( $raw['rating'] ?? null );
		if ( null === $rating ) {
			return null;
		}

		$author = is_array( $raw['authorAttribution'] ?? null ) ? $raw['authorAttribution'] : array();
		$text   = $raw['originalText']['text'] ?? $raw['text']['text'] ?? null;
		$photo  = isset( $author['photoUri'] ) && is_string( $author['photoUri'] ) && str_starts_with( $author['photoUri'], 'https://' )
			? $author['photoUri']
			: null;

		return new ReviewDto(
			self::stableId( $raw, $placeId ),
			isset( $author['displayName'] ) && is_string( $author['displayName'] ) ? $author['displayName'] : null,
			$photo,
			$rating,
			is_string( $text ) && '' !== trim( $text ) ? trim( $text ) : null,
			ReviewTransformer::parseRfc3339( $raw['publishTime'] ?? null ),
			ReviewTransformer::parseRfc3339( $raw['publishTime'] ?? null ),
			// Places API (New) no expone las respuestas del propietario.
			null,
			null
		);
	}

	/**
	 * Identificador estable entre sincronizaciones.
	 *
	 * Si cambiara en cada lectura, el plugin borraría e insertaría las mismas
	 * reseñas indefinidamente.
	 */
	private static function stableId( array $raw, string $placeId ): string {
		if ( isset( $raw['name'] ) && is_string( $raw['name'] ) && str_contains( $raw['name'], '/reviews/' ) ) {
			return $raw['name'];
		}
		$seed = $placeId . '|' . ( $raw['authorAttribution']['displayName'] ?? '' ) . '|' . ( $raw['publishTime'] ?? '' );
		return 'derived-' . substr( sha1( $seed ), 0, 24 );
	}
}
