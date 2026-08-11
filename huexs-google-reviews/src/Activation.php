<?php
/**
 * Activación: creación/actualización del esquema y programación de eventos.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews;

use Huexs\GoogleReviews\Sync\Scheduler;

final class Activation {

	public const DB_VERSION = '1';

	public static function activate(): void {
		self::create_tables();
		update_option( 'hgr_db_version', self::DB_VERSION, false );
		update_option( 'hgr_plugin_version', HGR_VERSION, false );
		Scheduler::ensure_events( Plugin::settings()['sync_frequency_hours'] );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'hgr_db_version' ) !== self::DB_VERSION ) {
			self::create_tables();
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
				google_account_name varchar(191) NOT NULL,
				google_location_name varchar(191) NOT NULL,
				google_location_id varchar(100) NOT NULL DEFAULT '',
				title varchar(255) NOT NULL DEFAULT '',
				store_code varchar(100) DEFAULT NULL,
				public_google_url text DEFAULT NULL,
				enabled tinyint(1) NOT NULL DEFAULT 0,
				average_rating decimal(3,2) DEFAULT NULL,
				total_review_count int(10) unsigned NOT NULL DEFAULT 0,
				last_synced_at datetime DEFAULT NULL,
				last_sync_status varchar(30) DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY account_location (google_account_name,google_location_name),
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
}
