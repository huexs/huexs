<?php
/**
 * Implementación wpdb del registro de sincronizaciones.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Repository;

use Huexs\GoogleReviews\Support\Logger;

class SyncLogRepository implements SyncLogRepositoryInterface {

	private const MAX_ROWS = 100;

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'hgr_sync_logs';
	}

	public function start( string $trigger, ?int $locationId, string $now ): int {
		global $wpdb;
		$wpdb->insert(
			$this->table(),
			array(
				'trigger_type' => $trigger,
				'location_id'  => $locationId,
				'started_at'   => $now,
				'status'       => 'running',
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function finish( int $id, string $status, int $seen, int $inserted, int $updated, ?string $errorCode, ?string $errorMessage, string $now ): void {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			array(
				'finished_at'      => $now,
				'status'           => $status,
				'reviews_seen'     => $seen,
				'reviews_inserted' => $inserted,
				'reviews_updated'  => $updated,
				'error_code'       => $errorCode,
				'error_message'    => null !== $errorMessage ? mb_substr( Logger::redact( $errorMessage ), 0, 1000 ) : null,
			),
			array( 'id' => $id )
		);
	}

	public function recent( int $limit = 10 ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table()} ORDER BY started_at DESC, id DESC LIMIT %d", $limit ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	public function prune( string $cutoff ): void {
		global $wpdb;
		$t = $this->table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE started_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$t} WHERE id NOT IN ( SELECT id FROM ( SELECT id FROM {$t} ORDER BY started_at DESC, id DESC LIMIT %d ) keep )", // phpcs:ignore WordPress.DB.PreparedSQL
				self::MAX_ROWS
			)
		);
	}

	public function deleteAll(): void {
		global $wpdb;
		$t = $this->table();
		$wpdb->query( "DELETE FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
