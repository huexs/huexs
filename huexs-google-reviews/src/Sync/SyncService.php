<?php
/**
 * Servicio de dominio de sincronización, compartido por cron, acción manual y WP-CLI.
 *
 * Es agnóstico de la fuente: cada ubicación se sincroniza con la fuente con la que
 * fue dada de alta (API de Huexs o proyecto propio de Google).
 *
 * Regla crítica: una sincronización incompleta o fallida nunca elimina reseñas existentes.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Sync;

use Huexs\GoogleReviews\Repository\LocationRepositoryInterface;
use Huexs\GoogleReviews\Repository\ReviewRepositoryInterface;
use Huexs\GoogleReviews\Repository\SyncLogRepositoryInterface;
use Huexs\GoogleReviews\Source\SourceException;
use Huexs\GoogleReviews\Source\SourceRegistry;
use Huexs\GoogleReviews\Support\Clock;
use Huexs\GoogleReviews\Support\Logger;

class SyncService {

	public function __construct(
		private SourceRegistry $sources,
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
			$logId = $this->logs->start( $trigger, $onlyLocationId, $this->clock->nowString() );
			$this->logs->finish( $logId, SyncResult::STATUS_SKIPPED, 0, 0, 0, 'locked', 'Ya hay una sincronización en curso.', $this->clock->nowString() );
			return new SyncResult( SyncResult::STATUS_SKIPPED );
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
				} catch ( SourceException $e ) {
					$result->locationsFailed++;
					$result->addError( (int) $location->id, $e->errorCode(), $e->getMessage() );
					$this->locations->markSyncStatus( (int) $location->id, 'failed', $this->clock->nowString() );
					$this->logger->debug( 'Fallo sincronizando ubicación ' . $location->id, array( 'code' => $e->errorCode() ) );

					// Estos errores afectan a todas las ubicaciones de la misma fuente: no insistimos.
					if ( in_array( $e->errorCode(), array( 'auth_expired', 'invalid_license', 'configuration_error' ), true ) ) {
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
	 * @throws SourceException
	 */
	private function syncLocation( object $location, SyncResult $result ): void {
		$source = $this->sources->get( (string) ( $location->source ?? '' ) );

		// Si la fuente no completa la lectura lanza excepción y no llegamos al borrado.
		$fetched  = $source->fetchReviews( $location );
		$runStamp = $this->clock->nowString();

		foreach ( $fetched->reviews as $dto ) {
			$outcome = $this->reviews->upsert( (int) $location->id, $dto, $runStamp );
			$result->seen++;
			if ( ReviewRepositoryInterface::RESULT_INSERTED === $outcome ) {
				$result->inserted++;
			} elseif ( ReviewRepositoryInterface::RESULT_UPDATED === $outcome ) {
				$result->updated++;
			}
		}

		// Solo tras una lectura completa es seguro eliminar lo que ya no existe en origen.
		$result->deleted += $this->reviews->deleteNotSeenSince( (int) $location->id, $runStamp );

		$this->locations->updateSyncSummary( (int) $location->id, $fetched, 'success', $this->clock->nowString() );
	}
}
