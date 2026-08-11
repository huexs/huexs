<?php

declare(strict_types=1);

namespace Huexs\GoogleReviews\Tests\Unit;

use Huexs\GoogleReviews\Sync\SyncLock;
use Huexs\GoogleReviews\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class SyncLockTest extends TestCase {

	protected function setUp(): void {
		hgr_test_reset_state();
	}

	public function testAcquireAndRelease(): void {
		$lock = new SyncLock( new FrozenClock() );

		self::assertTrue( $lock->acquire( 'run-a' ) );
		self::assertTrue( $lock->isLocked() );

		$lock->release( 'run-a' );
		self::assertFalse( $lock->isLocked() );
	}

	public function testConcurrentAcquireFails(): void {
		$clock = new FrozenClock();
		$lock  = new SyncLock( $clock );

		self::assertTrue( $lock->acquire( 'run-a' ) );
		self::assertFalse( $lock->acquire( 'run-b' ), 'Un segundo proceso no debe adquirir un lock activo.' );
	}

	public function testStaleLockIsRecovered(): void {
		$clock = new FrozenClock();
		$lock  = new SyncLock( $clock );

		self::assertTrue( $lock->acquire( 'run-a' ) );

		// Pasan 21 minutos: el lock queda huérfano y debe poder recuperarse.
		$clock->advance( 'PT21M' );
		self::assertFalse( $lock->isLocked() );
		self::assertTrue( $lock->acquire( 'run-b' ) );
	}

	public function testReleaseByNonOwnerKeepsLock(): void {
		$lock = new SyncLock( new FrozenClock() );

		self::assertTrue( $lock->acquire( 'run-a' ) );
		$lock->release( 'run-otro' );
		self::assertTrue( $lock->isLocked(), 'Solo el propietario puede liberar un lock vigente.' );
	}
}
