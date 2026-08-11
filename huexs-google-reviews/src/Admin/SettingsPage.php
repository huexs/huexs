<?php
/**
 * Pantalla Diseño: apariencia de los widgets y frecuencia de sincronización.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Admin;

use Huexs\GoogleReviews\Frontend\Layouts;
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
		$license  = $this->plugin->license();
		$allowed  = $license->allowedLayouts();
		$minHours = $license->minSyncHours();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Huexs Google Reviews — Diseño', 'huexs-google-reviews' ); ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="hgr_save_settings" />
				<?php wp_nonce_field( 'hgr_save_settings' ); ?>

				<div class="hgr-admin-card">
					<h2><?php esc_html_e( 'Diseño predeterminado', 'huexs-google-reviews' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Se usa cuando el shortcode no indica un diseño concreto. Puedes mezclar diseños distintos en distintas páginas con el atributo layout.', 'huexs-google-reviews' ); ?></p>

					<fieldset class="hgr-layout-picker">
						<legend class="screen-reader-text"><?php esc_html_e( 'Diseño', 'huexs-google-reviews' ); ?></legend>
						<?php foreach ( Layouts::labels() as $key => $label ) : ?>
							<?php $hgr_locked = ! in_array( $key, $allowed, true ); ?>
							<label class="hgr-layout-option<?php echo $hgr_locked ? ' hgr-layout-option--locked' : ''; ?>">
								<input
									type="radio"
									name="hgr_layout"
									value="<?php echo esc_attr( $key ); ?>"
									<?php checked( $settings['default_layout'], $key ); ?>
									<?php disabled( $hgr_locked ); ?>
								/>
								<span class="hgr-layout-option__preview hgr-preview--<?php echo esc_attr( $key ); ?>" aria-hidden="true"></span>
								<span class="hgr-layout-option__label">
									<?php echo esc_html( $label ); ?>
									<?php if ( $hgr_locked ) : ?>
										<span class="hgr-plan-pill hgr-plan-pill--pro"><?php esc_html_e( 'PRO', 'huexs-google-reviews' ); ?></span>
									<?php endif; ?>
								</span>
							</label>
						<?php endforeach; ?>
					</fieldset>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="hgr_limit"><?php esc_html_e( 'Número de reseñas', 'huexs-google-reviews' ); ?></label></th>
							<td>
								<input type="number" id="hgr_limit" name="hgr_limit" min="1" max="50" value="<?php echo esc_attr( (string) $settings['default_limit'] ); ?>" />
								<p class="description">
									<?php
									printf(
										/* translators: %d: máximo de reseñas del plan. */
										esc_html__( 'Tu plan sincroniza hasta %d reseñas por negocio.', 'huexs-google-reviews' ),
										$license->maxReviews()
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="hgr_badge_position"><?php esc_html_e( 'Posición de la burbuja flotante', 'huexs-google-reviews' ); ?></label></th>
							<td>
								<select id="hgr_badge_position" name="hgr_badge_position">
									<option value="bottom-right" <?php selected( $settings['badge_position'], 'bottom-right' ); ?>><?php esc_html_e( 'Abajo a la derecha', 'huexs-google-reviews' ); ?></option>
									<option value="bottom-left" <?php selected( $settings['badge_position'], 'bottom-left' ); ?>><?php esc_html_e( 'Abajo a la izquierda', 'huexs-google-reviews' ); ?></option>
									<option value="top-right" <?php selected( $settings['badge_position'], 'top-right' ); ?>><?php esc_html_e( 'Arriba a la derecha', 'huexs-google-reviews' ); ?></option>
									<option value="top-left" <?php selected( $settings['badge_position'], 'top-left' ); ?>><?php esc_html_e( 'Arriba a la izquierda', 'huexs-google-reviews' ); ?></option>
								</select>
							</td>
						</tr>
					</table>
				</div>

				<div class="hgr-admin-card">
					<h2><?php esc_html_e( 'Apariencia', 'huexs-google-reviews' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="hgr_theme"><?php esc_html_e( 'Fondo', 'huexs-google-reviews' ); ?></label></th>
							<td>
								<select id="hgr_theme" name="hgr_theme">
									<option value="light" <?php selected( $settings['theme'], 'light' ); ?>><?php esc_html_e( 'Claro', 'huexs-google-reviews' ); ?></option>
									<option value="dark" <?php selected( $settings['theme'], 'dark' ); ?>><?php esc_html_e( 'Oscuro', 'huexs-google-reviews' ); ?></option>
									<option value="transparent" <?php selected( $settings['theme'], 'transparent' ); ?>><?php esc_html_e( 'Transparente (hereda del tema)', 'huexs-google-reviews' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="hgr_card_style"><?php esc_html_e( 'Estilo de tarjeta', 'huexs-google-reviews' ); ?></label></th>
							<td>
								<select id="hgr_card_style" name="hgr_card_style">
									<option value="shadow" <?php selected( $settings['card_style'], 'shadow' ); ?>><?php esc_html_e( 'Con sombra', 'huexs-google-reviews' ); ?></option>
									<option value="border" <?php selected( $settings['card_style'], 'border' ); ?>><?php esc_html_e( 'Solo borde', 'huexs-google-reviews' ); ?></option>
									<option value="flat" <?php selected( $settings['card_style'], 'flat' ); ?>><?php esc_html_e( 'Plano (sin caja)', 'huexs-google-reviews' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="hgr_accent_color"><?php esc_html_e( 'Color de las estrellas', 'huexs-google-reviews' ); ?></label></th>
							<td><input type="color" id="hgr_accent_color" name="hgr_accent_color" value="<?php echo esc_attr( $settings['accent_color'] ?: '#fbbc04' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="hgr_text_color"><?php esc_html_e( 'Color del texto (opcional)', 'huexs-google-reviews' ); ?></label></th>
							<td><input type="text" class="small-text" id="hgr_text_color" name="hgr_text_color" value="<?php echo esc_attr( $settings['text_color'] ); ?>" placeholder="#1f2937" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="hgr_bg_color"><?php esc_html_e( 'Fondo de tarjeta (opcional)', 'huexs-google-reviews' ); ?></label></th>
							<td><input type="text" class="small-text" id="hgr_bg_color" name="hgr_bg_color" value="<?php echo esc_attr( $settings['bg_color'] ); ?>" placeholder="#ffffff" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Elementos visibles', 'huexs-google-reviews' ); ?></th>
							<td>
								<label><input type="checkbox" name="hgr_show_avatar" value="1" <?php checked( $settings['show_avatar'] ); ?> /> <?php esc_html_e( 'Foto del autor', 'huexs-google-reviews' ); ?></label><br />
								<label><input type="checkbox" name="hgr_show_date" value="1" <?php checked( $settings['show_date'] ); ?> /> <?php esc_html_e( 'Fecha de la reseña', 'huexs-google-reviews' ); ?></label><br />
								<label><input type="checkbox" name="hgr_show_reply" value="1" <?php checked( $settings['show_reply'] ); ?> /> <?php esc_html_e( 'Respuesta del propietario', 'huexs-google-reviews' ); ?></label><br />
								<label><input type="checkbox" name="hgr_show_google_logo" value="1" <?php checked( $settings['show_google_logo'] ); ?> /> <?php esc_html_e( 'Logo de Google', 'huexs-google-reviews' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="hgr_excerpt_lines"><?php esc_html_e( 'Líneas visibles del comentario', 'huexs-google-reviews' ); ?></label></th>
							<td>
								<input type="number" id="hgr_excerpt_lines" name="hgr_excerpt_lines" min="0" max="30" value="<?php echo esc_attr( (string) $settings['excerpt_lines'] ); ?>" />
								<p class="description"><?php esc_html_e( '0 = mostrar siempre completo. Con recorte aparece un botón "Leer más" accesible; el texto completo sigue presente en la página.', 'huexs-google-reviews' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="hgr-admin-card">
					<h2><?php esc_html_e( 'Sincronización y avanzado', 'huexs-google-reviews' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="hgr_frequency"><?php esc_html_e( 'Frecuencia de actualización', 'huexs-google-reviews' ); ?></label></th>
							<td>
								<select id="hgr_frequency" name="hgr_frequency">
									<?php foreach ( Scheduler::ALLOWED_HOURS as $hours ) : ?>
										<option value="<?php echo esc_attr( (string) $hours ); ?>" <?php selected( (int) $settings['sync_frequency_hours'], $hours ); ?> <?php disabled( $hours < $minHours ); ?>>
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
								<?php if ( $minHours > 6 ) : ?>
									<p class="description">
										<?php
										printf(
											/* translators: %d: horas mínimas del plan. */
											esc_html__( 'Tu plan actualiza como máximo cada %d horas.', 'huexs-google-reviews' ),
											$minHours
										);
										?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Avisos', 'huexs-google-reviews' ); ?></th>
							<td><label><input type="checkbox" name="hgr_stale_notice_admins" value="1" <?php checked( $settings['stale_notice_admins'] ); ?> /> <?php esc_html_e( 'Mostrar avisos solo a administradores cuando no haya datos', 'huexs-google-reviews' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Modo avanzado', 'huexs-google-reviews' ); ?></th>
							<td>
								<label><input type="checkbox" name="hgr_advanced_mode" value="1" <?php checked( $settings['advanced_mode'] ); ?> /> <?php esc_html_e( 'Permitir conectar un proyecto propio de Google Cloud', 'huexs-google-reviews' ); ?></label>
								<p class="description"><?php esc_html_e( 'Solo para instalaciones que no quieran depender de la API de Huexs. Requiere aprobación de Google Business Profile API.', 'huexs-google-reviews' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Desinstalación', 'huexs-google-reviews' ); ?></th>
							<td><label><input type="checkbox" name="hgr_delete_on_uninstall" value="1" <?php checked( $settings['delete_on_uninstall'] ); ?> /> <?php esc_html_e( 'Eliminar todos los datos al desinstalar el plugin', 'huexs-google-reviews' ); ?></label></td>
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

		$location = $enabled[0];
		$summary  = array(
			'average'        => null !== $location->average_rating ? (float) $location->average_rating : null,
			'count'          => (int) $location->total_review_count,
			'multi_location' => false,
			'public_url'     => (string) ( $location->public_google_url ?? '' ),
			'name'           => (string) $location->title,
		);

		$renderer = new ReviewRenderer( $settings, $this->plugin->license()->brandingRequired() );
		$layout   = (string) $settings['default_layout'];

		// La burbuja flotante es de posición fija: en la vista previa se muestra en cuadrícula.
		$previewLayout = Layouts::FLOATING === $layout ? Layouts::GRID : $layout;

		echo '<div class="hgr-admin-card"><h2>' . esc_html__( 'Vista previa con tus datos reales', 'huexs-google-reviews' ) . '</h2>';
		if ( Layouts::FLOATING === $layout ) {
			echo '<p class="description">' . esc_html__( 'La burbuja flotante se ancla a una esquina de la pantalla; aquí se muestra su contenido en cuadrícula.', 'huexs-google-reviews' ) . '</p>';
		}
		echo $renderer->renderLayout( // phpcs:ignore WordPress.Security.EscapeOutput -- plantillas internas escapadas.
			$previewLayout,
			$rows,
			array(
				'show_avatar'  => (bool) $settings['show_avatar'],
				'show_date'    => (bool) $settings['show_date'],
				'show_reply'   => (bool) $settings['show_reply'],
				'show_summary' => true,
				'show_count'   => true,
				'position'     => (string) $settings['badge_position'],
			),
			$summary
		);
		echo '</div>';
	}
}
