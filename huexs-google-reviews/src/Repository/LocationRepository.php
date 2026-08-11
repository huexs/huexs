<?php
/**
 * Implementación wpdb del repositorio de ubicaciones.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Repository;

use Huexs\GoogleReviews\Google\Dto\LocationDto;

class LocationRepository implements LocationRepositoryInterface {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'hgr_locations';
	}

	public function findEnabled(): array {
		global $wpdb;
		$t = $this->table();
		return (array) $wpdb->get_results( "SELECT * FROM {$t} WHERE enabled = 1 ORDER BY title ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public function all(): array {
		global $wpdb;
		$t = $this->table();
		return (array) $wpdb->get_results( "SELECT * FROM {$t} ORDER BY google_account_name ASC, title ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public function find( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $row ?: null;
	}

	public function upsertFromGoogle( LocationDto $dto, string $now ): int {
		global $wpdb;
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$this->table()} WHERE google_account_name = %s AND google_location_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$dto->accountName,
				$dto->locationName
			)
		);

		if ( $existing ) {
			$wpdb->update(
				$this->table(),
				array(
					'title'      => $dto->title,
					'store_code' => $dto->storeCode,
					'updated_at' => $now,
				),
				array( 'id' => (int) $existing->id )
			);
			return (int) $existing->id;
		}

		$wpdb->insert(
			$this->table(),
			array(
				'google_account_name'  => $dto->accountName,
				'google_location_name' => $dto->locationName,
				'google_location_id'   => $dto->locationId(),
				'title'                => $dto->title,
				'store_code'           => $dto->storeCode,
				'enabled'              => 0,
				'created_at'           => $now,
				'updated_at'           => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function setEnabled( array $enabledIds, string $now ): void {
		global $wpdb;
		$enabledIds = array_map( 'intval', $enabledIds );
		$t          = $this->table();

		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET enabled = 0, updated_at = %s WHERE enabled = 1", $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $enabledIds ) {
			$placeholders = implode( ',', array_fill( 0, count( $enabledIds ), '%d' ) );
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$t} SET enabled = 1, updated_at = %s WHERE id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL
					array_merge( array( $now ), $enabledIds )
				)
			);
		}
	}

	public function savePublicUrl( int $id, ?string $url, string $now ): void {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			array(
				'public_google_url' => $url,
				'updated_at'        => $now,
			),
			array( 'id' => $id )
		);
	}

	public function updateSyncSummary( int $id, ?float $averageRating, ?int $totalCount, string $status, string $now ): void {
		global $wpdb;
		$data = array(
			'last_synced_at'   => $now,
			'last_sync_status' => $status,
			'updated_at'       => $now,
		);
		if ( null !== $averageRating ) {
			$data['average_rating'] = $averageRating;
		}
		if ( null !== $totalCount ) {
			$data['total_review_count'] = $totalCount;
		}
		$wpdb->update( $this->table(), $data, array( 'id' => $id ) );
	}

	public function markSyncStatus( int $id, string $status, string $now ): void {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			array(
				'last_sync_status' => $status,
				'updated_at'       => $now,
			),
			array( 'id' => $id )
		);
	}

	public function deleteAll(): void {
		global $wpdb;
		$t = $this->table();
		$wpdb->query( "DELETE FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
