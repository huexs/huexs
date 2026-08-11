<?php

declare(strict_types=1);

namespace Huexs\GoogleReviews\Tests\Support;

use Huexs\GoogleReviews\Google\Dto\LocationDto;
use Huexs\GoogleReviews\Google\Dto\ReviewDto;
use Huexs\GoogleReviews\Repository\LocationRepositoryInterface;
use Huexs\GoogleReviews\Repository\ReviewRepositoryInterface;
use Huexs\GoogleReviews\Repository\SyncLogRepositoryInterface;

final class InMemoryLocationRepository implements LocationRepositoryInterface {

	/** @var array<int, object> */
	public array $rows = array();

	private int $nextId = 1;

	public function addLocation( array $data ): object {
		$row = (object) array_merge(
			array(
				'id'                   => $this->nextId,
				'google_account_name'  => 'accounts/1',
				'google_location_name' => 'locations/' . $this->nextId,
				'google_location_id'   => (string) $this->nextId,
				'title'                => 'Ubicación ' . $this->nextId,
				'store_code'           => null,
				'public_google_url'    => null,
				'enabled'              => 1,
				'average_rating'       => null,
				'total_review_count'   => 0,
				'last_synced_at'       => null,
				'last_sync_status'     => null,
			),
			$data
		);
		$this->rows[ $row->id ] = $row;
		$this->nextId++;
		return $row;
	}

	public function findEnabled(): array {
		return array_values( array_filter( $this->rows, static fn( $r ) => 1 === (int) $r->enabled ) );
	}

	public function all(): array {
		return array_values( $this->rows );
	}

	public function find( int $id ): ?object {
		return $this->rows[ $id ] ?? null;
	}

	public function upsertFromGoogle( LocationDto $dto, string $now ): int {
		foreach ( $this->rows as $row ) {
			if ( $row->google_account_name === $dto->accountName && $row->google_location_name === $dto->locationName ) {
				$row->title = $dto->title;
				return (int) $row->id;
			}
		}
		$row = $this->addLocation(
			array(
				'google_account_name'  => $dto->accountName,
				'google_location_name' => $dto->locationName,
				'google_location_id'   => $dto->locationId(),
				'title'                => $dto->title,
				'store_code'           => $dto->storeCode,
				'enabled'              => 0,
			)
		);
		return (int) $row->id;
	}

	public function setEnabled( array $enabledIds, string $now ): void {
		foreach ( $this->rows as $row ) {
			$row->enabled = in_array( (int) $row->id, array_map( 'intval', $enabledIds ), true ) ? 1 : 0;
		}
	}

	public function savePublicUrl( int $id, ?string $url, string $now ): void {
		if ( isset( $this->rows[ $id ] ) ) {
			$this->rows[ $id ]->public_google_url = $url;
		}
	}

	public function updateSyncSummary( int $id, ?float $averageRating, ?int $totalCount, string $status, string $now ): void {
		if ( ! isset( $this->rows[ $id ] ) ) {
			return;
		}
		$row                   = $this->rows[ $id ];
		$row->last_synced_at   = $now;
		$row->last_sync_status = $status;
		if ( null !== $averageRating ) {
			$row->average_rating = $averageRating;
		}
		if ( null !== $totalCount ) {
			$row->total_review_count = $totalCount;
		}
	}

	public function markSyncStatus( int $id, string $status, string $now ): void {
		if ( isset( $this->rows[ $id ] ) ) {
			$this->rows[ $id ]->last_sync_status = $status;
		}
	}

	public function deleteAll(): void {
		$this->rows = array();
	}
}
