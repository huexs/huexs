<?php
/**
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Repository;

use Huexs\GoogleReviews\Google\Dto\ReviewDto;

interface ReviewRepositoryInterface {

	public const RESULT_INSERTED  = 'inserted';
	public const RESULT_UPDATED   = 'updated';
	public const RESULT_UNCHANGED = 'unchanged';

	/**
	 * Upsert por (location_id, google_review_id). Siempre refresca last_seen_at.
	 *
	 * @return string Uno de RESULT_*.
	 */
	public function upsert( int $locationId, ReviewDto $review, string $now ): string;

	/** Elimina reseñas de la ubicación no vistas en esta ejecución. Devuelve filas borradas. */
	public function deleteNotSeenSince( int $locationId, string $seenAt ): int;

	/** Purga reseñas con last_seen_at anterior al corte (retención 30 días). */
	public function purgeLastSeenBefore( string $cutoff ): int;

	/**
	 * Reseñas para el frontend.
	 *
	 * @param int[]  $locationIds
	 * @param string $order 'newest'|'oldest'
	 * @return object[]
	 */
	public function findForDisplay( array $locationIds, string $order, int $limit ): array;

	public function countAll(): int;

	public function deleteByLocation( int $locationId ): void;

	public function deleteAll(): void;
}
