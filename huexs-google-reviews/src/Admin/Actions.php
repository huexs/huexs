<?php
/**
 * Handlers admin-post: guardar ajustes, credenciales, ubicaciones, sincronizar y probar conexión.
 * Todas las acciones comprueban manage_options y nonce.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Admin;

use Huexs\GoogleReviews\Google\GoogleApiException;
use Huexs\GoogleReviews\Plugin;
use Huexs\GoogleReviews\Sync\Scheduler;
use Huexs\GoogleReviews\Sync\SyncResult;

class Actions {

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_post_hgr_save_settings', array( $this, 'saveSettings' ) );
		add_action( 'admin_post_hgr_save_credentials', array( $this, 'saveCredentials' ) );
		add_action( 'admin_post_hgr_fetch_locations', array( $this, 'fetchLocations' ) );
		add_action( 'admin_post_hgr_save_locations', array( $this, 'saveLocations' ) );
		add_action( 'admin_post_hgr_sync_now', array( $this, 'syncNow' ) );
		add_action( 'admin_post_hgr_test_connection', array( $this, 'testConnection' ) );
	}

	public function saveSettings(): void {
		$this->authorize( 'hgr_save_settings' );

		$settings = Plugin::settings();

		$frequency = isset( $_POST['hgr_frequency'] ) ? (int) $_POST['hgr_frequency'] : 6;
		if ( ! in_array( $frequency, Scheduler::ALLOWED_HOURS, true ) ) {
			$frequency = 6;
		}
		$frequencyChanged = $frequency !== (int) $settings['sync_frequency_hours'];

		$layout = isset( $_POST['hgr_layout'] ) ? sanitize_key( wp_unslash( $_POST['hgr_layout'] ) ) : 'grid';
		if ( ! in_array( $layout, array( 'grid', 'list', 'carousel' ), true ) ) {
			$layout = 'grid';
		}

		$theme = isset( $_POST['hgr_theme'] ) ? sanitize_key( wp_unslash( $_POST['hgr_theme'] ) ) : 'light';
		if ( ! in_array( $theme, array( 'light', 'dark', 'transparent' ), true ) ) {
			$theme = 'light';
		}

		$settings = array_merge(
			$settings,
			array(
				'sync_frequency_hours' => $frequency,
				'default_layout'       => $layout,
				'default_limit'        => max( 1, min( 50, isset( $_POST['hgr_limit'] ) ? (int) $_POST['hgr_limit'] : 6 ) ),
				'show_avatar'          => isset( $_POST['hgr_show_avatar'] ),
				'show_date'            => isset( $_POST['hgr_show_date'] ),
				'show_reply'           => isset( $_POST['hgr_show_reply'] ),
				'show_google_logo'     => isset( $_POST['hgr_show_google_logo'] ),
				'excerpt_lines'        => max( 0, min( 30, isset( $_POST['hgr_excerpt_lines'] ) ? (int) $_POST['hgr_excerpt_lines'] : 5 ) ),
				'theme'                => $theme,
				'accent_color'         => $this->sanitizeColor( $_POST['hgr_accent_color'] ?? '' ),
				'text_color'           => $this->sanitizeColor( $_POST['hgr_text_color'] ?? '' ),
				'bg_color'             => $this->sanitizeColor( $_POST['hgr_bg_color'] ?? '' ),
				'stale_notice_admins'  => isset( $_POST['hgr_stale_notice_admins'] ),
				'delete_on_uninstall'  => isset( $_POST['hgr_delete_on_uninstall'] ),
			)
		);

		Plugin::update_settings( $settings );

		if ( $frequencyChanged ) {
			Scheduler::reschedule( $frequency );
		}

		$this->redirect( 'hgr-settings', 'success', 'settings_saved' );
	}

	public function saveCredentials(): void {
		$this->authorize( 'hgr_save_credentials' );

		$clientId     = isset( $_POST['hgr_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['hgr_client_id'] ) ) : '';
		$clientSecret = isset( $_POST['hgr_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['hgr_client_secret'] ) ) : '';

		if ( '' === $clientId || '' === $clientSecret ) {
			$this->redirect( 'hgr-connection', 'error', 'missing_credentials' );
		}

		try {
			$this->plugin->credentials()->store( $clientId, $clientSecret );
		} catch ( \RuntimeException ) {
			$this->redirect( 'hgr-connection', 'error', 'crypto_unavailable' );
		}

		$this->redirect( 'hgr-connection', 'success', 'credentials_saved' );
	}

	public function fetchLocations(): void {
		$this->authorize( 'hgr_fetch_locations' );

		try {
			$client = $this->plugin->googleClient();
			$now    = $this->plugin->clock()->nowString();
			foreach ( $client->listAccounts() as $account ) {
				foreach ( $client->listLocations( $account->name ) as $location ) {
					$this->plugin->locations()->upsertFromGoogle( $location, $now );
				}
			}
		} catch ( GoogleApiException $e ) {
			$this->plugin->logger()->debug( 'fetchLocations: ' . $e->getMessage(), array( 'code' => $e->errorCode() ) );
			$this->redirect( 'hgr-locations', 'error', 'fetch_failed' );
		}

		$this->redirect( 'hgr-locations', 'success', 'locations_fetched' );
	}

	public function saveLocations(): void {
		$this->authorize( 'hgr_save_locations' );

		$enabledIds = array();
		if ( isset( $_POST['hgr_enabled'] ) && is_array( $_POST['hgr_enabled'] ) ) {
			$enabledIds = array_map( 'absint', wp_unslash( $_POST['hgr_enabled'] ) );
		}
		$now = $this->plugin->clock()->nowString();
		$this->plugin->locations()->setEnabled( $enabledIds, $now );

		if ( isset( $_POST['hgr_public_url'] ) && is_array( $_POST['hgr_public_url'] ) ) {
			foreach ( wp_unslash( $_POST['hgr_public_url'] ) as $id => $url ) {
				$url = trim( sanitize_url( (string) $url ) );
				// Solo HTTPS y solo dominios de Google; en blanco para borrar.
				$valid = '' !== $url
					&& str_starts_with( $url, 'https://' )
					&& (bool) preg_match( '#^https://([a-z0-9-]+\.)*(google\.[a-z.]{2,6}|goo\.gl|g\.page|maps\.app\.goo\.gl)/#i', $url );
				$this->plugin->locations()->savePublicUrl( absint( $id ), $valid ? $url : null, $now );
			}
		}

		$this->redirect( 'hgr-locations', 'success', 'locations_saved' );
	}

	public function syncNow(): void {
		$this->authorize( 'hgr_sync_now' );

		$result = $this->plugin->syncService()->run( 'manual' );

		$notice = match ( $result->status ) {
			SyncResult::STATUS_SUCCESS => 'sync_ok',
			SyncResult::STATUS_PARTIAL => 'sync_partial',
			SyncResult::STATUS_SKIPPED => 'sync_locked',
			default                    => 'sync_failed',
		};
		$type = in_array( $result->status, array( SyncResult::STATUS_SUCCESS, SyncResult::STATUS_PARTIAL ), true ) ? 'success' : 'error';

		$this->redirect( 'hgr-overview', $type, $notice );
	}

	public function testConnection(): void {
		$this->authorize( 'hgr_test_connection' );

		try {
			$this->plugin->googleClient()->listAccounts();
			$this->redirect( 'hgr-status', 'success', 'test_ok' );
		} catch ( GoogleApiException $e ) {
			$this->plugin->logger()->debug( 'testConnection: ' . $e->getMessage(), array( 'code' => $e->errorCode() ) );
			$this->redirect( 'hgr-status', 'error', 'test_failed' );
		}
	}

	private function authorize( string $nonceAction ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permisos insuficientes.', 'huexs-google-reviews' ) );
		}
		check_admin_referer( $nonceAction );
	}

	private function sanitizeColor( mixed $value ): string {
		$value = trim( sanitize_text_field( wp_unslash( (string) $value ) ) );
		return preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ? $value : '';
	}

	private function redirect( string $page, string $type, string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => $page,
					'hgr_type'   => $type,
					'hgr_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
