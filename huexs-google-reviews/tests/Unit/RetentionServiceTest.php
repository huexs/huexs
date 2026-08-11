<?php

declare(strict_types=1);

namespace Huexs\GoogleReviews\Tests\Unit;

use Huexs\GoogleReviews\Sync\RetentionService;
use Huexs\GoogleReviews\Tests\Support\FrozenClock;
use Huexs\GoogleReviews\Tests\Support\InMemoryReviewRepository;
use Huexs\GoogleReviews\Tests\Support\InMemorySyncLogRepository;
use PHPUnit\Framework\TestCase;

final class RetentionServiceTest extends TestCase {

	public function testPurgesOnlyReviewsOlderThanThirtyDays(): void {
		$reviews = new InMemoryReviewRepository();
		$logs    = new InMemorySyncLogRepository();
		$clock   = new FrozenClock( '2026-08-11 10:00:00' );

		$reviews->seed( 1, 'reciente', array( 'last_seen_at' => '2026-08-01 00:00:00' ) );
		$reviews->seed( 1, 'al-limite', array( 'last_seen_at' => '2026-07-13 00:00:00' ) );
		$reviews->seed( 1, 'caducada', array( 'last_seen_at' => '2026-07-01 00:00:00' ) );
		$reviews->seed( 1, 'muy-caducada', array( 'last_seen_at' => '2026-01-01 00:00:00' ) );

		$purged = ( new RetentionService( $reviews, $logs, $clock ) )->purge();

		self::assertSame( 2, $purged );
		self::assertArrayHasKey( '1|reciente', $reviews->rows );
		self::assertArrayHasKey( '1|al-limite', $reviews->rows );
		self::assertArrayNotHasKey( '1|caducada', $reviews->rows );
		self::assertArrayNotHasKey( '1|muy-caducada', $reviews->rows );
	}

	public function testPrunesOldLogs(): void {
		$reviews = new InMemoryReviewRepository();
		$logs    = new InMemorySyncLogRepository();
		$clock   = new FrozenClock( '2026-08-11 10:00:00' );

		$oldId    = $logs->start( 'cron', null, '2026-06-01 00:00:00' );
		$recentId = $logs->start( 'cron', null, '2026-08-10 00:00:00' );

		( new RetentionService( $reviews, $logs, $clock ) )->purge();

		$remaining = array_map( static fn( $l ) => $l->id, $logs->recent( 100 ) );
		self::assertNotContains( $oldId, $remaining );
		self::assertContains( $recentId, $remaining );
	}
}
