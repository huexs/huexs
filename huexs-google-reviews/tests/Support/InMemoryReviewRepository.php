<?php

declare(strict_types=1);

namespace Huexs\GoogleReviews\Tests\Support;

use Huexs\GoogleReviews\Google\Dto\LocationDto;
use Huexs\GoogleReviews\Google\Dto\ReviewDto;
use Huexs\GoogleReviews\Repository\LocationRepositoryInterface;
use Huexs\GoogleReviews\Repository\ReviewRepositoryInterface;
use Huexs\GoogleReviews\Repository\SyncLogRepositoryInterface;

final class InMemoryReviewRepository implements ReviewRepositoryInterface {

	/** @var array<string, object> Clave "locationId|googleReviewId". */
	public array $rows = array();

	public function seed( int $locationId, string $googleReviewId, array $data = array() ): object {
		$row = (object) array_merge(
			array(
				'location_id'        => $locationId,
				'google_review_id'   => $googleReviewId,
				'reviewer_name'      => 'Autor',
				'reviewer_photo_url' => null,
				'star_rating'        => 5,
				'comment'            => 'Comentario',
				'create_time'        => '2026-01-01 00:00:00',
				'update_time'        => null,
				'reply_comment'      => null,
				'reply_update_time'  => null,
				'fetched_at'         => '2026-01-01 00:00:00',
				'last_seen_at'       => '2026-01-01 00:00:00',
			),
			$data
		);
		$this->rows[ $locationId . '|' . $googleReviewId ] = $row;
		return $row;
	}

	public function upsert( int $locationId, ReviewDto $review, string $now ): string {
		$key    = $locationId . '|' . $review->googleReviewId;
		$fields = array(
			'reviewer_name'      => $review->reviewerName,
			'reviewer_photo_url' => $review->reviewerPhotoUrl,
			'star_rating'        => $review->starRating,
			'comment'            => $review->comment,
			'create_time'        => $review->createTime,
			'update_time'        => $review->updateTime,
			'reply_comment'      => $review->replyComment,
			'reply_update_time'  => $review->replyUpdateTime,
		);

		if ( ! isset( $this->rows[ $key ] ) ) {
			$this->rows[ $key ] = (object) array_merge(
				$fields,
				array(
					'location_id'      => $locationId,
					'google_review_id' => $review->googleReviewId,
					'fetched_at'       => $now,
					'last_seen_at'     => $now,
				)
			);
			return self::RESULT_INSERTED;
		}

		$row     = $this->rows[ $key ];
		$changed = false;
		foreach ( $fields as $field => $value ) {
			if ( (string) ( $row->$field ?? '' ) !== (string) ( $value ?? '' ) ) {
				$changed = true;
			}
		}
		$row->fetched_at   = $now;
		$row->last_seen_at = $now;
		if ( $changed ) {
			foreach ( $fields as $field => $value ) {
				$row->$field = $value;
			}
			return self::RESULT_UPDATED;
		}
		return self::RESULT_UNCHANGED;
	}

	public function deleteNotSeenSince( int $locationId, string $seenAt ): int {
		$deleted = 0;
		foreach ( $this->rows as $key => $row ) {
			if ( (int) $row->location_id === $locationId && $row->last_seen_at < $seenAt ) {
				unset( $this->rows[ $key ] );
				$deleted++;
			}
		}
		return $deleted;
	}

	public function purgeLastSeenBefore( string $cutoff ): int {
		$purged = 0;
		foreach ( $this->rows as $key => $row ) {
			if ( $row->last_seen_at < $cutoff ) {
				unset( $this->rows[ $key ] );
				$purged++;
			}
		}
		return $purged;
	}

	public function findForDisplay( array $locationIds, string $order, int $limit ): array {
		$rows = array_values(
			array_filter( $this->rows, static fn( $r ) => in_array( (int) $r->location_id, array_map( 'intval', $locationIds ), true ) )
		);
		usort(
			$rows,
			static fn( $a, $b ) => 'oldest' === $order
				? strcmp( (string) $a->create_time, (string) $b->create_time )
				: strcmp( (string) $b->create_time, (string) $a->create_time )
		);
		return array_slice( $rows, 0, max( 1, min( 50, $limit ) ) );
	}

	public function countAll(): int {
		return count( $this->rows );
	}

	public function deleteByLocation( int $locationId ): void {
		foreach ( $this->rows as $key => $row ) {
			if ( (int) $row->location_id === $locationId ) {
				unset( $this->rows[ $key ] );
			}
		}
	}

	public function deleteAll(): void {
		$this->rows = array();
	}
}
