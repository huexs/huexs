<?php
/**
 * Renderiza la pantalla Conexión fuera de WordPress para revisar su aspecto.
 *
 * Usa la clase real; solo sustituye las funciones de WordPress por stubs.
 * No forma parte del ZIP distribuible.
 *
 * Uso:
 *   php tools/render-admin-preview.php --state=nuevo      > /tmp/a.html
 *   php tools/render-admin-preview.php --state=conectado  > /tmp/b.html
 *
 * @package Huexs\GoogleReviews
 */

declare(strict_types=1);

require __DIR__ . '/../tests/bootstrap.php';

$args = array();
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( preg_match( '/^--([a-z_]+)=(.*)$/', $arg, $m ) ) {
		$args[ $m[1] ] = $m[2];
	}
}
$state = $args['state'] ?? 'conectado';

$GLOBALS['hgr_test_is_admin'] = true;

// --- Stubs adicionales que solo necesita la administración ---

function admin_url( $path = '' ) {
	return 'https://ejemplo.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function wp_create_nonce( $action = -1 ) {
	return 'nonce-demo';
}

function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
	$html = '<input type="hidden" name="' . $name . '" value="nonce-demo" />';
	if ( $display ) {
		echo $html; // phpcs:ignore
	}
	return $html;
}

function get_current_user_id() {
	return 1;
}

// --- Doble mínimo de $wpdb: la pantalla solo lee ubicaciones ---

use Huexs\GoogleReviews\Admin\ConnectionPage;
use Huexs\GoogleReviews\Plugin;
use Huexs\GoogleReviews\Source\BusinessResult;
use Huexs\GoogleReviews\Support\Crypto;
use Huexs\GoogleReviews\Support\PlacesKey;

$GLOBALS['wpdb'] = new class( $state ) {
	public string $prefix = 'wp_';

	public function __construct( private string $state ) {}

	public function get_results( $query ) {
		if ( 'conectado' !== $this->state ) {
			return array();
		}
		return array(
			(object) array(
				'id'                 => 1,
				'source'             => 'places_direct',
				'ref_key'            => 'ChIJdemo',
				'place_id'           => 'ChIJdemo',
				'title'              => 'RotulaMax Rótulos Luminosos',
				'address'            => "Carrer d'Exemple 12, 08001 Barcelona",
				'enabled'            => 1,
				'average_rating'     => 4.9,
				'total_review_count' => 86,
				'truncated'          => 1,
				'last_synced_at'     => '2026-08-11 18:40:00',
				'last_sync_status'   => 'success',
			),
		);
	}

	public function get_row( $query ) {
		return null;
	}

	public function get_var( $query ) {
		return 0;
	}

	public function prepare( $query, ...$args ) {
		return $query;
	}
};

// La clave activa el modo completo, igual que en un sitio real.
$crypto = new Crypto();
$key    = new PlacesKey( $crypto );
if ( 'nuevo' !== $state ) {
	$key->store( 'AIzaSyDemoKey0123456789abcd124c' );
}

$plugin = Plugin::instance();

// --- Búsqueda simulada según el estado ---

if ( 'buscando' === $state ) {
	set_transient(
		'hgr_search_1',
		array(
			new BusinessResult( 'ChIJ1', 'RotulaMax Rótulos Luminosos', 'Carrer Gran 4, Barcelona', 4.9, 86 ),
			new BusinessResult( 'ChIJ2', 'RotulaMax Taller', "Polígon Sud 12, L'Hospitalet", 4.7, 31 ),
		),
		600
	);
	set_transient( 'hgr_search_q_1', 'rotulamax', 600 );
}

if ( 'error' === $state ) {
	set_transient( 'hgr_search_1', array(), 600 );
	set_transient( 'hgr_search_q_1', 'rotula', 600 );
	set_transient(
		'hgr_search_error_1',
		'places_key_rejected: Google rechazó la clave de Places. Respuesta de Google: Requests from referer <empty> are blocked.',
		600
	);
}

// --- Salida ---

$css = file_get_contents( __DIR__ . '/../assets/css/admin.css' );

echo "<!doctype html>\n<html lang=\"es\"><head><meta charset=\"utf-8\">\n";
echo "<title>Pantalla Conexión</title>\n<style>\n";
echo "body{margin:0;padding:24px;background:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:14px;color:#1d2327;}\n";
echo "h1{font-size:23px;font-weight:400;margin:0 0 8px;}h2{font-size:16px;}\n";
echo ".button{background:#f6f7f7;border:1px solid #2271b1;border-radius:3px;color:#2271b1;cursor:pointer;padding:5px 12px;font-size:13px;}\n";
echo ".button-primary{background:#2271b1;border-color:#2271b1;color:#fff;text-decoration:none;display:inline-block;}\n";
echo ".button-link{background:none;border:none;color:#2271b1;cursor:pointer;padding:0;text-decoration:underline;font-size:13px;}\n";
echo ".button-link.delete{color:#b32d2e;}\n";
echo ".regular-text{border:1px solid #8c8f94;border-radius:4px;padding:6px 8px;width:25em;}\n";
echo $css;
echo "\n</style></head><body>\n";

( new ConnectionPage( $plugin ) )->render();

echo "</body></html>\n";
