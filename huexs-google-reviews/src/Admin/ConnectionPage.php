<?php
/**
 * Pantalla Conexión: credenciales, OAuth y desconexión.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Admin;

use Huexs\GoogleReviews\Auth\OAuthController;
use Huexs\GoogleReviews\Plugin;

class ConnectionPage {

	public function __construct( private Plugin $plugin ) {}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$credentials = $this->plugin->credentials();
		$tokenStore  = $this->plugin->tokenStore();
		$connected   = $tokenStore->isConnected();
		$expired     = $tokenStore->isExpired();
		$source      = $credentials->source();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Huexs Google Reviews — Conexión', 'huexs-google-reviews' ); ?></h1>

			<div class="hgr-admin-card">
				<h2><?php esc_html_e( 'Credenciales de Google Cloud', 'huexs-google-reviews' ); ?></h2>
				<?php if ( 'constants' === $source ) : ?>
					<p><span class="hgr-status-ok"><?php esc_html_e( 'Definidas mediante constantes en wp-config.php (recomendado).', 'huexs-google-reviews' ); ?></span></p>
					<p><?php esc_html_e( 'Client ID:', 'huexs-google-reviews' ); ?> <span class="hgr-code"><?php echo esc_html( $credentials->maskedClientId() ); ?></span></p>
				<?php elseif ( 'options' === $source ) : ?>
					<p><span class="hgr-status-warn"><?php esc_html_e( 'Guardadas cifradas en la base de datos. Es preferible definirlas en wp-config.php.', 'huexs-google-reviews' ); ?></span></p>
					<p><?php esc_html_e( 'Client ID:', 'huexs-google-reviews' ); ?> <span class="hgr-code"><?php echo esc_html( $credentials->maskedClientId() ); ?></span></p>
				<?php else : ?>
					<p><span class="hgr-status-error"><?php esc_html_e( 'Sin configurar.', 'huexs-google-reviews' ); ?></span></p>
					<p>
						<?php esc_html_e( 'Opción recomendada: añade a wp-config.php:', 'huexs-google-reviews' ); ?><br />
						<span class="hgr-code">define( 'HGR_GOOGLE_CLIENT_ID', '…' );</span><br />
						<span class="hgr-code">define( 'HGR_GOOGLE_CLIENT_SECRET', '…' );</span>
					</p>
				<?php endif; ?>

				<?php if ( 'constants' !== $source ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="hgr_save_credentials" />
						<?php wp_nonce_field( 'hgr_save_credentials' ); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="hgr_client_id"><?php esc_html_e( 'Client ID', 'huexs-google-reviews' ); ?></label></th>
								<td><input type="text" class="regular-text" id="hgr_client_id" name="hgr_client_id" autocomplete="off" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="hgr_client_secret"><?php esc_html_e( 'Client Secret', 'huexs-google-reviews' ); ?></label></th>
								<td>
									<input type="password" class="regular-text" id="hgr_client_secret" name="hgr_client_secret" autocomplete="new-password" />
									<p class="description"><?php esc_html_e( 'Se guarda cifrado y no volverá a mostrarse completo. Advertencia: cualquier administrador de este WordPress podrá usar la conexión.', 'huexs-google-reviews' ); ?></p>
								</td>
							</tr>
						</table>
						<?php submit_button( __( 'Guardar credenciales', 'huexs-google-reviews' ), 'secondary', 'submit', false ); ?>
					</form>
				<?php endif; ?>

				<p style="margin-top:12px;">
					<?php esc_html_e( 'Redirect URI para Google Cloud (cópiala tal cual en la pantalla de credenciales OAuth):', 'huexs-google-reviews' ); ?><br />
					<span class="hgr-code"><?php echo esc_html( OAuthController::redirectUri() ); ?></span>
				</p>
			</div>

			<div class="hgr-admin-card">
				<h2><?php esc_html_e( 'Cuenta de Google', 'huexs-google-reviews' ); ?></h2>
				<?php if ( $expired ) : ?>
					<p><span class="hgr-status-error"><?php esc_html_e( 'La conexión ha caducado: Google rechazó la renovación del token. Reconecta.', 'huexs-google-reviews' ); ?></span></p>
				<?php elseif ( $connected ) : ?>
					<p><span class="hgr-status-ok"><?php esc_html_e( 'Cuenta conectada mediante OAuth.', 'huexs-google-reviews' ); ?></span></p>
				<?php else : ?>
					<p><?php esc_html_e( 'Todavía no hay ninguna cuenta conectada.', 'huexs-google-reviews' ); ?></p>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
					<input type="hidden" name="action" value="hgr_google_oauth_start" />
					<?php wp_nonce_field( 'hgr_oauth_start' ); ?>
					<?php
					submit_button(
						$connected || $expired ? __( 'Reconectar con Google', 'huexs-google-reviews' ) : __( 'Conectar con Google', 'huexs-google-reviews' ),
						'primary',
						'submit',
						false
					);
					?>
				</form>
			</div>

			<?php if ( $connected || $expired ) : ?>
			<div class="hgr-admin-card hgr-danger-zone">
				<h2><?php esc_html_e( 'Desconectar', 'huexs-google-reviews' ); ?></h2>
				<p><?php esc_html_e( 'Elimina inmediatamente los tokens OAuth y todas las reseñas y ubicaciones sincronizadas. Esta acción no se puede deshacer.', 'huexs-google-reviews' ); ?></p>
				<form
					method="post"
					action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					data-hgr-confirm="<?php esc_attr_e( '¿Desconectar Google y borrar todos los datos sincronizados?', 'huexs-google-reviews' ); ?>"
				>
					<input type="hidden" name="action" value="hgr_google_disconnect" />
					<?php wp_nonce_field( 'hgr_disconnect' ); ?>
					<?php submit_button( __( 'Desconectar y eliminar datos', 'huexs-google-reviews' ), 'delete', 'submit', false ); ?>
				</form>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
