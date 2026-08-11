<?php
/**
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Repository;

interface SyncLogRepositoryInterface {

	public function start( string $trigger, ?int $locationId, string $now ): int;

	public function finish( int $id, string $status, int $seen, int $inserted, int $updated, ?string $errorCode, ?string $errorMessage, string $now ): void;

	/** @return object[] */
	public function recent( int $limit = 10 ): array;

	/** Mantiene solo los últimos 100 registros o 30 días. */
	public function prune( string $cutoff ): void;

	public function deleteAll(): void;
}
