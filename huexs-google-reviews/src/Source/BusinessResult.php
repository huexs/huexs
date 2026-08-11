<?php
/**
 * Resultado de búsqueda de negocio por nombre.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Source;

final class BusinessResult {

	public function __construct(
		public readonly string $placeId,
		public readonly string $name,
		public readonly string $address,
		public readonly ?float $rating,
		public readonly ?int $reviewCount
	) {}

	public static function fromApi( array $raw ): ?self {
		$placeId = isset( $raw['place_id'] ) && is_string( $raw['place_id'] ) ? trim( $raw['place_id'] ) : '';
		$name    = isset( $raw['name'] ) && is_string( $raw['name'] ) ? trim( $raw['name'] ) : '';
		if ( '' === $placeId || '' === $name ) {
			return null;
		}
		return new self(
			$placeId,
			$name,
			isset( $raw['formatted_address'] ) && is_string( $raw['formatted_address'] ) ? trim( $raw['formatted_address'] ) : '',
			isset( $raw['rating'] ) && is_numeric( $raw['rating'] ) ? (float) $raw['rating'] : null,
			isset( $raw['review_count'] ) && is_numeric( $raw['review_count'] ) ? (int) $raw['review_count'] : null
		);
	}
}
