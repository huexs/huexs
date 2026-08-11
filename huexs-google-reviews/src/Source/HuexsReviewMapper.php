<?php
/**
 * Convierte el formato neutro de la API de Huexs a los DTO internos.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Source;

use Huexs\GoogleReviews\Google\Dto\ReviewDto;
use Huexs\GoogleReviews\Google\Dto\ReviewTransformer;

final class HuexsReviewMapper {

	/** Devuelve null si la reseña no cumple el contrato mínimo. */
	public static function map( array $raw ): ?ReviewDto {
		$id     = isset( $raw['id'] ) && is_string( $raw['id'] ) ? trim( $raw['id'] ) : '';
		$rating = ReviewTransformer::starsToInt( $raw['rating'] ?? null );
		if ( '' === $id || null === $rating ) {
			return null;
		}

		$reply = is_array( $raw['reply'] ?? null ) ? $raw['reply'] : array();

		return new ReviewDto(
			$id,
			self::text( $raw['author_name'] ?? null ),
			self::httpsUrl( $raw['author_photo_url'] ?? null ),
			$rating,
			self::text( $raw['text'] ?? null ),
			ReviewTransformer::parseRfc3339( $raw['created_at'] ?? null ),
			ReviewTransformer::parseRfc3339( $raw['updated_at'] ?? null ),
			self::text( $reply['text'] ?? null ),
			ReviewTransformer::parseRfc3339( $reply['updated_at'] ?? null )
		);
	}

	private static function text( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		return '' === $value ? null : $value;
	}

	/** Solo se aceptan avatares por HTTPS. */
	private static function httpsUrl( mixed $value ): ?string {
		$value = self::text( $value );
		if ( null === $value || ! str_starts_with( $value, 'https://' ) ) {
			return null;
		}
		return $value;
	}
}
