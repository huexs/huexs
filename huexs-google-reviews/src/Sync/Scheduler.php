<?php
/**
 * Programación WP-Cron: evento único de sincronización + purga diaria.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Sync;

use Huexs\GoogleReviews\Plugin;

class Scheduler {

	public const SYNC_EVENT  = 'hgr_sync_event';
	public const PURGE_EVENT = 'hgr_purge_event';

	public const ALLOWED_HOURS = array( 6, 12, 24 );

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( self::SYNC_EVENT, array( $this, 'run_sync' ) );
		add_action( self::PURGE_EVENT, array( $this, 'run_purge' ) );
		add_action( 'init', array( $this, 'ensure' ) );
	}

	public static function add_schedules( array $schedules ): array {
		foreach ( self::ALLOWED_HOURS as $hours ) {
			$schedules[ 'hgr_every_' . $hours . 'h' ] = array(
				'interval' => $hours * HOUR_IN_SECONDS,
				'display'  => sprintf( 'Cada %d horas (Huexs Google Reviews)', $hours ),
			);
		}
		return $schedules;
	}

	public function ensure(): void {
		self::ensure_events( Plugin::settings()['sync_frequency_hours'] );
	}

	public static function ensure_events( int $frequencyHours ): void {
		if ( ! in_array( $frequencyHours, self::ALLOWED_HOURS, true ) ) {
			$frequencyHours = 6;
		}
		if ( ! wp_next_scheduled( self::SYNC_EVENT ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hgr_every_' . $frequencyHours . 'h', self::SYNC_EVENT );
		}
		if ( ! wp_next_scheduled( self::PURGE_EVENT ) ) {
			wp_schedule_event( time() + 2 * MINUTE_IN_SECONDS, 'daily', self::PURGE_EVENT );
		}
	}

	/** Reprograma el evento al cambiar la frecuencia, sin duplicados. */
	public static function reschedule( int $frequencyHours ): void {
		$timestamp = wp_next_scheduled( self::SYNC_EVENT );
		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::SYNC_EVENT );
			$timestamp = wp_next_scheduled( self::SYNC_EVENT );
		}
		self::ensure_events( $frequencyHours );
	}

	public static function clear_events(): void {
		wp_clear_scheduled_hook( self::SYNC_EVENT );
		wp_clear_scheduled_hook( self::PURGE_EVENT );
	}

	public function run_sync(): void {
		$this->plugin->syncService()->run( 'cron' );
	}

	public function run_purge(): void {
		$this->plugin->retention()->purge();
	}
}
