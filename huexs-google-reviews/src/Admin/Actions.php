<?php
/**
 * Handlers admin-post. Todas las acciones comprueban manage_options y nonce.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Admin;

use Huexs\GoogleReviews\Frontend\Layouts;
use Huexs\GoogleReviews\Plugin;
use Huexs\GoogleReviews\Source\BusinessResult;
use Huexs\GoogleReviews\Source\ReviewSourceInterface;
use Huexs\GoogleReviews\Source\SourceException;
use Huexs\GoogleReviews\Sync\Scheduler;
use Huexs\GoogleReviews\Sync\SyncResult;

class Actions {

	private const SEARCH_TTL = 600;

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_post_hgr_save_license', array( $this, 'saveLicense' ) );
		add_action( 'admin_post_hgr_save_places_key', array( $this, 'savePlacesKey' ) );
		add_action( 'admin_post_hgr_search_business', array( $this, 'searchBusiness' ) );
		add_action( 'admin_post_hgr_add_business', array( $this, 'addBusiness' ) );
		add_action( 'admin_post_hgr_remove_business', array( $this, 'removeBusiness' ) );
		add_action( 'admin_post_hgr_save_settings', array( $this, 'saveSettings' ) );
		add_action( 'admin_post_hgr_sync_now', array( $this, 'syncNow' ) );
		add_action( 'admin_post_hgr_test_connection', array( $this, 'testConnection' ) );
		add_action( 'admin_post_hgr_save_credentials', array( $this, 'saveCredentials' ) );
		add_action( 'admin_post_hgr_fetch_locations', array( $this, 'fetchLocations' ) );
	}

	// ---- Licencia y alta de negocios ----

	public function saveLicense(): void {
		$this->authorize( 'hgr_save_license' );

		$key = isset( $_POST['hgr_license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['hgr_license_key'] ) ) : '';
		if ( '' === $key ) {
			$this->redirect( 'hgr-connection', 'error', 'license_missing' );
		}

		try {
			$this->plugin->license()->store( $key );
		} catch ( \RuntimeException ) {
			$this->redirect( 'hgr-connection', 'error', 'crypto_unavailable' );
		}

		// Validamos la clave contra el servidor y cacheamos el plan.
		try {
			$this->plugin->huexsSource()->refreshAccount();
		} catch ( SourceException $e ) {
			$this->plugin->logger()->debug( 'saveLicense: ' . $e->getMessage(), array( 'code' => $e->errorCode() ) );
			if ( 'invalid_license' === $e->errorCode() ) {
				$this->plugin->license()->clear();
				$this->redirect( 'hgr-connection', 'error', 'license_invalid' );
			}
			$this->redirect( 'hgr-connection', 'error', 'license_unreachable' );
		}

		$this->redirect( 'hgr-connection', 'success', 'license_saved' );
	}

	public function savePlacesKey(): void {
		$this->authorize( 'hgr_save_places_key' );

		$key = isset( $_POST['hgr_places_key'] ) ? sanitize_text_field( wp_unslash( $_POST['hgr_places_key'] ) ) : '';

		try {
			$this->plugin->placesKey()->store( $key );
		} catch ( \RuntimeException ) {
			$this->redirect( 'hgr-connection', 'error', 'crypto_unavailable' );
		}

		$this->redirect( 'hgr-connection', 'success', '' === $key ? 'places_key_removed' : 'places_key_saved' );
	}

	public function searchBusiness(): void {
		$this->authorize( 'hgr_search_business' );

		$query = isset( $_POST['hgr_query'] ) ? sanitize_text_field( wp_unslash( $_POST['hgr_query'] ) ) : '';

		try {
			$results = $this->plugin->activeSource()->searchBusinesses( $query );
		} catch ( SourceException $e ) {
			$this->plugin->logger()->debug( 'searchBusiness: ' . $e->getMessage(), array( 'code' => $e->errorCode() ) );
			$this->storeSearch( array(), $query );
			$this->redirect( 'hgr-connection', 'error', 'search_failed' );
		}

		$this->storeSearch( $results, $query );
		$this->redirect( 'hgr-connection', 'success', $results ? 'search_ok' : 'search_empty' );
	}

	public function addBusiness(): void {
		$this->authorize( 'hgr_add_business' );

		$placeId = isset( $_POST['hgr_place_id'] ) ? sanitize_text_field( wp_unslash( $_POST['hgr_place_id'] ) ) : '';
		$name    = isset( $_POST['hgr_name'] ) ? sanitize_text_field( wp_unslash( $_POST['hgr_name'] ) ) : '';
		if ( '' === $placeId || '' === $name ) {
			$this->redirect( 'hgr-connection', 'error', 'add_failed' );
		}

		$license = $this->plugin->license();
		if ( $this->plugin->locations()->countEnabled() >= $license->maxLocations() ) {
			$this->redirect( 'hgr-connection', 'error', 'plan_limit_locations' );
		}

		$now      = $this->plugin->clock()->nowString();
		$business = new BusinessResult(
			$placeId,
			$name,
			isset( $_POST['hgr_address'] ) ? sanitize_text_field( wp_unslash( $_POST['hgr_address'] ) ) : '',
			null,
			null
		);

		$id = $this->plugin->locations()->upsertFromBusiness( $this->plugin->defaultSourceId(), $business, $now );
		$this->plugin->locations()->setEnabled( $id, true, $now );
		$this->clearSearch();

		// Primera sincronización inmediata para que el cliente vea resultados al momento.
		$result = $this->plugin->syncService()->run( 'manual', $id );

		$this->redirect(
			'hgr-connection',
			SyncResult::STATUS_FAILED === $result->status ? 'error' : 'success',
			SyncResult::STATUS_FAILED === $result->status ? 'added_sync_failed' : 'business_added'
		);
	}

	public function removeBusiness(): void {
		$this->authorize( 'hgr_remove_business' );

		$id = isset( $_POST['hgr_location_id'] ) ? absint( wp_unslash( $_POST['hgr_location_id'] ) ) : 0;
		if ( $id > 0 ) {
			$this->plugin->reviews()->deleteByLocation( $id );
			$this->plugin->locations()->delete( $id );
		}

		$this->redirect( 'hgr-connection', 'success', 'business_removed' );
	}

	// ---- Ajustes ----

	public function saveSettings(): void {
		$this->authorize( 'hgr_save_settings' );

		$settings = Plugin::settings();
		$minHours = $this->plugin->license()->minSyncHours();

		$frequency = isset( $_POST['hgr_frequency'] ) ? (int) $_POST['hgr_frequency'] : 24;
		if ( ! in_array( $frequency, Scheduler::ALLOWED_HOURS, true ) ) {
			$frequency = 24;
		}
		// El plan puede imponer un suelo de frecuencia.
		$frequency        = max( $frequency, $minHours );
		$frequencyChanged = $frequency !== (int) $settings['sync_frequency_hours'];

		$layout = isset( $_POST['hgr_layout'] ) ? sanitize_key( wp_unslash( $_POST['hgr_layout'] ) ) : 'grid';
		if ( ! Layouts::isValid( $layout ) ) {
			$layout = 'grid';
		}

		$theme = isset( $_POST['hgr_theme'] ) ? sanitize_key( wp_unslash( $_POST['hgr_theme'] ) ) : 'light';
		if ( ! in_array( $theme, array( 'light', 'dark', 'transparent' ), true ) ) {
			$theme = 'light';
		}

		$cardStyle = isset( $_POST['hgr_card_style'] ) ? sanitize_key( wp_unslash( $_POST['hgr_card_style'] ) ) : 'shadow';
		if ( ! in_array( $cardStyle, array( 'shadow', 'border', 'flat' ), true ) ) {
			$cardStyle = 'shadow';
		}

		$badgePosition = isset( $_POST['hgr_badge_position'] ) ? sanitize_key( wp_unslash( $_POST['hgr_badge_position'] ) ) : 'bottom-right';
		if ( ! in_array( $badgePosition, array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' ), true ) ) {
			$badgePosition = 'bottom-right';
		}

		$settings = array_merge(
			$settings,
			array(
				'sync_frequency_hours' => $frequency,
				'advanced_mode'        => isset( $_POST['hgr_advanced_mode'] ),
				'default_layout'       => $layout,
				'default_limit'        => max( 1, min( 50, isset( $_POST['hgr_limit'] ) ? (int) $_POST['hgr_limit'] : 6 ) ),
				'show_avatar'          => isset( $_POST['hgr_show_avatar'] ),
				'show_date'            => isset( $_POST['hgr_show_date'] ),
				'show_reply'           => isset( $_POST['hgr_show_reply'] ),
				'show_google_logo'     => isset( $_POST['hgr_show_google_logo'] ),
				'excerpt_lines'        => max( 0, min( 30, isset( $_POST['hgr_excerpt_lines'] ) ? (int) $_POST['hgr_excerpt_lines'] : 5 ) ),
				'theme'                => $theme,
				'card_style'           => $cardStyle,
				'badge_position'       => $badgePosition,
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

	// ---- Sincronización ----

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
			if ( $this->plugin->license()->isUnlocked() ) {
				// Sin servicio central que consultar: se prueba la fuente activa.
				$this->plugin->activeSource()->searchBusinesses( 'Huexs' );
			} else {
				$this->plugin->huexsSource()->refreshAccount();
			}
			$this->redirect( 'hgr-status', 'success', 'test_ok' );
		} catch ( SourceException $e ) {
			$this->plugin->logger()->debug( 'testConnection: ' . $e->getMessage(), array( 'code' => $e->errorCode() ) );
			$this->redirect( 'hgr-status', 'error', 'test_failed' );
		}
	}

	// ---- Modo avanzado ----

	public function saveCredentials(): void {
		$this->authorize( 'hgr_save_credentials' );

		$clientId     = isset( $_POST['hgr_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['hgr_client_id'] ) ) : '';
		$clientSecret = isset( $_POST['hgr_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['hgr_client_secret'] ) ) : '';

		if ( '' === $clientId || '' === $clientSecret ) {
			$this->redirect( 'hgr-advanced', 'error', 'missing_credentials' );
		}

		try {
			$this->plugin->credentials()->store( $clientId, $clientSecret );
		} catch ( \RuntimeException ) {
			$this->redirect( 'hgr-advanced', 'error', 'crypto_unavailable' );
		}

		$this->redirect( 'hgr-advanced', 'success', 'credentials_saved' );
	}

	public function fetchLocations(): void {
		$this->authorize( 'hgr_fetch_locations' );

		try {
			$now = $this->plugin->clock()->nowString();
			foreach ( $this->plugin->googleSource()->listManagedLocations() as $business ) {
				$id = $this->plugin->locations()->upsertFromBusiness( ReviewSourceInterface::SOURCE_GOOGLE, $business, $now );
				$this->plugin->locations()->setEnabled( $id, true, $now );
			}
		} catch ( SourceException $e ) {
			$this->plugin->logger()->debug( 'fetchLocations: ' . $e->getMessage(), array( 'code' => $e->errorCode() ) );
			$this->redirect( 'hgr-advanced', 'error', 'fetch_failed' );
		}

		$this->redirect( 'hgr-advanced', 'success', 'locations_fetched' );
	}

	// ---- Utilidades ----

	/** @param \Huexs\GoogleReviews\Source\BusinessResult[] $results */
	private function storeSearch( array $results, string $query ): void {
		$plain = array_map(
			static fn( $r ) => array(
				'place_id'          => $r->placeId,
				'name'              => $r->name,
				'formatted_address' => $r->address,
				'rating'            => $r->rating,
				'review_count'      => $r->reviewCount,
			),
			$results
		);
		// Se guarda en forma plana y se rehidrata al pintar, para no serializar objetos.
		set_transient( 'hgr_search_raw_' . get_current_user_id(), $plain, self::SEARCH_TTL );
		set_transient( 'hgr_search_q_' . get_current_user_id(), $query, self::SEARCH_TTL );
		set_transient( 'hgr_search_' . get_current_user_id(), array_values( array_filter( array_map( array( BusinessResult::class, 'fromApi' ), $plain ) ) ), self::SEARCH_TTL );
	}

	private function clearSearch(): void {
		delete_transient( 'hgr_search_' . get_current_user_id() );
		delete_transient( 'hgr_search_raw_' . get_current_user_id() );
		delete_transient( 'hgr_search_q_' . get_current_user_id() );
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
