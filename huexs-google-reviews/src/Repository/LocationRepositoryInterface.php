<?php
/**
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Repository;

use Huexs\GoogleReviews\Google\Dto\LocationDto;

interface LocationRepositoryInterface {

	/** @return object[] Filas de ubicaciones activadas. */
	public function findEnabled(): array;

	/** @return object[] Todas las filas. */
	public function all(): array;

	public function find( int $id ): ?object;

	/** Inserta o actualiza la ubicación descubierta en Google. Devuelve el id interno. */
	public function upsertFromGoogle( LocationDto $dto, string $now ): int;

	/** @param int[] $enabledIds */
	public function setEnabled( array $enabledIds, string $now ): void;

	public function savePublicUrl( int $id, ?string $url, string $now ): void;

	public function updateSyncSummary( int $id, ?float $averageRating, ?int $totalCount, string $status, string $now ): void;

	public function markSyncStatus( int $id, string $status, string $now ): void;

	public function deleteAll(): void;
}
