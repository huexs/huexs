<?php
/**
 * Menú de administración: Google Reviews con sus cinco pantallas.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Admin;

use Huexs\GoogleReviews\Plugin;

class Menu {

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addPages' ) );
		add_action( 'admin_notices', array( $this, 'showNotices' ) );
	}

	public function addPages(): void {
		$cap = 'manage_options';

		add_menu_page(
			__( 'Google Reviews', 'huexs-google-reviews' ),
			__( 'Google Reviews', 'huexs-google-reviews' ),
			$cap,
			'hgr-overview',
			array( new OverviewPage( $this->plugin ), 'render' ),
			'dashicons-star-filled',
			58
		);
		add_submenu_page( 'hgr-overview', __( 'Resumen', 'huexs-google-reviews' ), __( 'Resumen', 'huexs-google-reviews' ), $cap, 'hgr-overview', array( new OverviewPage( $this->plugin ), 'render' ) );
		add_submenu_page( 'hgr-overview', __( 'Conexión', 'huexs-google-reviews' ), __( 'Conexión', 'huexs-google-reviews' ), $cap, 'hgr-connection', array( new ConnectionPage( $this->plugin ), 'render' ) );
		add_submenu_page( 'hgr-overview', __( 'Ubicaciones', 'huexs-google-reviews' ), __( 'Ubicaciones', 'huexs-google-reviews' ), $cap, 'hgr-locations', array( new LocationsPage( $this->plugin ), 'render' ) );
		add_submenu_page( 'hgr-overview', __( 'Presentación', 'huexs-google-reviews' ), __( 'Presentación', 'huexs-google-reviews' ), $cap, 'hgr-settings', array( new SettingsPage( $this->plugin ), 'render' ) );
		add_submenu_page( 'hgr-overview', __( 'Estado', 'huexs-google-reviews' ), __( 'Estado', 'huexs-google-reviews' ), $cap, 'hgr-status', array( new StatusPage( $this->plugin ), 'render' ) );
	}

	public function showNotices(): void {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['hgr_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$code = sanitize_key( wp_unslash( $_GET['hgr_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$type = isset( $_GET['hgr_type'] ) && 'success' === $_GET['hgr_type'] ? 'success' : 'error'; // phpcs:ignore WordPress.Security.NonceVerification

		$messages = array(
			'connected'           => __( 'Cuenta de Google conectada correctamente.', 'huexs-google-reviews' ),
			'disconnected'        => __( 'Conexión eliminada. Tokens y reseñas sincronizadas borrados.', 'huexs-google-reviews' ),
			'invalid_state'       => __( 'El estado OAuth no es válido o ha caducado. Inténtalo de nuevo.', 'huexs-google-reviews' ),
			'google_denied'       => __( 'Google denegó la autorización (acceso cancelado o rechazado).', 'huexs-google-reviews' ),
			'missing_code'        => __( 'Google no devolvió el código de autorización.', 'huexs-google-reviews' ),
			'exchange_failed'     => __( 'No se pudo canjear el código de autorización. Revisa las credenciales y la Redirect URI.', 'huexs-google-reviews' ),
			'no_refresh_token'    => __( 'Google no entregó un refresh token. Revoca el acceso de la app en tu cuenta de Google y vuelve a conectar.', 'huexs-google-reviews' ),
			'crypto_unavailable'  => __( 'No hay criptografía segura disponible (Sodium/OpenSSL): el token no se ha guardado.', 'huexs-google-reviews' ),
			'missing_credentials' => __( 'Configura primero el Client ID y el Client Secret de Google Cloud.', 'huexs-google-reviews' ),
			'settings_saved'      => __( 'Ajustes guardados.', 'huexs-google-reviews' ),
			'credentials_saved'   => __( 'Credenciales guardadas de forma cifrada.', 'huexs-google-reviews' ),
			'locations_saved'     => __( 'Selección de ubicaciones guardada.', 'huexs-google-reviews' ),
			'locations_fetched'   => __( 'Cuentas y ubicaciones actualizadas desde Google.', 'huexs-google-reviews' ),
			'sync_ok'             => __( 'Sincronización completada.', 'huexs-google-reviews' ),
			'sync_partial'        => __( 'Sincronización completada con errores en alguna ubicación. Revisa la pantalla Estado.', 'huexs-google-reviews' ),
			'sync_failed'         => __( 'La sincronización falló. Revisa la pantalla Estado.', 'huexs-google-reviews' ),
			'sync_locked'         => __( 'Ya hay una sincronización en curso. Espera a que termine.', 'huexs-google-reviews' ),
			'fetch_failed'        => __( 'No se pudieron obtener las ubicaciones de Google. Revisa la pantalla Estado.', 'huexs-google-reviews' ),
			'test_ok'             => __( 'Conexión con Google verificada: la API responde.', 'huexs-google-reviews' ),
			'test_failed'         => __( 'La prueba de conexión falló. Revisa credenciales, permisos y aprobación de la API.', 'huexs-google-reviews' ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $messages[ $code ] )
		);
	}
}
