<?php

declare(strict_types=1);

namespace Huexs\GoogleReviews\Tests\Unit;

use Huexs\GoogleReviews\Google\Dto\ReviewDto;
use Huexs\GoogleReviews\Source\LocationReviews;
use Huexs\GoogleReviews\Source\ReviewSourceInterface;
use Huexs\GoogleReviews\Source\SourceRegistry;
use Huexs\GoogleReviews\Support\Logger;
use Huexs\GoogleReviews\Sync\SyncLock;
use Huexs\GoogleReviews\Sync\SyncResult;
use Huexs\GoogleReviews\Sync\SyncService;
use Huexs\GoogleReviews\Tests\Support\FakeSource;
use Huexs\GoogleReviews\Tests\Support\FrozenClock;
use Huexs\GoogleReviews\Tests\Support\InMemoryLocationRepository;
use Huexs\GoogleReviews\Tests\Support\InMemoryReviewRepository;
use Huexs\GoogleReviews\Tests\Support\InMemorySyncLogRepository;
use PHPUnit\Framework\TestCase;

final class SyncServiceTest extends TestCase {

	private FakeSource $source;
	private InMemoryLocationRepository $locations;
	private InMemoryReviewRepository $reviews;
	private InMemorySyncLogRepository $logs;
	private FrozenClock $clock;
	private SyncService $service;

	protected function setUp(): void {
		hgr_test_reset_state();

		$this->source    = new FakeSource();
		$this->locations = new InMemoryLocationRepository();
		$this->reviews   = new InMemoryReviewRepository();
		$this->logs      = new InMemorySyncLogRepository();
		$this->clock     = new FrozenClock( '2026-08-11 10:00:00' );

		$registry = new SourceRegistry();
		$registry->add( $this->source );

		$this->service = new SyncService(
			$registry,
			$this->locations,
			$this->reviews,
			$this->logs,
			new SyncLock( $this->clock ),
			$this->clock,
			new Logger()
		);
	}

	private function review( string $id, int $stars = 5, string $comment = 'Genial' ): ReviewDto {
		return new ReviewDto( $id, 'Autor ' . $id, null, $stars, $comment, '2026-07-01 10:00:00', null, null, null );
	}

