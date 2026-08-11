<?php
/**
 * Logger mínimo: escribe en debug.log solo con WP_DEBUG_LOG y siempre redacta secretos.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Support;

class Logger {

	public function debug( string $message, array $context = array() ): void {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}
		$line = '[huexs-google-reviews] ' . self::redact( $message );
		if ( $context ) {
			$line .= ' ' . self::redact( (string) \wp_json_encode( $context ) );
		}
		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Elimina tokens, secretos y cabeceras Authorization de cualquier texto destinado a logs o mensajes.
	 */
	public static function redact( string $text ): string {
		$patterns = array(
			'/(access_token|refresh_token|client_secret|id_token|code)(["\']?\s*[:=]\s*["\']?)[A-Za-z0-9\-._~+\/\\\\]+=*/i' => '$1$2[REDACTADO]',
			'/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i' => 'Bearer [REDACTADO]',
			'/ya29\.[A-Za-z0-9\-._~+\/]+/' => '[REDACTADO]',
			'/1\/\/[A-Za-z0-9\-._~+\/]{20,}/' => '[REDACTADO]',
		);
		return (string) preg_replace( array_keys( $patterns ), array_values( $patterns ), $text );
	}
}
