<?php
/**
 * Transformación de la respuesta cruda de la API v4 a DTOs internos.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Google\Dto;

final class ReviewTransformer {

	private const STAR_MAP = array(
		'ONE'   => 1,
		'TWO'   => 2,
		'THREE' => 3,
		'FOUR'  => 4,
		'FIVE'  => 5,
	);

	/** Devuelve null si la reseña no tiene los campos mínimos válidos. */
	public static function fromApi( array $raw ): ?ReviewDto {
		$id = isset( $raw['reviewId'] ) && is_string( $raw['reviewId'] ) ? trim( $raw['reviewId'] ) : '';
		if ( '' === $id && isset( $raw['name'] ) && is_string( $raw['name'] ) ) {
			// Alternativa: extraer del recurso "accounts/x/locations/y/reviews/z".
			$parts = explode( '/reviews/', $raw['name'] );
			$id    = count( $parts ) === 2 ? trim( $parts[1] ) : '';
		}
		$stars = self::starsToInt( $raw['starRating'] ?? null );
		if ( '' === $id || null === $stars ) {
			return null;
		}

		$reviewer = is_array( $raw['reviewer'] ?? null ) ? $raw['reviewer'] : array();
		$reply    = is_array( $raw['reviewReply'] ?? null ) ? $raw['reviewReply'] : array();

		return new ReviewDto(
			$id,
			self::stringOrNull( $reviewer['displayName'] ?? null ),
			self::stringOrNull( $reviewer['profilePhotoUrl'] ?? null ),
			$stars,
			self::stringOrNull( $raw['comment'] ?? null ),
			self::parseRfc3339( $raw['createTime'] ?? null ),
			self::parseRfc3339( $raw['updateTime'] ?? null ),
			self::stringOrNull( $reply['comment'] ?? null ),
			self::parseRfc3339( $reply['updateTime'] ?? null )
		);
	}

	/** Acepta el enum de la API ("FIVE"), un entero o un numérico en string. */
	public static function starsToInt( mixed $value ): ?int {
		if ( is_string( $value ) && isset( self::STAR_MAP[ strtoupper( trim( $value ) ) ] ) ) {
			return self::STAR_MAP[ strtoupper( trim( $value ) ) ];
		}
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			$int = (int) $value;
			return ( $int >= 1 && $int <= 5 ) ? $int : null;
		}
		return null;
	}

	/** RFC 3339 → "Y-m-d H:i:s" en UTC; null si es inválida. */
	public static function parseRfc3339( mixed $value ): ?string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}
		try {
			$date = new \DateTimeImmutable( trim( $value ) );
		} catch ( \Exception ) {
			return null;
		}
		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	private static function stringOrNull( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		return '' === $value ? null : $value;
	}
}
