<?php
/**
 * Transporte HTTP sobre la WordPress HTTP API, con reintentos y errores normalizados.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Support;

use Huexs\GoogleReviews\Source\SourceException;

class WpHttpClient implements HttpClientInterface {

	private const MAX_RETRIES = 3;
	private const TIMEOUT     = 20;

	public function __construct( private Logger $logger ) {}

	public function getJson( string $url, array $headers = array() ): array {
		return $this->request( 'GET', $url, null, $headers );
	}

	public function postJson( string $url, array $body, array $headers = array() ): array {
		return $this->request( 'POST', $url, $body, $headers );
	}

	private function request( string $method, string $url, ?array $body, array $headers ): array {
		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => array_merge(
				array(
					'Accept'     => 'application/json',
					'User-Agent' => 'huexs-google-reviews/' . ( defined( 'HGR_VERSION' ) ? HGR_VERSION : 'dev' ),
				),
				$headers
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) \wp_json_encode( $body );
		}

		$attempt = 0;
		while ( true ) {
			$attempt++;
			$response = \wp_remote_request( $url, $args );

			if ( \is_wp_error( $response ) ) {
				if ( $attempt <= self::MAX_RETRIES ) {
					$this->backoff( $attempt, null );
					continue;
				}
				throw new SourceException( 'remote_unavailable', 'No se pudo contactar con el servidor: ' . $response->get_error_message() );
			}

			$code = (int) \wp_remote_retrieve_response_code( $response );
			$raw  = (string) \wp_remote_retrieve_body( $response );

			if ( $code >= 200 && $code < 300 ) {
				$data = json_decode( $raw, true );
				if ( ! is_array( $data ) ) {
					throw new SourceException( 'invalid_response', 'El servidor devolvió una respuesta que no es JSON válido.' );
				}
				return $data;
			}

			if ( 429 === $code || ( $code >= 500 && $code < 600 ) ) {
				if ( $attempt <= self::MAX_RETRIES ) {
					$retryAfter = \wp_remote_retrieve_header( $response, 'retry-after' );
					$this->backoff( $attempt, is_string( $retryAfter ) && ctype_digit( $retryAfter ) ? (int) $retryAfter : null );
					continue;
				}
			}

			throw $this->toException( $code, $raw );
		}
	}

	/** Traduce la envoltura de error del contrato a una SourceException. */
	private function toException( int $code, string $raw ): SourceException {
		$decoded = json_decode( $raw, true );
		$apiCode = '';
		$message = '';
		if ( is_array( $decoded ) && isset( $decoded['error'] ) && is_array( $decoded['error'] ) ) {
			$apiCode = isset( $decoded['error']['code'] ) && is_string( $decoded['error']['code'] ) ? $decoded['error']['code'] : '';
			$message = isset( $decoded['error']['message'] ) && is_string( $decoded['error']['message'] ) ? $decoded['error']['message'] : '';
		}

		$known = array( 'invalid_license', 'plan_limit', 'not_found', 'rate_limited', 'upstream_error', 'configuration_error' );
		if ( ! in_array( $apiCode, $known, true ) ) {
			$apiCode = match ( true ) {
				401 === $code || 403 === $code => 'invalid_license',
				402 === $code                  => 'plan_limit',
				404 === $code                  => 'not_found',
				429 === $code                  => 'rate_limited',
				$code >= 500                   => 'upstream_error',
				default                        => 'invalid_response',
			};
		}
		if ( '' === $message ) {
			$message = 'El servidor respondió con HTTP ' . $code . '.';
		}

		$this->logger->debug( 'Error HTTP ' . $code, array( 'code' => $apiCode ) );

		return new SourceException( $apiCode, Logger::redact( $message ) );
	}

	private function backoff( int $attempt, ?int $retryAfter ): void {
		$seconds = null !== $retryAfter
			? min( $retryAfter, 30 )
			: min( 2 ** $attempt, 16 ) + ( random_int( 0, 1000 ) / 1000 );
		usleep( (int) ( $seconds * 1_000_000 ) );
	}
}
