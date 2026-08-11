<?php
/**
 * Pantalla Conexión: licencia, búsqueda del negocio por nombre y negocios conectados.
 *
 * Es el onboarding completo: el cliente pega su clave, escribe el nombre de su
 * negocio, lo elige de una lista y ya está sincronizando.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Admin;

use Huexs\GoogleReviews\Plugin;
use Huexs\GoogleReviews\Source\ReviewSourceInterface;

class ConnectionPage {

	public function __construct( private Plugin $plugin ) {}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$license   = $this->plugin->license();
		$locations = $this->plugin->locations()->all();
		$results   = get_transient( 'hgr_search_' . get_current_user_id() );
		$query     = get_transient( 'hgr_search_q_' . get_current_user_id() );
		?>
		<div class="wrap hgr-wrap-admin">
			<h1><?php esc_html_e( 'Huexs Google Reviews', 'huexs-google-reviews' ); ?></h1>

			<?php $this->renderLicenseBox( $license ); ?>

			<?php if ( $license->has() ) : ?>
				<?php $this->renderSearchBox( is_array( $results ) ? $results : null, is_string( $query ) ? $query : '' ); ?>
			<?php endif; ?>

			<?php $this->renderConnectedBox( $locations ); ?>

			<?php if ( Plugin::settings()['advanced_mode'] ) : ?>
				<p class="description">
					<?php esc_html_e( 'El modo avanzado está activo: puedes conectar tu propio proyecto de Google Cloud en la pantalla Avanzado.', 'huexs-google-reviews' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function renderLicenseBox( $license ): void {
		$plan   = $license->plan();
		$limits = $license->limits();
		?>
		<div class="hgr-admin-card">
			<h2><?php esc_html_e( '1. Tu clave de licencia', 'huexs-google-reviews' ); ?></h2>

			<?php if ( $license->has() ) : ?>
				<p>
					<span class="hgr-status-ok"><?php esc_html_e( 'Licencia activa', 'huexs-google-reviews' ); ?></span>
					&nbsp;<span class="hgr-code"><?php echo esc_html( $license->maskedKey() ); ?></span>
					&nbsp;<span class="hgr-plan-pill hgr-plan-pill--<?php echo esc_attr( $plan ); ?>"><?php echo esc_html( strtoupper( $plan ) ); ?></span>
				</p>
				<p class="description">
					<?php
					printf(
						/* translators: 1: nº de reseñas, 2: nº de ubicaciones. */
						esc_html__( 'Tu plan permite hasta %1$d reseñas por negocio y %2$d ubicaciones.', 'huexs-google-reviews' ),
						(int) $limits['max_reviews'],
						(int) $limits['max_locations']
					);
					?>
				</p>
			<?php else : ?>
				<p><?php esc_html_e( 'Introduce la clave que te hemos facilitado para activar el plugin. No necesitas cuenta de Google Cloud ni configuración técnica.', 'huexs-google-reviews' ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hgr-inline-form">
				<input type="hidden" name="action" value="hgr_save_license" />
				<?php wp_nonce_field( 'hgr_save_license' ); ?>
				<input
					type="text"
					name="hgr_license_key"
					class="regular-text"
					autocomplete="off"
					placeholder="HGR-XXXXXXXX-XXXXXXXX"
					aria-label="<?php esc_attr_e( 'Clave de licencia', 'huexs-google-reviews' ); ?>"
				/>
				<?php submit_button( $license->has() ? __( 'Cambiar clave', 'huexs-google-reviews' ) : __( 'Activar', 'huexs-google-reviews' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/** @param \Huexs\GoogleReviews\Source\BusinessResult[]|null $results */
	private function renderSearchBox( ?array $results, string $query ): void {
		?>
		<div class="hgr-admin-card">
			<h2><?php esc_html_e( '2. Busca tu negocio', 'huexs-google-reviews' ); ?></h2>
			<p><?php esc_html_e( 'Escribe el nombre tal y como aparece en Google (añade la ciudad si hay varios con el mismo nombre).', 'huexs-google-reviews' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hgr-inline-form">
				<input type="hidden" name="action" value="hgr_search_business" />
				<?php wp_nonce_field( 'hgr_search_business' ); ?>
				<input
					type="search"
					name="hgr_query"
					class="regular-text"
					value="<?php echo esc_attr( $query ); ?>"
					placeholder="<?php esc_attr_e( 'Ej.: Huexs Señalética Barcelona', 'huexs-google-reviews' ); ?>"
					aria-label="<?php esc_attr_e( 'Nombre del negocio', 'huexs-google-reviews' ); ?>"
					required
					minlength="3"
				/>
				<?php submit_button( __( 'Buscar', 'huexs-google-reviews' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php if ( null !== $results ) : ?>
				<?php if ( ! $results ) : ?>
					<p class="hgr-status-warn"><?php esc_html_e( 'No se encontró ningún negocio con ese nombre. Prueba a añadir la ciudad o revisa cómo aparece exactamente en Google Maps.', 'huexs-google-reviews' ); ?></p>
				<?php else : ?>
					<ul class="hgr-search-results">
						<?php foreach ( $results as $business ) : ?>
							<li class="hgr-search-result">
								<div class="hgr-search-result__info">
									<strong><?php echo esc_html( $business->name ); ?></strong>
									<?php if ( '' !== $business->address ) : ?>
										<span class="hgr-search-result__address"><?php echo esc_html( $business->address ); ?></span>
									<?php endif; ?>
									<?php if ( null !== $business->rating ) : ?>
										<span class="hgr-search-result__rating">
											<?php
											printf(
												/* translators: 1: nota media, 2: nº de reseñas. */
												esc_html__( '%1$s ★ · %2$s reseñas', 'huexs-google-reviews' ),
												esc_html( number_format_i18n( $business->rating, 1 ) ),
												esc_html( number_format_i18n( (int) $business->reviewCount ) )
											);
											?>
										</span>
									<?php endif; ?>
								</div>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="hgr_add_business" />
									<input type="hidden" name="hgr_place_id" value="<?php echo esc_attr( $business->placeId ); ?>" />
									<input type="hidden" name="hgr_name" value="<?php echo esc_attr( $business->name ); ?>" />
									<input type="hidden" name="hgr_address" value="<?php echo esc_attr( $business->address ); ?>" />
									<?php wp_nonce_field( 'hgr_add_business' ); ?>
									<?php submit_button( __( 'Usar este negocio', 'huexs-google-reviews' ), 'primary', 'submit', false ); ?>
								</form>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/** @param object[] $locations */
	private function renderConnectedBox( array $locations ): void {
		if ( ! $locations ) {
			return;
		}
		?>
		<div class="hgr-admin-card">
			<h2><?php esc_html_e( '3. Negocios conectados', 'huexs-google-reviews' ); ?></h2>
			<table class="widefat striped hgr-table-locations">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Negocio', 'huexs-google-reviews' ); ?></th>
						<th><?php esc_html_e( 'Valoración', 'huexs-google-reviews' ); ?></th>
						<th><?php esc_html_e( 'Última sincronización', 'huexs-google-reviews' ); ?></th>
						<th><?php esc_html_e( 'Shortcode', 'huexs-google-reviews' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $locations as $location ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $location->title ); ?></strong>
								<?php if ( ! empty( $location->address ) ) : ?>
									<br /><span class="description"><?php echo esc_html( $location->address ); ?></span>
								<?php endif; ?>
								<?php if ( ReviewSourceInterface::SOURCE_GOOGLE === $location->source ) : ?>
									<br /><span class="description"><?php esc_html_e( 'Modo avanzado (proyecto propio)', 'huexs-google-reviews' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( null !== $location->average_rating ) : ?>
									<?php echo esc_html( number_format_i18n( (float) $location->average_rating, 1 ) ); ?> ★
									<span class="description">(<?php echo esc_html( number_format_i18n( (int) $location->total_review_count ) ); ?>)</span>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
							<td>
								<?php echo esc_html( $location->last_synced_at ? $location->last_synced_at . ' UTC' : __( 'nunca', 'huexs-google-reviews' ) ); ?>
								<?php if ( 'failed' === $location->last_sync_status ) : ?>
									<br /><span class="hgr-status-error"><?php esc_html_e( 'último intento fallido', 'huexs-google-reviews' ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $location->truncated ) ) : ?>
									<br /><span class="hgr-status-warn"><?php esc_html_e( 'hay más reseñas disponibles con el plan Pro', 'huexs-google-reviews' ); ?></span>
								<?php endif; ?>
							</td>
							<td><span class="hgr-code">[huexs_google_reviews location="<?php echo esc_attr( (string) $location->id ); ?>"]</span></td>
							<td>
								<form
									method="post"
									action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
									data-hgr-confirm="<?php esc_attr_e( '¿Quitar este negocio y sus reseñas guardadas?', 'huexs-google-reviews' ); ?>"
								>
									<input type="hidden" name="action" value="hgr_remove_business" />
									<input type="hidden" name="hgr_location_id" value="<?php echo esc_attr( (string) $location->id ); ?>" />
									<?php wp_nonce_field( 'hgr_remove_business' ); ?>
									<button type="submit" class="button-link delete"><?php esc_html_e( 'Quitar', 'huexs-google-reviews' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
