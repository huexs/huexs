<?php
/**
 * Implementación wpdb del repositorio de ubicaciones.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Repository;

use Huexs\GoogleReviews\Source\BusinessResult;
use Huexs\GoogleReviews\Source\LocationReviews;
use Huexs\GoogleReviews\Source\ReviewSourceInterface;

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
		return (array) $wpdb->get_results( "SELECT * FROM {$t} ORDER BY title ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public function find( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $row ?: null;
	}

	public function countEnabled(): int {
		global $wpdb;
		$t = $this->table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE enabled = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public function upsertFromBusiness( string $source, BusinessResult $business, string $now ): int {
		global $wpdb;

		$refKey = $business->placeId;
		$fields = array(
			'title'   => $business->name,
			'address' => '' !== $business->address ? $business->address : null,
		);

		if ( ReviewSourceInterface::SOURCE_GOOGLE === $source ) {
			// En modo avanzado el identificador es "accounts/X/locations/Y".
			$fields = array_merge( $fields, self::splitGoogleRef( $refKey ) );
		} else {
			$fields['place_id'] = $refKey;
		}

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, enabled FROM {$this->table()} WHERE source = %s AND ref_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$source,
				$refKey
			)
		);

		if ( $existing ) {
			$wpdb->update( $this->table(), array_merge( $fields, array( 'updated_at' => $now ) ), array( 'id' => (int) $existing->id ) );
			return (int) $existing->id;
		}

		$wpdb->insert(
			$this->table(),
			array_merge(
				$fields,
				array(
					'source'             => $source,
					'ref_key'            => $refKey,
					'average_rating'     => $business->rating,
					'total_review_count' => $business->reviewCount ?? 0,
					'enabled'            => 0,
					'created_at'         => $now,
					'updated_at'         => $now,
				)
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function setEnabled( int $id, bool $enabled, string $now ): void {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			array(
				'enabled'    => $enabled ? 1 : 0,
				'updated_at' => $now,
			),
			array( 'id' => $id )
		);
	}

	public function setEnabledSet( array $enabledIds, string $now ): void {
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

	public function updateSyncSummary( int $id, LocationReviews $fetched, string $status, string $now ): void {
		global $wpdb;
		$data = array(
			'truncated'        => $fetched->truncated ? 1 : 0,
			'source_label'     => '' !== $fetched->sourceLabel ? $fetched->sourceLabel : null,
			'last_synced_at'   => $now,
			'last_sync_status' => $status,
			'updated_at'       => $now,
		);
		if ( null !== $fetched->rating ) {
			$data['average_rating'] = $fetched->rating;
		}
		if ( null !== $fetched->reviewCount ) {
			$data['total_review_count'] = $fetched->reviewCount;
		}
		if ( null !== $fetched->name && '' !== $fetched->name ) {
			$data['title'] = $fetched->name;
		}
		// La URL pública configurada a mano por el administrador tiene prioridad sobre la remota.
		if ( null !== $fetched->publicUrl ) {
			$current = $wpdb->get_var( $wpdb->prepare( "SELECT public_google_url FROM {$this->table()} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( null === $current || '' === $current ) {
				$data['public_google_url'] = $fetched->publicUrl;
			}
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

	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( $this->table(), array( 'id' => $id ) );
	}

	public function deleteAll(): void {
		global $wpdb;
		$t = $this->table();
		$wpdb->query( "DELETE FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** "accounts/123/locations/456" → columnas de Google. */
	private static function splitGoogleRef( string $ref ): array {
		$parts = explode( '/locations/', $ref );
		if ( 2 !== count( $parts ) ) {
			return array();
		}
		return array(
			'google_account_name'  => $parts[0],
			'google_location_name' => 'locations/' . $parts[1],
			'google_location_id'   => $parts[1],
		);
	}
}
