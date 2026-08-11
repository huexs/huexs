<?php
/**
 * Conjunto COMPLETO de reseñas de una ubicación tal y como lo devuelve una fuente.
 *
 * Contrato: si una fuente no puede completar la lectura, debe lanzar SourceException,
 * nunca devolver un resultado parcial. Esto es lo que garantiza que una sincronización
 * incompleta jamás borre reseñas ya almacenadas.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Source;

final class LocationReviews {

	/**
	 * @param \Huexs\GoogleReviews\Google\Dto\ReviewDto[] $reviews
	 */
	public function __construct(
		public readonly array $reviews,
		public readonly ?float $rating,
		public readonly ?int $reviewCount,
		public readonly bool $truncated = false,
		public readonly string $sourceLabel = '',
		public readonly ?string $publicUrl = null,
		public readonly ?string $name = null
	) {}
}
