<?php
/**
 * Pantalla Resumen: estado general, sincronización manual y shortcodes de ejemplo.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Admin;

use Huexs\GoogleReviews\Plugin;
use Huexs\GoogleReviews\Sync\Scheduler;

class OverviewPage {

	public function __construct( private Plugin $plugin ) {}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$license   = $this->plugin->license();
		$locations = $this->plugin->locations()->findEnabled();
		$logs      = $this->plugin->syncLogs()->recent( 5 );
		$nextSync  = wp_next_scheduled( Scheduler::SYNC_EVENT );
		$lastLog   = $logs[0] ?? null;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Huexs Google Reviews — Resumen', 'huexs-google-reviews' ); ?></h1>

			<div class="hgr-admin-card">
				<h2><?php esc_html_e( 'Estado', 'huexs-google-reviews' ); ?></h2>
				<p>
					<?php if ( ! $license->has() ) : ?>
						<span class="hgr-status-warn"><?php esc_html_e( 'Sin licencia activada.', 'huexs-google-reviews' ); ?></span>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=hgr-connection' ) ); ?>"><?php esc_html_e( 'Activar ahora', 'huexs-google-reviews' ); ?></a>
					<?php elseif ( ! $locations ) : ?>
						<span class="hgr-status-warn"><?php esc_html_e( 'Licencia activa, pero no hay ningún negocio conectado.', 'huexs-google-reviews' ); ?></span>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=hgr-connection' ) ); ?>"><?php esc_html_e( 'Buscar mi negocio', 'huexs-google-reviews' ); ?></a>
					<?php else : ?>
						<span class="hgr-status-ok"><?php esc_html_e( 'Todo listo: reseñas sincronizándose automáticamente.', 'huexs-google-reviews' ); ?></span>
					<?php endif; ?>
				</p>

				<ul>
					<li>
						<?php
						printf(
							/* translators: %d: número de negocios activos. */
							esc_html__( 'Negocios conectados: %d', 'huexs-google-reviews' ),
							count( $locations )
						);
						?>
					</li>
					<li>
						<?php
						printf(
							/* translators: %s: número de reseñas. */
							esc_html__( 'Reseñas en caché: %s', 'huexs-google-reviews' ),
							esc_html( number_format_i18n( $this->plugin->reviews()->countAll() ) )
						);
						?>
					</li>
					<li>
						<?php
						if ( $lastLog && $lastLog->finished_at ) {
							printf(
								/* translators: 1: fecha, 2: estado. */
								esc_html__( 'Última sincronización: %1$s (%2$s)', 'huexs-google-reviews' ),
								esc_html( $lastLog->finished_at . ' UTC' ),
								esc_html( $lastLog->status )
							);
						} else {
							esc_html_e( 'Última sincronización: nunca', 'huexs-google-reviews' );
						}
						?>
					</li>
					<li>
						<?php
						if ( $nextSync ) {
							printf(
								/* translators: %s: fecha de la próxima ejecución. */
								esc_html__( 'Próxima sincronización: %s', 'huexs-google-reviews' ),
								esc_html( gmdate( 'Y-m-d H:i:s', $nextSync ) . ' UTC' )
							);
						} else {
							echo '<span class="hgr-status-error">' . esc_html__( 'El evento de cron no está programado.', 'huexs-google-reviews' ) . '</span>';
						}
						?>
					</li>
				</ul>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="hgr_sync_now" />
					<?php wp_nonce_field( 'hgr_sync_now' ); ?>
					<?php submit_button( __( 'Sincronizar ahora', 'huexs-google-reviews' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div class="hgr-admin-card">
				<h2><?php esc_html_e( 'Cómo mostrar las reseñas', 'huexs-google-reviews' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Pega cualquiera de estos shortcodes en una página, entrada o widget. En Elementor, usa el widget "Shortcode".', 'huexs-google-reviews' ); ?></p>
				<table class="widefat striped">
					<tbody>
						<tr>
							<td><?php esc_html_e( 'Cuadrícula de reseñas', 'huexs-google-reviews' ); ?></td>
							<td><span class="hgr-code">[huexs_google_reviews layout="grid" limit="6"]</span></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Carrusel', 'huexs-google-reviews' ); ?></td>
							<td><span class="hgr-code">[huexs_google_reviews layout="carousel" limit="10"]</span></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Columna lateral (widget estrecho)', 'huexs-google-reviews' ); ?></td>
							<td><span class="hgr-code">[huexs_google_reviews layout="sidebar" limit="8"]</span></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Insignia con la nota media', 'huexs-google-reviews' ); ?></td>
							<td><span class="hgr-code">[huexs_google_reviews layout="badge"]</span></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Burbuja flotante (ponla en el pie para verla en todo el sitio)', 'huexs-google-reviews' ); ?></td>
							<td><span class="hgr-code">[huexs_google_reviews layout="floating" limit="8"]</span></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Solo la valoración, en línea', 'huexs-google-reviews' ); ?></td>
							<td><span class="hgr-code">[huexs_google_rating]</span></td>
						</tr>
					</tbody>
				</table>
			</div>

			<?php if ( $logs ) : ?>
			<div class="hgr-admin-card">
				<h2><?php esc_html_e( 'Ejecuciones recientes', 'huexs-google-reviews' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Inicio (UTC)', 'huexs-google-reviews' ); ?></th>
							<th><?php esc_html_e( 'Origen', 'huexs-google-reviews' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'huexs-google-reviews' ); ?></th>
							<th><?php esc_html_e( 'Vistas / Nuevas / Actualizadas', 'huexs-google-reviews' ); ?></th>
							<th><?php esc_html_e( 'Error', 'huexs-google-reviews' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( $log->started_at ); ?></td>
								<td><?php echo esc_html( $log->trigger_type ); ?></td>
								<td><?php echo esc_html( $log->status ); ?></td>
								<td><?php echo esc_html( $log->reviews_seen . ' / ' . $log->reviews_inserted . ' / ' . $log->reviews_updated ); ?></td>
								<td><?php echo esc_html( $log->error_code ?? '—' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
