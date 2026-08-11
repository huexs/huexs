<?php
/**
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Repository;

use Huexs\GoogleReviews\Source\BusinessResult;
use Huexs\GoogleReviews\Source\LocationReviews;

interface LocationRepositoryInterface {

	/** @return object[] Filas de ubicaciones activadas. */
	public function findEnabled(): array;

	/** @return object[] Todas las filas. */
	public function all(): array;

	public function find( int $id ): ?object;

	public function countEnabled(): int;

	/**
	 * Alta o actualización de una ubicación descubierta en una fuente.
	 * Idempotente por (source, ref_key). Devuelve el id interno.
	 */
	public function upsertFromBusiness( string $source, BusinessResult $business, string $now ): int;

	public function setEnabled( int $id, bool $enabled, string $now ): void;

	/** @param int[] $enabledIds */
	public function setEnabledSet( array $enabledIds, string $now ): void;

	public function savePublicUrl( int $id, ?string $url, string $now ): void;

	/** Guarda el resumen devuelto por la fuente tras una sincronización correcta. */
	public function updateSyncSummary( int $id, LocationReviews $fetched, string $status, string $now ): void;

	public function markSyncStatus( int $id, string $status, string $now ): void;

	public function delete( int $id ): void;

	public function deleteAll(): void;
}
