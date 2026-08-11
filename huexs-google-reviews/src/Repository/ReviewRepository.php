<?php
/**
 * Implementación wpdb del repositorio de reseñas (caché temporal).
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Repository;

use Huexs\GoogleReviews\Google\Dto\ReviewDto;

class ReviewRepository implements ReviewRepositoryInterface {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'hgr_reviews';
	}

	public function upsert( int $locationId, ReviewDto $review, string $now ): string {
		global $wpdb;
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE location_id = %d AND google_review_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$locationId,
				$review->googleReviewId
			)
		);

		$fields = array(
			'reviewer_name'     => $review->reviewerName,
			'reviewer_photo_url' => $review->reviewerPhotoUrl,
			'star_rating'       => $review->starRating,
			'comment'           => $review->comment,
			'create_time'       => $review->createTime,
			'update_time'       => $review->updateTime,
			'reply_comment'     => $review->replyComment,
			'reply_update_time' => $review->replyUpdateTime,
		);

		if ( ! $existing ) {
			$wpdb->insert(
				$this->table(),
				array_merge(
					$fields,
					array(
						'location_id'      => $locationId,
						'google_review_id' => $review->googleReviewId,
						'fetched_at'       => $now,
						'last_seen_at'     => $now,
						'created_at'       => $now,
						'updated_at'       => $now,
					)
				)
			);
			return self::RESULT_INSERTED;
		}

		$changed = false;
		foreach ( $fields as $key => $value ) {
			$current = $existing->$key;
			if ( (string) ( $current ?? '' ) !== (string) ( $value ?? '' ) ) {
				$changed = true;
				break;
			}
		}

		$update = array(
			'fetched_at'   => $now,
			'last_seen_at' => $now,
		);
		if ( $changed ) {
			$update = array_merge( $update, $fields, array( 'updated_at' => $now ) );
		}
		$wpdb->update( $this->table(), $update, array( 'id' => (int) $existing->id ) );

		return $changed ? self::RESULT_UPDATED : self::RESULT_UNCHANGED;
	}

	public function deleteNotSeenSince( int $locationId, string $seenAt ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table()} WHERE location_id = %d AND last_seen_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$locationId,
				$seenAt
			)
		);
	}

	public function purgeLastSeenBefore( string $cutoff ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$this->table()} WHERE last_seen_at < %s", $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	public function findForDisplay( array $locationIds, string $order, int $limit ): array {
		global $wpdb;
		$locationIds = array_map( 'intval', $locationIds );
		if ( ! $locationIds ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $locationIds ), '%d' ) );
		$direction    = 'oldest' === $order ? 'ASC' : 'DESC';
		$limit        = max( 1, min( 50, $limit ) );

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.*, l.title AS location_title, l.public_google_url
				 FROM {$this->table()} r
				 INNER JOIN {$wpdb->prefix}hgr_locations l ON l.id = r.location_id
				 WHERE r.location_id IN ($placeholders)
				 ORDER BY r.create_time {$direction}
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				array_merge( $locationIds, array( $limit ) )
			)
		);
	}

	public function countAll(): int {
		global $wpdb;
		$t = $this->table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public function deleteByLocation( int $locationId ): void {
		global $wpdb;
		$wpdb->delete( $this->table(), array( 'location_id' => $locationId ) );
	}

	public function deleteAll(): void {
		global $wpdb;
		$t = $this->table();
		$wpdb->query( "DELETE FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
