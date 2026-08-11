<?php
/**
 * Pantalla Presentación: ajustes visuales globales y frecuencia de sincronización.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Admin;

use Huexs\GoogleReviews\Frontend\ReviewRenderer;
use Huexs\GoogleReviews\Plugin;
use Huexs\GoogleReviews\Sync\Scheduler;

class SettingsPage {

	public function __construct( private Plugin $plugin ) {}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Plugin::settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Huexs Google Reviews — Presentación', 'huexs-google-reviews' ); ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="hgr_save_settings" />
				<?php wp_nonce_field( 'hgr_save_settings' ); ?>

				<div class="hgr-admin-card">
					<h2><?php esc_html_e( 'Sincronización', 'huexs-google-reviews' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="hgr_frequency"><?php esc_html_e( 'Frecuencia', 'huexs-google-reviews' ); ?></label></th>
							<td>
								<select id="hgr_frequency" name="hgr_frequency">
									<?php foreach ( Scheduler::ALLOWED_HOURS as $hours ) : ?>
										<option value="<?php echo esc_attr( (string) $hours ); ?>" <?php selected( (int) $settings['sync_frequency_hours'], $hours ); ?>>
											<?php
											printf(
												/* translators: %d: horas. */
												esc_html__( 'Cada %d horas', 'huexs-google-reviews' ),
												(int) $hours
											);
											?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</table>
				</div>

				<div class="hgr-admin-card">
					<h2><?php esc_html_e( 'Diseño predeterminado', 'huexs-google-reviews' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="hgr_layout"><?php esc_html_e( 'Diseño', 'huexs-google-reviews' ); ?></label></th>
							<td>
								<select id="hgr_layout" name="hgr_layout">
									<option value="grid" <?php selected( $settings['default_layout'], 'grid' ); ?>><?php esc_html_e( 'Cuadrícula', 'huexs-google-reviews' ); ?></option>
									<option value="list" <?php selected( $settings['default_layout'], 'list' ); ?>><?php esc_html_e( 'Lista', 'huexs-google-reviews' ); ?></option>
									<option value="carousel" <?php selected( $settings['default_layout'], 'carousel' ); ?>><?php esc_html_e( 'Carrusel', 'huexs-google-reviews' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="hgr_limit"><?php esc_html_e( 'Número de reseñas', 'huexs-google-reviews' ); ?></label></th>
							<td><input type="number" id="hgr_limit" name="hgr_limit" min="1" max="50" value="<?php echo esc_attr( (string) $settings['default_limit'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Elementos visibles', 'huexs-google-reviews' ); ?></th>
							<td>
								<label><input type="checkbox" name="hgr_show_avatar" value="1" <?php checked( $settings['show_avatar'] ); ?> /> <?php esc_html_e( 'Avatar del autor', 'huexs-google-reviews' ); ?></label><br />
								<label><input type="checkbox" name="hgr_show_date" value="1" <?php checked( $settings['show_date'] ); ?> /> <?php esc_html_e( 'Fecha de la reseña', 'huexs-google-reviews' ); ?></label><br />
								<label><input type="checkbox" name="hgr_show_reply" value="1" <?php checked( $settings['show_reply'] ); ?> /> <?php esc_html_e( 'Respuesta del propietario', 'huexs-google-reviews' ); ?></label><br />
								<label><input type="checkbox" name="hgr_show_google_logo" value="1" <?php checked( $settings['show_google_logo'] ); ?> /> <?php esc_html_e( 'Logo de Google en la atribución', 'huexs-google-reviews' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="hgr_excerpt_lines"><?php esc_html_e( 'Líneas visibles del comentario', 'huexs-google-reviews' ); ?></label></th>
							<td>
								<input type="number" id="hgr_excerpt_lines" name="hgr_excerpt_lines" min="0" max="30" value="<?php echo esc_attr( (string) $settings['excerpt_lines'] ); ?>" />
								<p class="description"><?php esc_html_e( '0 = mostrar siempre completo. Con recorte, aparece un botón accesible "Leer más"; el texto completo se conserva en la página.', 'huexs-google-reviews' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="hgr-admin-card">
					<h2><?php esc_html_e( 'Colores y fondo', 'huexs-google-reviews' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="hgr_theme"><?php esc_html_e( 'Fondo de las tarjetas', 'huexs-google-reviews' ); ?></label></th>
							<td>
								<select id="hgr_theme" name="hgr_theme">
									<option value="light" <?php selected( $settings['theme'], 'light' ); ?>><?php esc_html_e( 'Claro', 'huexs-google-reviews' ); ?></option>
									<option value="dark" <?php selected( $settings['theme'], 'dark' ); ?>><?php esc_html_e( 'Oscuro', 'huexs-google-reviews' ); ?></option>
									<option value="transparent" <?php selected( $settings['theme'], 'transparent' ); ?>><?php esc_html_e( 'Transparente', 'huexs-google-reviews' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="hgr_accent_color"><?php esc_html_e( 'Color de acento (estrellas)', 'huexs-google-reviews' ); ?></label></th>
							<td><input type="color" id="hgr_accent_color" name="hgr_accent_color" value="<?php echo esc_attr( $settings['accent_color'] ?: '#fbbc04' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="hgr_text_color"><?php esc_html_e( 'Color del texto (opcional)', 'huexs-google-reviews' ); ?></label></th>
							<td><input type="text" class="small-text" id="hgr_text_color" name="hgr_text_color" value="<?php echo esc_attr( $settings['text_color'] ); ?>" placeholder="#1f2937" pattern="#[0-9a-fA-F]{3,6}" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="hgr_bg_color"><?php esc_html_e( 'Color de fondo de tarjeta (opcional)', 'huexs-google-reviews' ); ?></label></th>
							<td><input type="text" class="small-text" id="hgr_bg_color" name="hgr_bg_color" value="<?php echo esc_attr( $settings['bg_color'] ); ?>" placeholder="#ffffff" pattern="#[0-9a-fA-F]{3,6}" /></td>
						</tr>
					</table>
				</div>

				<div class="hgr-admin-card">
					<h2><?php esc_html_e( 'Otros', 'huexs-google-reviews' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Avisos', 'huexs-google-reviews' ); ?></th>
							<td>
								<label><input type="checkbox" name="hgr_stale_notice_admins" value="1" <?php checked( $settings['stale_notice_admins'] ); ?> /> <?php esc_html_e( 'Mostrar avisos solo a administradores cuando no haya datos o estén caducados', 'huexs-google-reviews' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Desinstalación', 'huexs-google-reviews' ); ?></th>
							<td>
								<label><input type="checkbox" name="hgr_delete_on_uninstall" value="1" <?php checked( $settings['delete_on_uninstall'] ); ?> /> <?php esc_html_e( 'Eliminar todos los datos (tablas y opciones) al desinstalar el plugin', 'huexs-google-reviews' ); ?></label>
							</td>
						</tr>
					</table>
				</div>

				<?php submit_button( __( 'Guardar ajustes', 'huexs-google-reviews' ) ); ?>
			</form>

			<?php $this->renderPreview( $settings ); ?>
		</div>
		<?php
	}

	private function renderPreview( array $settings ): void {
		$enabled = $this->plugin->locations()->findEnabled();
		if ( ! $enabled ) {
			return;
		}
		$ids  = array_map( static fn( $l ) => (int) $l->id, $enabled );
		$rows = $this->plugin->reviews()->findForDisplay( $ids, 'newest', 3 );
		if ( ! $rows ) {
			return;
		}
		$renderer = new ReviewRenderer( $settings );
		echo '<div class="hgr-admin-card"><h2>' . esc_html__( 'Vista previa (datos sincronizados)', 'huexs-google-reviews' ) . '</h2>';
		wp_enqueue_style( 'hgr-frontend', HGR_PLUGIN_URL . 'assets/css/frontend.css', array(), HGR_VERSION );
		echo $renderer->renderLayout( // phpcs:ignore WordPress.Security.EscapeOutput -- plantillas internas escapadas.
			$settings['default_layout'],
			$rows,
			array(
				'show_avatar'  => (bool) $settings['show_avatar'],
				'show_date'    => (bool) $settings['show_date'],
				'show_reply'   => (bool) $settings['show_reply'],
				'show_summary' => false,
			),
			null
		);
		echo '</div>';
	}
}
