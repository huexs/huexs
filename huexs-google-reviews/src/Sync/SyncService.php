<?php
/**
 * Servicio de dominio de sincronización, compartido por cron, acción manual y WP-CLI.
 *
 * Regla crítica: una sincronización incompleta o fallida nunca elimina reseñas existentes.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Sync;

use Huexs\GoogleReviews\Google\GoogleApiException;
use Huexs\GoogleReviews\Google\GoogleClientInterface;
use Huexs\GoogleReviews\Repository\LocationRepositoryInterface;
use Huexs\GoogleReviews\Repository\ReviewRepositoryInterface;
use Huexs\GoogleReviews\Repository\SyncLogRepositoryInterface;
use Huexs\GoogleReviews\Support\Clock;
use Huexs\GoogleReviews\Support\Logger;

class SyncService {

	private const MAX_PAGES_PER_LOCATION = 200; // Salvaguarda contra paginación infinita.

	public function __construct(
		private GoogleClientInterface $client,
		private LocationRepositoryInterface $locations,
		private ReviewRepositoryInterface $reviews,
		private SyncLogRepositoryInterface $logs,
		private SyncLock $lock,
		private Clock $clock,
		private Logger $logger
	) {}

	public function run( string $trigger, ?int $onlyLocationId = null ): SyncResult {
		$runId = bin2hex( random_bytes( 8 ) );

		if ( ! $this->lock->acquire( $runId ) ) {
			$result = new SyncResult( SyncResult::STATUS_SKIPPED );
			$logId  = $this->logs->start( $trigger, $onlyLocationId, $this->clock->nowString() );
			$this->logs->finish( $logId, SyncResult::STATUS_SKIPPED, 0, 0, 0, 'locked', 'Ya hay una sincronización en curso.', $this->clock->nowString() );
			return $result;
		}

		$result = new SyncResult();
		$logId  = $this->logs->start( $trigger, $onlyLocationId, $this->clock->nowString() );

		try {
			$targets = $this->locations->findEnabled();
			if ( null !== $onlyLocationId ) {
				$targets = array_values( array_filter( $targets, static fn( $l ) => (int) $l->id === $onlyLocationId ) );
			}

			foreach ( $targets as $location ) {
				try {
					$this->syncLocation( $location, $result );
					$result->locationsOk++;
				} catch ( GoogleApiException $e ) {
					$result->locationsFailed++;
					$result->addError( (int) $location->id, $e->errorCode(), $e->getMessage() );
					$this->locations->markSyncStatus( (int) $location->id, 'failed', $this->clock->nowString() );
					$this->logger->debug( 'Fallo sincronizando ubicación ' . $location->id, array( 'code' => $e->errorCode() ) );

					// Un error de autenticación afecta a todas las ubicaciones: no insistimos.
					if ( in_array( $e->errorCode(), array( 'auth_expired', 'configuration_error' ), true ) ) {
						break;
					}
				}
			}

			$result->resolveStatus();
		} catch ( \Throwable $e ) {
			$result->locationsFailed++;
			$result->addError( null, 'internal_error', $e->getMessage() );
			$result->status = SyncResult::STATUS_FAILED;
		} finally {
			$this->logs->finish(
				$logId,
				$result->status,
				$result->seen,
				$result->inserted,
				$result->updated,
				$result->firstErrorCode(),
				$result->errorSummary(),
				$this->clock->nowString()
			);
			$this->lock->release( $runId );
		}

		return $result;
	}

	/**
	 * @param object $location Fila de la tabla de ubicaciones.
	 * @throws GoogleApiException
	 */
	private function syncLocation( object $location, SyncResult $result ): void {
		$runStamp = $this->clock->nowString();

		$pageToken     = null;
		$pages         = 0;
		$avgRating     = null;
		$totalCount    = null;
		$seenThisRun   = 0;

		do {
			$page = $this->client->listReviewsPage(
				(string) $location->google_account_name,
				(string) $location->google_location_id,
				$pageToken
			);

			foreach ( $page->reviews as $dto ) {
				$outcome = $this->reviews->upsert( (int) $location->id, $dto, $runStamp );
				$seenThisRun++;
				$result->seen++;
				if ( ReviewRepositoryInterface::RESULT_INSERTED === $outcome ) {
					$result->inserted++;
				} elseif ( ReviewRepositoryInterface::RESULT_UPDATED === $outcome ) {
					$result->updated++;
				}
			}

			$avgRating  = $page->averageRating ?? $avgRating;
			$totalCount = $page->totalReviewCount ?? $totalCount;
			$pageToken  = $page->nextPageToken;
			$pages++;

			if ( $pages >= self::MAX_PAGES_PER_LOCATION && null !== $pageToken ) {
				throw new GoogleApiException( 'invalid_response', 'Paginación anómala: demasiadas páginas de reseñas.' );
			}
		} while ( null !== $pageToken );

		// Solo tras completar la paginación es seguro eliminar lo no visto.
		$result->deleted += $this->reviews->deleteNotSeenSince( (int) $location->id, $runStamp );

		$this->locations->updateSyncSummary(
			(int) $location->id,
			$avgRating,
			$totalCount ?? $seenThisRun,
			'success',
			$this->clock->nowString()
		);
	}
}
