<?php
/**
 * Desactivación: desprogramar eventos y liberar locks. Los datos se conservan.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews;

use Huexs\GoogleReviews\Sync\Scheduler;
use Huexs\GoogleReviews\Sync\SyncLock;

final class Deactivation {

	public static function deactivate(): void {
		Scheduler::clear_events();
		SyncLock::force_release();
	}
}