	private function seedLocationWithReviews( int $count = 3 ): object {
		$location = $this->locations->addLocation( array( 'ref_key' => 'place-abc' ) );
		$reviews  = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$reviews[] = $this->review( 'rev-' . $i );
		}
		$this->source->setResult( 'place-abc', new LocationReviews( $reviews, 4.7, 42, false, 'places', 'https://maps.google.com/?cid=1', 'Mi Negocio' ) );
		return $location;
	}

	public function testSyncInsertsAllReviewsAndStoresSummary(): void {
		$this->seedLocationWithReviews( 3 );

		$result = $this->service->run( 'manual' );

		self::assertSame( SyncResult::STATUS_SUCCESS, $result->status );
		self::assertSame( 3, $result->seen );
		self::assertSame( 3, $result->inserted );
		self::assertSame( 3, $this->reviews->countAll() );

		$location = $this->locations->all()[0];
		self::assertSame( 'success', $location->last_sync_status );
		self::assertSame( 4.7, (float) $location->average_rating );
		self::assertSame( 42, (int) $location->total_review_count, 'El total real de Google se conserva aunque lleguen menos reseñas.' );
		self::assertSame( 'places', $location->source_label );
		self::assertSame( 'https://maps.google.com/?cid=1', $location->public_google_url );
	}

	public function testTruncatedFlagIsPersisted(): void {
		$this->locations->addLocation( array( 'ref_key' => 'place-abc' ) );
		$this->source->setResult( 'place-abc', new LocationReviews( array( $this->review( 'r1' ) ), 4.9, 300, true, 'places' ) );

		$this->service->run( 'manual' );

		self::assertSame( 1, (int) $this->locations->all()[0]->truncated );
	}

	public function testCompleteSyncDeletesOnlyUnseenReviews(): void {
		$location = $this->seedLocationWithReviews( 3 );
		$this->reviews->seed( (int) $location->id, 'rev-borrada-en-google' );

		$result = $this->service->run( 'manual' );

		self::assertSame( SyncResult::STATUS_SUCCESS, $result->status );
		self::assertSame( 1, $result->deleted );
		self::assertSame( 3, $this->reviews->countAll() );
		self::assertArrayNotHasKey( $location->id . '|rev-borrada-en-google', $this->reviews->rows );
	}

	public function testFailedFetchNeverDeletesExistingReviews(): void {
		$location = $this->locations->addLocation( array( 'ref_key' => 'place-roto' ) );
		$this->reviews->seed( (int) $location->id, 'rev-preexistente' );
		$this->source->failWith( 'place-roto', 'upstream_error' );

		$result = $this->service->run( 'manual' );

		self::assertSame( SyncResult::STATUS_FAILED, $result->status );
		self::assertSame( 0, $result->deleted );
		self::assertArrayHasKey( $location->id . '|rev-preexistente', $this->reviews->rows, 'Una sync fallida no debe borrar datos.' );
		self::assertSame( 'failed', $this->locations->all()[0]->last_sync_status );
	}

	public function testSecondRunDistinguishesUnchanged(): void {
		$this->seedLocationWithReviews( 3 );
		$this->service->run( 'manual' );

		$this->clock->advance( 'PT1H' );
		$result = $this->service->run( 'manual' );

		self::assertSame( 3, $result->seen );
		self::assertSame( 0, $result->inserted );
		self::assertSame( 0, $result->updated );
	}

	public function testUpdatedReviewIsDetected(): void {
		$location = $this->seedLocationWithReviews( 1 );
		$this->service->run( 'manual' );

		$this->source->setResult( 'place-abc', new LocationReviews( array( $this->review( 'rev-1', 3, 'Texto editado' ) ), 4.7, 42 ) );
		$this->clock->advance( 'PT1H' );
		$result = $this->service->run( 'manual' );

		self::assertSame( 0, $result->inserted );
		self::assertSame( 1, $result->updated );
		self::assertSame( 3, (int) $this->reviews->rows[ $location->id . '|rev-1' ]->star_rating );
	}

	public function testLockedRunIsSkipped(): void {
		$this->seedLocationWithReviews();

		$lock = new SyncLock( $this->clock );
		self::assertTrue( $lock->acquire( 'otro-proceso' ) );

		$result = $this->service->run( 'cron' );

		self::assertSame( SyncResult::STATUS_SKIPPED, $result->status );
		self::assertSame( 0, $this->reviews->countAll() );
		self::assertSame( 'skipped', $this->logs->recent( 1 )[0]->status );
	}

	public function testPartialWhenOneLocationFailsAndAnotherSucceeds(): void {
		$this->seedLocationWithReviews( 3 );
		$this->locations->addLocation( array( 'ref_key' => 'place-roto' ) );
		$this->source->failWith( 'place-roto', 'upstream_error' );

		$result = $this->service->run( 'manual' );

		self::assertSame( SyncResult::STATUS_PARTIAL, $result->status );
		self::assertSame( 1, $result->locationsOk );
		self::assertSame( 1, $result->locationsFailed );
		self::assertSame( 3, $this->reviews->countAll(), 'La ubicación sana conserva sus datos.' );
	}

	public function testLicenseErrorStopsRemainingLocations(): void {
		$this->locations->addLocation( array( 'ref_key' => 'place-1' ) );
		$this->locations->addLocation( array( 'ref_key' => 'place-2' ) );
		$this->source->failWith( 'place-1', 'invalid_license' );

		$result = $this->service->run( 'cron' );

		self::assertSame( SyncResult::STATUS_FAILED, $result->status );
		self::assertSame( 1, $this->source->fetchCalls, 'Un fallo de licencia afecta a todas: no se intenta la siguiente.' );
	}

	public function testRunAlwaysFinishesLogAndReleasesLock(): void {
		$this->locations->addLocation( array( 'ref_key' => 'place-roto' ) );
		$this->source->failWith( 'place-roto', 'upstream_error' );

		$this->service->run( 'cli' );

		$log = $this->logs->recent( 1 )[0];
		self::assertNotNull( $log->finished_at );
		self::assertSame( 'failed', $log->status );

		// El lock quedó liberado: una nueva ejecución no se salta.
		self::assertNotSame( SyncResult::STATUS_SKIPPED, $this->service->run( 'cli' )->status );
	}

	public function testUnknownSourceIsReportedAsLocationError(): void {
		$this->locations->addLocation(
			array(
				'ref_key' => 'place-x',
				'source'  => ReviewSourceInterface::SOURCE_GOOGLE,
			)
		);

		$result = $this->service->run( 'manual' );

		self::assertSame( SyncResult::STATUS_FAILED, $result->status );
		self::assertSame( 'configuration_error', $result->firstErrorCode() );
	}
}
