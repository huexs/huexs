<?php

declare(strict_types=1);

namespace Huexs\GoogleReviews\Tests\Support;

use Huexs\GoogleReviews\Repository\LocationRepositoryInterface;
use Huexs\GoogleReviews\Source\BusinessResult;
use Huexs\GoogleReviews\Source\LocationReviews;
use Huexs\GoogleReviews\Source\ReviewSourceInterface;

final class InMemoryLocationRepository implements LocationRepositoryInterface {

	/** @var array<int, object> */
	public array $rows = array();

	private int $nextId = 1;

	public function addLocation( array $data = array() ): object {
		$id  = $this->nextId;
		$row = (object) array_merge(
			array(
				'id'                   => $id,
				'source'               => ReviewSourceInterface::SOURCE_HUEXS,
				'ref_key'              => 'place-' . $id,
				'place_id'             => 'place-' . $id,
				'google_account_name'  => null,
				'google_location_name' => null,
				'google_location_id'   => null,
				'title'                => 'Negocio ' . $id,
				'address'              => null,
				'store_code'           => null,
				'public_google_url'    => null,
				'enabled'              => 1,
				'average_rating'       => null,
				'total_review_count'   => 0,
				'truncated'            => 0,
				'source_label'         => null,
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

	public function countEnabled(): int {
		return count( $this->findEnabled() );
	}

	public function upsertFromBusiness( string $source, BusinessResult $business, string $now ): int {
		foreach ( $this->rows as $row ) {
			if ( $row->source === $source && $row->ref_key === $business->placeId ) {
				$row->title = $business->name;
				return (int) $row->id;
			}
		}
		$row = $this->addLocation(
			array(
				'source'             => $source,
				'ref_key'            => $business->placeId,
				'place_id'           => ReviewSourceInterface::SOURCE_HUEXS === $source ? $business->placeId : null,
				'title'              => $business->name,
				'address'            => '' !== $business->address ? $business->address : null,
				'average_rating'     => $business->rating,
				'total_review_count' => $business->reviewCount ?? 0,
				'enabled'            => 0,
			)
		);
		return (int) $row->id;
	}

	public function setEnabled( int $id, bool $enabled, string $now ): void {
		if ( isset( $this->rows[ $id ] ) ) {
			$this->rows[ $id ]->enabled = $enabled ? 1 : 0;
		}
	}

	public function setEnabledSet( array $enabledIds, string $now ): void {
		foreach ( $this->rows as $row ) {
			$row->enabled = in_array( (int) $row->id, array_map( 'intval', $enabledIds ), true ) ? 1 : 0;
		}
	}

	public function savePublicUrl( int $id, ?string $url, string $now ): void {
		if ( isset( $this->rows[ $id ] ) ) {
			$this->rows[ $id ]->public_google_url = $url;
		}
	}

	public function updateSyncSummary( int $id, LocationReviews $fetched, string $status, string $now ): void {
		if ( ! isset( $this->rows[ $id ] ) ) {
			return;
		}
		$row                   = $this->rows[ $id ];
		$row->last_synced_at   = $now;
		$row->last_sync_status = $status;
		$row->truncated        = $fetched->truncated ? 1 : 0;
		$row->source_label     = '' !== $fetched->sourceLabel ? $fetched->sourceLabel : null;
		if ( null !== $fetched->rating ) {
			$row->average_rating = $fetched->rating;
		}
		if ( null !== $fetched->reviewCount ) {
			$row->total_review_count = $fetched->reviewCount;
		}
		if ( null !== $fetched->publicUrl && empty( $row->public_google_url ) ) {
			$row->public_google_url = $fetched->publicUrl;
		}
	}

	public function markSyncStatus( int $id, string $status, string $now ): void {
		if ( isset( $this->rows[ $id ] ) ) {
			$this->rows[ $id ]->last_sync_status = $status;
		}
	}

	public function delete( int $id ): void {
		unset( $this->rows[ $id ] );
	}

	public function deleteAll(): void {
		$this->rows = array();
	}
}
