<?php

declare(strict_types=1);

namespace Huexs\GoogleReviews\Tests\Support;

use Huexs\GoogleReviews\Google\Dto\LocationDto;
use Huexs\GoogleReviews\Google\Dto\ReviewDto;
use Huexs\GoogleReviews\Repository\LocationRepositoryInterface;
use Huexs\GoogleReviews\Repository\ReviewRepositoryInterface;
use Huexs\GoogleReviews\Repository\SyncLogRepositoryInterface;

final class InMemorySyncLogRepository implements SyncLogRepositoryInterface {

	/** @var array<int, object> */
	public array $rows = array();

	private int $nextId = 1;

	public function start( string $trigger, ?int $locationId, string $now ): int {
		$id                = $this->nextId++;
		$this->rows[ $id ] = (object) array(
			'id'               => $id,
			'trigger_type'     => $trigger,
			'location_id'      => $locationId,
			'started_at'       => $now,
			'finished_at'      => null,
			'status'           => 'running',
			'reviews_seen'     => 0,
			'reviews_inserted' => 0,
			'reviews_updated'  => 0,
			'error_code'       => null,
			'error_message'    => null,
		);
		return $id;
	}

	public function finish( int $id, string $status, int $seen, int $inserted, int $updated, ?string $errorCode, ?string $errorMessage, string $now ): void {
		if ( ! isset( $this->rows[ $id ] ) ) {
			return;
		}
		$row                   = $this->rows[ $id ];
		$row->finished_at      = $now;
		$row->status           = $status;
		$row->reviews_seen     = $seen;
		$row->reviews_inserted = $inserted;
		$row->reviews_updated  = $updated;
		$row->error_code       = $errorCode;
		$row->error_message    = $errorMessage;
	}

	public function recent( int $limit = 10 ): array {
		$rows = array_values( $this->rows );
		usort( $rows, static fn( $a, $b ) => $b->id <=> $a->id );
		return array_slice( $rows, 0, $limit );
	}

	public function prune( string $cutoff ): void {
		foreach ( $this->rows as $id => $row ) {
			if ( $row->started_at < $cutoff ) {
				unset( $this->rows[ $id ] );
			}
		}
		$rows = array_values( $this->rows );
		usort( $rows, static fn( $a, $b ) => $b->id <=> $a->id );
		$keep       = array_slice( $rows, 0, 100 );
		$keepIds    = array_map( static fn( $r ) => $r->id, $keep );
		$this->rows = array_filter( $this->rows, static fn( $r ) => in_array( $r->id, $keepIds, true ) );
	}

	public function deleteAll(): void {
		$this->rows = array();
	}
}
