<?php
/**
 * Plugin Name:       Huexs Google Reviews
 * Plugin URI:        https://huexs.com
 * Description:       Muestra tus reseñas de Google y mantenlas actualizadas automáticamente. Busca tu negocio por nombre, sin configuración técnica. Shortcodes compatibles con Elementor.
 * Version:           0.5.1
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Huexs
 * Author URI:        https://huexs.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       huexs-google-reviews
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Comprobaciones de versión sin errores fatales (este archivo debe parsear en PHP antiguos).
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Huexs Google Reviews requiere PHP 8.1 o superior. El plugin está inactivo.', 'huexs-google-reviews' );
			echo '</p></div>';
		}
	);
	return;
}

if ( version_compare( get_bloginfo( 'version' ), '6.5', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Huexs Google Reviews requiere WordPress 6.5 o superior. El plugin está inactivo.', 'huexs-google-reviews' );
			echo '</p></div>';
		}
	);
	return;
}

define( 'HGR_VERSION', '0.5.1' );
define( 'HGR_PLUGIN_FILE', __FILE__ );
define( 'HGR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HGR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Autoloader PSR-4 propio: el ZIP de producción no necesita vendor/.
spl_autoload_register(
	function ( $class ) {
		$prefix = 'Huexs\\GoogleReviews\\';
		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = HGR_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'Huexs\\GoogleReviews\\Activation', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Huexs\\GoogleReviews\\Deactivation', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		\Huexs\GoogleReviews\Plugin::instance()->init();
	}
);
