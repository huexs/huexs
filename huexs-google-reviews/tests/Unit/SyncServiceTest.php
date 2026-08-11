<?php

declare(strict_types=1);

namespace Huexs\GoogleReviews\Tests\Unit;

use Huexs\GoogleReviews\Support\Logger;
use Huexs\GoogleReviews\Sync\SyncLock;
use Huexs\GoogleReviews\Sync\SyncResult;
use Huexs\GoogleReviews\Sync\SyncService;
use Huexs\GoogleReviews\Tests\Support\FakeGoogleClient;
use Huexs\GoogleReviews\Tests\Support\FrozenClock;
use Huexs\GoogleReviews\Tests\Support\InMemoryLocationRepository;
use Huexs\GoogleReviews\Tests\Support\InMemoryReviewRepository;
use Huexs\GoogleReviews\Tests\Support\InMemorySyncLogRepository;
use PHPUnit\Framework\TestCase;

final class SyncServiceTest extends TestCase {

	private FakeGoogleClient $client;
	private InMemoryLocationRepository $locations;
	private InMemoryReviewRepository $reviews;
	private InMemorySyncLogRepository $logs;
	private FrozenClock $clock;
	private SyncService $service;

	protected function setUp(): void {
		hgr_test_reset_state();
		$this->client    = new FakeGoogleClient();
		$this->locations = new InMemoryLocationRepository();
		$this->reviews   = new InMemoryReviewRepository();
		$this->logs      = new InMemorySyncLogRepository();
		$this->clock     = new FrozenClock( '2026-08-11 10:00:00' );
		$this->service   = new SyncService(
			$this->client,
			$this->locations,
			$this->reviews,
			$this->logs,
			new SyncLock( $this->clock ),
			$this->clock,
			new Logger()
		);
	}

	private function seedLocationWithFixturePages(): object {
		$location = $this->locations->addLocation(
			array(
				'google_account_name' => 'accounts/1',
				'google_location_id'  => '10',
			)
		);
		$this->client->loadReviewPagesFromFixtures(
			'accounts/1',
			'10',
			array(
				__DIR__ . '/../Fixtures/reviews-page-1.json',
				__DIR__ . '/../Fixtures/reviews-page-2.json',
			)
		);
		return $location;
	}

	public function testFullPaginatedSyncInsertsAllReviews(): void {
		$this->seedLocationWithFixturePages();

		$result = $this->service->run( 'manual' );

		self::assertSame( SyncResult::STATUS_SUCCESS, $result->status );
		self::assertSame( 5, $result->seen );
		self::assertSame( 5, $result->inserted );
		self::assertSame( 0, $result->updated );
		self::assertSame( 5, $this->reviews->countAll() );
		self::assertSame( 2, $this->client->reviewRequests, 'Debe recorrer las dos páginas.' );

		$location = $this->locations->all()[0];
		self::assertSame( 'success', $location->last_sync_status );
		self::assertSame( 4.4, (float) $location->average_rating );
		self::assertSame( 5, (int) $location->total_review_count );
	}

	public function testCompleteSyncDeletesOnlyUnseenReviews(): void {
		$location = $this->seedLocationWithFixturePages();

		// Reseña antigua que Google ya no devuelve: debe eliminarse tras un recorrido completo.
		$this->reviews->seed( (int) $location->id, 'rev-borrada-en-google' );

		$result = $this->service->run( 'manual' );

		self::assertSame( SyncResult::STATUS_SUCCESS, $result->status );
		self::assertSame( 1, $result->deleted );
		self::assertSame( 5, $this->reviews->countAll() );
		self::assertArrayNotHasKey( $location->id . '|rev-borrada-en-google', $this->reviews->rows );
	}

	public function testFailedPaginationNeverDeletesExistingReviews(): void {
		$location = $this->seedLocationWithFixturePages();
		$this->reviews->seed( (int) $location->id, 'rev-preexistente' );

		// La segunda página falla: la sincronización queda incompleta.
		$this->client->failAt( 'accounts/1', '10', 1 );

		$result = $this->service->run( 'manual' );

		self::assertSame( SyncResult::STATUS_FAILED, $result->status );
		self::assertSame( 0, $result->deleted );
		self::assertArrayHasKey( $location->id . '|rev-preexistente', $this->reviews->rows, 'Una sync incompleta no debe borrar datos.' );
		self::assertSame( 'failed', $this->locations->all()[0]->last_sync_status );
		self::assertNotEmpty( $result->errors );
	}

	public function testSecondRunDistinguishesUpdatedAndUnchanged(): void {
		$this->seedLocationWithFixturePages();
		$this->service->run( 'manual' );

		// Segunda pasada: mismos datos → nada insertado ni actualizado.
		$this->clock->advance( 'PT1H' );
		$result = $this->service->run( 'manual' );

		self::assertSame( 5, $result->seen );
		self::assertSame( 0, $result->inserted );
		self::assertSame( 0, $result->updated );
	}

	public function testLockedRunIsSkipped(): void {
		$this->seedLocationWithFixturePages();

		$lock = new SyncLock( $this->clock );
		self::assertTrue( $lock->acquire( 'otro-proceso' ) );

		$result = $this->service->run( 'cron' );

		self::assertSame( SyncResult::STATUS_SKIPPED, $result->status );
		self::assertSame( 0, $this->reviews->countAll() );

		$log = $this->logs->recent( 1 )[0];
		self::assertSame( 'skipped', $log->status );
	}

	public function testPartialWhenOneLocationFailsAndAnotherSucceeds(): void {
		$this->seedLocationWithFixturePages();
		$this->locations->addLocation(
			array(
				'google_account_name' => 'accounts/1',
				'google_location_id'  => '20',
			)
		);
		$this->client->setReviewPages( 'accounts/1', '20', array() );
		$this->client->failAt( 'accounts/1', '20', 0 );

		$result = $this->service->run( 'manual' );

		self::assertSame( SyncResult::STATUS_PARTIAL, $result->status );
		self::assertSame( 1, $result->locationsOk );
		self::assertSame( 1, $result->locationsFailed );
		self::assertSame( 5, $this->reviews->countAll(), 'La ubicación sana conserva sus datos.' );
	}

	public function testRunAlwaysFinishesLogAndReleasesLock(): void {
		$this->seedLocationWithFixturePages();
		$this->client->failAt( 'accounts/1', '10', 0 );

		$this->service->run( 'cli' );

		$log = $this->logs->recent( 1 )[0];
		self::assertNotNull( $log->finished_at );
		self::assertSame( 'failed', $log->status );

		// El lock quedó liberado: una nueva ejecución no se salta.
		$this->client->failAt( 'accounts/1', '10', -1 );
		$result = $this->service->run( 'cli' );
		self::assertNotSame( SyncResult::STATUS_SKIPPED, $result->status );
	}
}
