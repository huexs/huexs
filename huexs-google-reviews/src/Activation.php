<?php
/**
 * Activación: creación/actualización del esquema y programación de eventos.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews;

use Huexs\GoogleReviews\Source\ReviewSourceInterface;
use Huexs\GoogleReviews\Sync\Scheduler;

final class Activation {

	public const DB_VERSION = '2';

	public static function activate(): void {
		self::create_tables();
		self::migrate();
		update_option( 'hgr_db_version', self::DB_VERSION, false );
		update_option( 'hgr_plugin_version', HGR_VERSION, false );
		Scheduler::ensure_events( Plugin::settings()['sync_frequency_hours'] );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'hgr_db_version' ) !== self::DB_VERSION ) {
			self::create_tables();
			self::migrate();
			update_option( 'hgr_db_version', self::DB_VERSION, false );
		}
		if ( get_option( 'hgr_plugin_version' ) !== HGR_VERSION ) {
			update_option( 'hgr_plugin_version', HGR_VERSION, false );
		}
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		dbDelta(
			"CREATE TABLE {$p}hgr_locations (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source varchar(20) NOT NULL DEFAULT 'huexs',
				ref_key varchar(191) NOT NULL DEFAULT '',
				place_id varchar(191) DEFAULT NULL,
				google_account_name varchar(191) DEFAULT NULL,
				google_location_name varchar(191) DEFAULT NULL,
				google_location_id varchar(100) DEFAULT NULL,
				title varchar(255) NOT NULL DEFAULT '',
				address varchar(255) DEFAULT NULL,
				store_code varchar(100) DEFAULT NULL,
				public_google_url text DEFAULT NULL,
				enabled tinyint(1) NOT NULL DEFAULT 0,
				average_rating decimal(3,2) DEFAULT NULL,
				total_review_count int(10) unsigned NOT NULL DEFAULT 0,
				truncated tinyint(1) NOT NULL DEFAULT 0,
				source_label varchar(30) DEFAULT NULL,
				last_synced_at datetime DEFAULT NULL,
				last_sync_status varchar(30) DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source_ref (source,ref_key),
				KEY enabled (enabled)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$p}hgr_reviews (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				location_id bigint(20) unsigned NOT NULL,
				google_review_id varchar(191) NOT NULL,
				reviewer_name varchar(255) DEFAULT NULL,
				reviewer_photo_url text DEFAULT NULL,
				star_rating tinyint(3) unsigned NOT NULL,
				comment longtext DEFAULT NULL,
				create_time datetime DEFAULT NULL,
				update_time datetime DEFAULT NULL,
				reply_comment longtext DEFAULT NULL,
				reply_update_time datetime DEFAULT NULL,
				fetched_at datetime NOT NULL,
				last_seen_at datetime NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY location_review (location_id,google_review_id),
				KEY location_created (location_id,create_time),
				KEY last_seen (last_seen_at)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$p}hgr_sync_logs (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				trigger_type varchar(20) NOT NULL,
				location_id bigint(20) unsigned DEFAULT NULL,
				started_at datetime NOT NULL,
				finished_at datetime DEFAULT NULL,
				status varchar(20) NOT NULL DEFAULT 'running',
				reviews_seen int(10) unsigned NOT NULL DEFAULT 0,
				reviews_inserted int(10) unsigned NOT NULL DEFAULT 0,
				reviews_updated int(10) unsigned NOT NULL DEFAULT 0,
				error_code varchar(100) DEFAULT NULL,
				error_message text DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY started (started_at)
			) $charset;"
		);
	}

	/**
	 * Migración v1 → v2.
	 *
	 * dbDelta no elimina índices obsoletos ni rellena columnas nuevas: se hace aquí.
	 * Las instalaciones de la 0.1.0 usaban exclusivamente OAuth propio.
	 */
	private static function migrate(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'hgr_locations';

		// El índice único original (cuenta + ubicación) queda sustituido por (source, ref_key).
		$indexes = (array) $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'account_location'" ); // phpcs:ignore WordPress.DB
		if ( $indexes ) {
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX account_location" ); // phpcs:ignore WordPress.DB
		}

		// Filas heredadas: eran todas de OAuth propio y su referencia estable es el nombre de ubicación.
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$table}
				 SET source = %s, ref_key = google_location_name
				 WHERE ref_key = '' AND google_location_name IS NOT NULL AND google_location_name <> ''",
				ReviewSourceInterface::SOURCE_GOOGLE
			)
		);
	}
}
