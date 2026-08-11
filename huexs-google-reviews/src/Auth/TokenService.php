<?php
/**
 * Proporciona access tokens válidos, renovándolos con el refresh token cuando toca.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Auth;

use Huexs\GoogleReviews\Google\GoogleApiException;
use Huexs\GoogleReviews\Support\Credentials;
use Huexs\GoogleReviews\Support\Logger;

class TokenService {

	private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
	private const REFRESH_MARGIN = 120;

	public function __construct(
		private TokenStore $store,
		private Credentials $credentials,
		private Logger $logger
	) {}

	/**
	 * @throws GoogleApiException Con código auth_expired o configuration_error.
	 */
	public function getValidAccessToken(): string {
		$token = $this->store->get();
		if ( null === $token || empty( $token['refresh_token'] ) ) {
			throw new GoogleApiException( 'auth_expired', 'No hay conexión con Google. Conecta la cuenta desde la pantalla Conexión.' );
		}
		if ( 'expired' === ( $token['status'] ?? '' ) ) {
			throw new GoogleApiException( 'auth_expired', 'La conexión con Google ha caducado. Reconecta la cuenta.' );
		}
		$expiresAt = (int) ( $token['expires_at'] ?? 0 );
		if ( ! empty( $token['access_token'] ) && $expiresAt > time() + self::REFRESH_MARGIN ) {
			return (string) $token['access_token'];
		}
		return $this->refresh( $token );
	}

	private function refresh( array $token ): string {
		if ( ! $this->credentials->has() ) {
			throw new GoogleApiException( 'configuration_error', 'Faltan las credenciales de Google Cloud (Client ID/Secret).' );
		}

		$response = \wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => array(
					'client_id'     => $this->credentials->clientId(),
					'client_secret' => $this->credentials->clientSecret(),
					'refresh_token' => (string) $token['refresh_token'],
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( \is_wp_error( $response ) ) {
			throw new GoogleApiException( 'remote_unavailable', 'No se pudo contactar con Google para renovar el token: ' . $response->get_error_message() );
		}

		$code = (int) \wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) \wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code && is_array( $body ) && ! empty( $body['access_token'] ) ) {
			$token['access_token'] = (string) $body['access_token'];
			$token['expires_at']   = time() + (int) ( $body['expires_in'] ?? 3600 );
			$token['status']       = 'active';
			$this->store->save( $token );
			return $token['access_token'];
		}

		$error = is_array( $body ) ? (string) ( $body['error'] ?? '' ) : '';
		if ( 'invalid_grant' === $error || 400 === $code || 401 === $code ) {
			$this->store->markExpired();
			$this->logger->debug( 'Refresh token rechazado, conexión marcada como caducada.', array( 'http' => $code, 'error' => $error ) );
			throw new GoogleApiException( 'auth_expired', 'Google rechazó la renovación del token. Reconecta la cuenta desde la pantalla Conexión.' );
		}

		throw new GoogleApiException( 'remote_unavailable', 'Respuesta inesperada de Google al renovar el token (HTTP ' . $code . ').' );
	}
}
