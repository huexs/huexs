<?php
/**
 * Menú de administración: Google Reviews y sus pantallas.
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
			'hgr-connection',
			array( new ConnectionPage( $this->plugin ), 'render' ),
			'dashicons-star-filled',
			58
		);
		add_submenu_page( 'hgr-connection', __( 'Conexión', 'huexs-google-reviews' ), __( 'Conexión', 'huexs-google-reviews' ), $cap, 'hgr-connection', array( new ConnectionPage( $this->plugin ), 'render' ) );
		add_submenu_page( 'hgr-connection', __( 'Diseño', 'huexs-google-reviews' ), __( 'Diseño', 'huexs-google-reviews' ), $cap, 'hgr-settings', array( new SettingsPage( $this->plugin ), 'render' ) );
		add_submenu_page( 'hgr-connection', __( 'Resumen', 'huexs-google-reviews' ), __( 'Resumen', 'huexs-google-reviews' ), $cap, 'hgr-overview', array( new OverviewPage( $this->plugin ), 'render' ) );
		add_submenu_page( 'hgr-connection', __( 'Estado', 'huexs-google-reviews' ), __( 'Estado', 'huexs-google-reviews' ), $cap, 'hgr-status', array( new StatusPage( $this->plugin ), 'render' ) );

		if ( Plugin::settings()['advanced_mode'] ) {
			add_submenu_page( 'hgr-connection', __( 'Avanzado', 'huexs-google-reviews' ), __( 'Avanzado', 'huexs-google-reviews' ), $cap, 'hgr-advanced', array( new AdvancedPage( $this->plugin ), 'render' ) );
		}
	}

	public function showNotices(): void {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['hgr_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$code = sanitize_key( wp_unslash( $_GET['hgr_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$type = isset( $_GET['hgr_type'] ) && 'success' === $_GET['hgr_type'] ? 'success' : 'error'; // phpcs:ignore WordPress.Security.NonceVerification

		$messages = array(
			// Licencia y negocios.
			'license_saved'        => __( 'Licencia activada correctamente.', 'huexs-google-reviews' ),
			'license_invalid'      => __( 'La clave de licencia no es válida o no corresponde a este dominio.', 'huexs-google-reviews' ),
			'license_missing'      => __( 'Introduce una clave de licencia.', 'huexs-google-reviews' ),
			'license_unreachable'  => __( 'No se pudo verificar la licencia ahora mismo. La hemos guardado; reintenta desde la pantalla Estado.', 'huexs-google-reviews' ),
			'search_ok'            => __( 'Elige tu negocio en la lista de resultados.', 'huexs-google-reviews' ),
			'search_empty'         => __( 'No se encontró ningún negocio con ese nombre.', 'huexs-google-reviews' ),
			'search_failed'        => __( 'La búsqueda falló. Revisa tu licencia y la conexión en la pantalla Estado.', 'huexs-google-reviews' ),
			'business_added'       => __( 'Negocio conectado y reseñas sincronizadas. Ya puedes insertar el shortcode.', 'huexs-google-reviews' ),
			'added_sync_failed'    => __( 'Negocio conectado, pero la primera sincronización falló. Revisa la pantalla Estado.', 'huexs-google-reviews' ),
			'business_removed'     => __( 'Negocio eliminado junto con sus reseñas guardadas.', 'huexs-google-reviews' ),
			'add_failed'           => __( 'No se pudo añadir el negocio. Vuelve a buscarlo.', 'huexs-google-reviews' ),
			'plan_limit_locations' => __( 'Tu plan no permite más ubicaciones. Amplía el plan para añadir otra.', 'huexs-google-reviews' ),
			// Sincronización.
			'sync_ok'              => __( 'Sincronización completada.', 'huexs-google-reviews' ),
			'sync_partial'         => __( 'Sincronización completada con errores en alguna ubicación. Revisa la pantalla Estado.', 'huexs-google-reviews' ),
			'sync_failed'          => __( 'La sincronización falló. Revisa la pantalla Estado.', 'huexs-google-reviews' ),
			'sync_locked'          => __( 'Ya hay una sincronización en curso. Espera a que termine.', 'huexs-google-reviews' ),
			// Ajustes y diagnóstico.
			'settings_saved'       => __( 'Ajustes guardados.', 'huexs-google-reviews' ),
			'test_ok'              => __( 'Conexión verificada: la API responde correctamente.', 'huexs-google-reviews' ),
			'test_failed'          => __( 'La prueba de conexión falló. Revisa tu clave de licencia.', 'huexs-google-reviews' ),
			'crypto_unavailable'   => __( 'No hay criptografía segura disponible (Sodium/OpenSSL): no se pudo guardar de forma segura.', 'huexs-google-reviews' ),
			// Modo avanzado.
			'credentials_saved'    => __( 'Credenciales guardadas de forma cifrada.', 'huexs-google-reviews' ),
			'missing_credentials'  => __( 'Introduce el Client ID y el Client Secret.', 'huexs-google-reviews' ),
			'locations_fetched'    => __( 'Fichas de Google cargadas y activadas.', 'huexs-google-reviews' ),
			'fetch_failed'         => __( 'No se pudieron obtener las fichas de Google. Revisa la pantalla Estado.', 'huexs-google-reviews' ),
			'connected'            => __( 'Cuenta de Google conectada correctamente.', 'huexs-google-reviews' ),
			'disconnected'         => __( 'Conexión eliminada. Tokens y reseñas sincronizadas borrados.', 'huexs-google-reviews' ),
			'invalid_state'        => __( 'El estado OAuth no es válido o ha caducado. Inténtalo de nuevo.', 'huexs-google-reviews' ),
			'google_denied'        => __( 'Google denegó la autorización.', 'huexs-google-reviews' ),
			'missing_code'         => __( 'Google no devolvió el código de autorización.', 'huexs-google-reviews' ),
			'exchange_failed'      => __( 'No se pudo canjear el código de autorización. Revisa las credenciales y la Redirect URI.', 'huexs-google-reviews' ),
			'no_refresh_token'     => __( 'Google no entregó un refresh token. Revoca el acceso de la app en tu cuenta de Google y vuelve a conectar.', 'huexs-google-reviews' ),
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
