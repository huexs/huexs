<?php
/**
 * Bootstrap de tests unitarios: autoload + stubs mínimos de WordPress.
 * Los tests unitarios no requieren una instalación de WordPress.
 */

declare(strict_types=1);

error_reporting( E_ALL );

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if ( is_readable( $vendorAutoload ) ) {
	require $vendorAutoload;
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$map = array(
				'Huexs\\GoogleReviews\\Tests\\' => __DIR__ . '/',
				'Huexs\\GoogleReviews\\'        => __DIR__ . '/../src/',
			);
			foreach ( $map as $prefix => $base ) {
				if ( str_starts_with( $class, $prefix ) ) {
					$file = $base . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
					if ( is_readable( $file ) ) {
						require $file;
					}
					return;
				}
			}
		}
	);
}

// --- Constantes que el código de producción espera ---
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}
if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'test-auth-key-0123456789-abcdefghijklmnopqrstuvwxyz' );
}
if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
	define( 'SECURE_AUTH_KEY', 'test-secure-auth-key-0123456789-zyxwvutsrqponmlkjihg' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// --- Stubs de funciones de WordPress usadas por las clases bajo test ---

$GLOBALS['hgr_test_options']    = array();
$GLOBALS['hgr_test_transients'] = array();

function hgr_test_reset_state(): void {
	$GLOBALS['hgr_test_options']    = array();
	$GLOBALS['hgr_test_transients'] = array();
}

function get_option( string $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['hgr_test_options'] ) ? $GLOBALS['hgr_test_options'][ $name ] : $default;
}

function add_option( string $name, $value, $deprecated = '', $autoload = 'yes' ): bool {
	if ( array_key_exists( $name, $GLOBALS['hgr_test_options'] ) ) {
		return false;
	}
	$GLOBALS['hgr_test_options'][ $name ] = $value;
	return true;
}

function update_option( string $name, $value, $autoload = null ): bool {
	$GLOBALS['hgr_test_options'][ $name ] = $value;
	return true;
}

function delete_option( string $name ): bool {
	unset( $GLOBALS['hgr_test_options'][ $name ] );
	return true;
}

function set_transient( string $key, $value, int $ttl = 0 ): bool {
	$GLOBALS['hgr_test_transients'][ $key ] = array( $value, $ttl ? time() + $ttl : PHP_INT_MAX );
	return true;
}

function get_transient( string $key ) {
	if ( ! isset( $GLOBALS['hgr_test_transients'][ $key ] ) ) {
		return false;
	}
	[ $value, $expires ] = $GLOBALS['hgr_test_transients'][ $key ];
	if ( time() > $expires ) {
		unset( $GLOBALS['hgr_test_transients'][ $key ] );
		return false;
	}
	return $value;
}

function delete_transient( string $key ): bool {
	unset( $GLOBALS['hgr_test_transients'][ $key ] );
	return true;
}

function wp_json_encode( $data, int $flags = 0 ) {
	return json_encode( $data, $flags );
}

function absint( $value ): int {
	return abs( (int) $value );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return filter_var( (string) $url, FILTER_VALIDATE_URL ) ? (string) $url : '';
}

function __( $text, $domain = 'default' ) { // phpcs:ignore
	return $text;
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, (int) $decimals, ',', '.' );
}
