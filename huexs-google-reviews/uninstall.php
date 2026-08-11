<?php
/**
 * Desinstalación. Solo borra datos si el administrador activó la opción explícita.
 *
 * @package Huexs\GoogleReviews
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$hgr_settings = get_option( 'hgr_settings', array() );
$hgr_delete   = is_array( $hgr_settings ) && ! empty( $hgr_settings['delete_on_uninstall'] );

if ( $hgr_delete ) {
	global $wpdb;
	foreach ( array( 'hgr_reviews', 'hgr_sync_logs', 'hgr_locations' ) as $hgr_table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . $hgr_table . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
	}

	foreach ( array( 'hgr_settings', 'hgr_encrypted_token', 'hgr_encrypted_credentials', 'hgr_db_version', 'hgr_plugin_version', 'hgr_sync_lock' ) as $hgr_option ) {
		delete_option( $hgr_option );
	}
}

// Los eventos de cron se limpian siempre.
wp_clear_scheduled_hook( 'hgr_sync_event' );
wp_clear_scheduled_hook( 'hgr_purge_event' );
