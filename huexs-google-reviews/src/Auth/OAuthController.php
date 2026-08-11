<?php
/**
 * Flujo OAuth 2.0: inicio, callback y desconexión. Todo desde servidor.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Auth;

use Huexs\GoogleReviews\Plugin;
use Huexs\GoogleReviews\Support\Credentials;
use Huexs\GoogleReviews\Support\Logger;

class OAuthController {

	public const SCOPE          = 'https://www.googleapis.com/auth/business.manage';
	private const AUTH_ENDPOINT  = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

	public function __construct(
		private Credentials $credentials,
		private TokenStore $tokenStore,
		private OAuthStateStore $stateStore,
		private Plugin $plugin
	) {}

	public function register(): void {
		add_action( 'admin_post_hgr_google_oauth_start', array( $this, 'start' ) );
		add_action( 'admin_post_hgr_google_oauth_callback', array( $this, 'callback' ) );
		add_action( 'admin_post_hgr_google_disconnect', array( $this, 'disconnect' ) );
	}

	public static function redirectUri(): string {
		return admin_url( 'admin-post.php?action=hgr_google_oauth_callback' );
	}

	public function start(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permisos insuficientes.', 'huexs-google-reviews' ) );
		}
		check_admin_referer( 'hgr_oauth_start' );

		if ( ! $this->credentials->has() ) {
			$this->redirectWithNotice( 'error', 'missing_credentials' );
		}

		$state = $this->stateStore->create( get_current_user_id() );
		$url   = add_query_arg(
			array(
				'client_id'              => $this->credentials->clientId(),
				'redirect_uri'           => self::redirectUri(),
				'response_type'          => 'code',
				'scope'                  => self::SCOPE,
				'access_type'            => 'offline',
				'include_granted_scopes' => 'true',
				'prompt'                 => 'consent',
				'state'                  => $state,
			),
			self::AUTH_ENDPOINT
		);
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- destino externo fijo de Google.
		exit;
	}

	public function callback(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permisos insuficientes.', 'huexs-google-reviews' ) );
		}

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( ! $this->stateStore->validateAndConsume( $state, get_current_user_id() ) ) {
			$this->redirectWithNotice( 'error', 'invalid_state' );
		}

		if ( isset( $_GET['error'] ) ) {
			$this->redirectWithNotice( 'error', 'google_denied' );
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( '' === $code ) {
			$this->redirectWithNotice( 'error', 'missing_code' );
		}

		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => array(
					'client_id'     => $this->credentials->clientId(),
					'client_secret' => $this->credentials->clientSecret(),
					'code'          => $code,
					'grant_type'    => 'authorization_code',
					'redirect_uri'  => self::redirectUri(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->redirectWithNotice( 'error', 'exchange_failed' );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
			$this->redirectWithNotice( 'error', 'exchange_failed' );
		}

		$existing = $this->tokenStore->get();
		$refresh  = (string) ( $body['refresh_token'] ?? '' );
		if ( '' === $refresh && $existing && ! empty( $existing['refresh_token'] ) ) {
			// Google no siempre reenvía el refresh token al reconectar la misma conexión.
			$refresh = (string) $existing['refresh_token'];
		}
		if ( '' === $refresh ) {
			$this->redirectWithNotice( 'error', 'no_refresh_token' );
		}

		try {
			$this->tokenStore->save(
				array(
					'access_token'  => (string) $body['access_token'],
					'refresh_token' => $refresh,
					'expires_at'    => time() + (int) ( $body['expires_in'] ?? 3600 ),
					'scope'         => (string) ( $body['scope'] ?? self::SCOPE ),
					'connected_at'  => time(),
					'status'        => 'active',
				)
			);
		} catch ( \RuntimeException $e ) {
			( new Logger() )->debug( 'No se pudo guardar el token: ' . $e->getMessage() );
			$this->redirectWithNotice( 'error', 'crypto_unavailable' );
		}

		$this->redirectWithNotice( 'success', 'connected' );
	}

	public function disconnect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permisos insuficientes.', 'huexs-google-reviews' ) );
		}
		check_admin_referer( 'hgr_disconnect' );

		$token = $this->tokenStore->get();
		if ( $token && ! empty( $token['refresh_token'] ) ) {
			// Revocación remota best-effort; la limpieza local se hace siempre.
			wp_remote_post(
				'https://oauth2.googleapis.com/revoke',
				array(
					'timeout' => 15,
					'body'    => array( 'token' => (string) $token['refresh_token'] ),
				)
			);
		}

		$this->tokenStore->clear();
		$this->plugin->reviews()->deleteAll();
		$this->plugin->locations()->deleteAll();
		$this->plugin->syncLogs()->deleteAll();

		$this->redirectWithNotice( 'success', 'disconnected' );
	}

	private function redirectWithNotice( string $type, string $code ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'hgr-connection',
					'hgr_notice' => $code,
					'hgr_type'   => $type,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
