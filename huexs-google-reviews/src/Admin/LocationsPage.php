<?php
/**
 * Pantalla Ubicaciones: descubrimiento desde Google, activación y URL pública.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Admin;

use Huexs\GoogleReviews\Plugin;

class LocationsPage {

	public function __construct( private Plugin $plugin ) {}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$locations = $this->plugin->locations()->all();
		$connected = $this->plugin->tokenStore()->isConnected();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Huexs Google Reviews — Ubicaciones', 'huexs-google-reviews' ); ?></h1>

			<div class="hgr-admin-card">
				<p><?php esc_html_e( 'Solo se sincronizan las ubicaciones que actives aquí. Descubre primero las ubicaciones accesibles con la cuenta conectada.', 'huexs-google-reviews' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="hgr_fetch_locations" />
					<?php wp_nonce_field( 'hgr_fetch_locations' ); ?>
					<?php submit_button( __( 'Cargar cuentas y ubicaciones desde Google', 'huexs-google-reviews' ), 'secondary', 'submit', false, $connected ? array() : array( 'disabled' => 'disabled' ) ); ?>
					<?php if ( ! $connected ) : ?>
						<p class="description"><?php esc_html_e( 'Conecta primero una cuenta de Google en la pantalla Conexión.', 'huexs-google-reviews' ); ?></p>
					<?php endif; ?>
				</form>
			</div>

			<?php if ( $locations ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="hgr_save_locations" />
				<?php wp_nonce_field( 'hgr_save_locations' ); ?>
				<div class="hgr-admin-card">
					<table class="widefat striped hgr-table-locations">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Activa', 'huexs-google-reviews' ); ?></th>
								<th><?php esc_html_e( 'Ubicación', 'huexs-google-reviews' ); ?></th>
								<th><?php esc_html_e( 'Cuenta', 'huexs-google-reviews' ); ?></th>
								<th><?php esc_html_e( 'Store code', 'huexs-google-reviews' ); ?></th>
								<th><?php esc_html_e( 'Última sync', 'huexs-google-reviews' ); ?></th>
								<th><?php esc_html_e( 'Estado', 'huexs-google-reviews' ); ?></th>
								<th><?php esc_html_e( 'URL pública de Google (https)', 'huexs-google-reviews' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $locations as $location ) : ?>
								<tr>
									<td>
										<input
											type="checkbox"
											name="hgr_enabled[]"
											value="<?php echo esc_attr( (string) $location->id ); ?>"
											<?php checked( (int) $location->enabled, 1 ); ?>
											aria-label="<?php echo esc_attr( sprintf( /* translators: %s: nombre de la ubicación. */ __( 'Activar %s', 'huexs-google-reviews' ), $location->title ) ); ?>"
										/>
									</td>
									<td><strong><?php echo esc_html( $location->title ); ?></strong><br /><span class="description"><?php echo esc_html( $location->google_location_name ); ?></span></td>
									<td><?php echo esc_html( $location->google_account_name ); ?></td>
									<td><?php echo esc_html( $location->store_code ?? '—' ); ?></td>
									<td><?php echo esc_html( $location->last_synced_at ? $location->last_synced_at . ' UTC' : '—' ); ?></td>
									<td><?php echo esc_html( $location->last_sync_status ?? '—' ); ?></td>
									<td>
										<input
											type="url"
											class="regular-text"
											name="hgr_public_url[<?php echo esc_attr( (string) $location->id ); ?>]"
											value="<?php echo esc_attr( (string) ( $location->public_google_url ?? '' ) ); ?>"
											placeholder="https://maps.google.com/…"
											pattern="https://.*"
										/>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php submit_button( __( 'Guardar selección', 'huexs-google-reviews' ) ); ?>
				</div>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
