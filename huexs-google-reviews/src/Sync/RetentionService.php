<?php
/**
 * Retención: purga diaria de contenido no renovado en 30 días y de logs antiguos.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Sync;

use Huexs\GoogleReviews\Repository\ReviewRepositoryInterface;
use Huexs\GoogleReviews\Repository\SyncLogRepositoryInterface;
use Huexs\GoogleReviews\Support\Clock;

class RetentionService {

	public const RETENTION_DAYS = 30;

	public function __construct(
		private ReviewRepositoryInterface $reviews,
		private SyncLogRepositoryInterface $logs,
		private Clock $clock
	) {}

	/** Devuelve el número de reseñas purgadas. */
	public function purge(): int {
		$cutoff = $this->clock->now()
			->sub( new \DateInterval( 'P' . self::RETENTION_DAYS . 'D' ) )
			->format( 'Y-m-d H:i:s' );

		$purged = $this->reviews->purgeLastSeenBefore( $cutoff );
		$this->logs->prune( $cutoff );

		return $purged;
	}
}
