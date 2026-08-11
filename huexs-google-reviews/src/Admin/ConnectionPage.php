<?php
/**
 * Pantalla Conexión: el onboarding completo en tres pasos.
 *
 * Pegar la clave → buscar el negocio → elegir diseño. La pantalla indica en qué
 * paso estás y qué falta para el siguiente.
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
		$unlocked  = $license->isUnlocked();
		$connected = $unlocked || $license->has();
		$locations = $this->plugin->locations()->all();
		$results   = get_transient( 'hgr_search_' . get_current_user_id() );
		$query     = get_transient( 'hgr_search_q_' . get_current_user_id() );
		$error     = get_transient( 'hgr_search_error_' . get_current_user_id() );
		?>
		<div class="wrap hgr-wrap">
			<h1 class="hgr-page-title"><?php esc_html_e( 'Huexs Google Reviews', 'huexs-google-reviews' ); ?></h1>

			<?php $this->renderSteps( $connected, (bool) $locations ); ?>

			<?php $this->renderConnectionCard( $license, $unlocked ); ?>

			<?php if ( $connected ) : ?>
				<?php
				$this->renderSearchCard(
					is_array( $results ) ? $results : null,
					is_string( $query ) ? $query : '',
					is_string( $error ) ? $error : null
				);
				?>
			<?php endif; ?>

			<?php $this->renderBusinessCards( $locations ); ?>
		</div>
		<?php
	}

	// ---- Indicador de pasos ----

	private function renderSteps( bool $connected, bool $hasBusiness ): void {
		$steps = array(
			array(
				'label' => __( 'Conectar', 'huexs-google-reviews' ),
				'done'  => $connected,
			),
			array(
				'label' => __( 'Tu negocio', 'huexs-google-reviews' ),
				'done'  => $hasBusiness,
			),
			array(
				'label' => __( 'Diseño', 'huexs-google-reviews' ),
				'done'  => false,
			),
		);

		// El paso actual es el primero que queda por completar.
		$current = count( $steps ) - 1;
		foreach ( $steps as $index => $step ) {
			if ( ! $step['done'] ) {
				$current = $index;
				break;
			}
		}
		?>
		<ol class="hgr-steps">
			<?php foreach ( $steps as $index => $step ) : ?>
				<?php
				$class = 'hgr-step';
				if ( $step['done'] ) {
					$class .= ' hgr-step--done';
				} elseif ( $index === $current ) {
					$class .= ' hgr-step--current';
				}
				?>
				<li class="<?php echo esc_attr( $class ); ?>">
					<span class="hgr-step__bullet" aria-hidden="true"><?php echo $step['done'] ? '&#10003;' : esc_html( (string) ( $index + 1 ) ); ?></span>
					<span class="hgr-step__label"><?php echo esc_html( $step['label'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	// ---- Paso 1: conexión ----

	private function renderConnectionCard( $license, bool $unlocked ): void {
		if ( $unlocked ) {
			$this->renderFullModeCard();
			return;
		}
		$this->renderLicenseCard( $license );
		$this->renderOwnKeyCard();
	}

	private function renderFullModeCard(): void {
		$key = $this->plugin->placesKey();
		?>
		<div class="hgr-card hgr-card--ok">
			<div class="hgr-card__head">
				<h2><?php esc_html_e( 'Modo completo', 'huexs-google-reviews' ); ?></h2>
				<span class="hgr-pill hgr-pill--full"><?php esc_html_e( 'SIN LÍMITES', 'huexs-google-reviews' ); ?></span>
			</div>

			<p class="hgr-lead"><?php esc_html_e( 'Este sitio usa tu propia clave de Google y habla directamente con Google. Sin licencia y sin límites.', 'huexs-google-reviews' ); ?></p>

			<div class="hgr-keyrow">
				<span class="hgr-keyrow__label"><?php esc_html_e( 'Clave', 'huexs-google-reviews' ); ?></span>
				<code class="hgr-code"><?php echo esc_html( $key->masked() ); ?></code>
				<span class="hgr-keyrow__source">
					<?php
					echo esc_html(
						'constant' === $key->source()
							? __( 'desde wp-config.php', 'huexs-google-reviews' )
							: __( 'guardada cifrada', 'huexs-google-reviews' )
					);
					?>
				</span>

				<button
					type="button"
					class="button"
					data-hgr-test-key
					data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'hgr_test_key' ) ); ?>"
					data-label-testing="<?php esc_attr_e( 'Probando…', 'huexs-google-reviews' ); ?>"
				>
					<?php esc_html_e( 'Probar clave', 'huexs-google-reviews' ); ?>
				</button>

				<?php if ( 'option' === $key->source() ) : ?>
					<form
						method="post"
						action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						class="hgr-inline"
						data-hgr-confirm="<?php esc_attr_e( '¿Quitar la clave de Google de este sitio?', 'huexs-google-reviews' ); ?>"
					>
						<input type="hidden" name="action" value="hgr_save_places_key" />
						<input type="hidden" name="hgr_places_key" value="" />
						<?php wp_nonce_field( 'hgr_save_places_key' ); ?>
						<button type="submit" class="button-link delete"><?php esc_html_e( 'Quitar', 'huexs-google-reviews' ); ?></button>
					</form>
				<?php endif; ?>
			</div>

			<p class="hgr-test-result" role="status" aria-live="polite" data-hgr-test-result></p>

			<p class="hgr-note"><?php esc_html_e( 'Google Places devuelve como máximo 5 reseñas por ficha y no incluye las respuestas del propietario. Es un límite de Google, no del plugin: la nota media y el total sí son completos.', 'huexs-google-reviews' ); ?></p>
		</div>
		<?php
	}

	private function renderLicenseCard( $license ): void {
		$limits = $license->limits();
		?>
		<div class="hgr-card">
			<div class="hgr-card__head">
				<h2><?php esc_html_e( 'Clave de licencia', 'huexs-google-reviews' ); ?></h2>
				<?php if ( $license->has() ) : ?>
					<span class="hgr-pill hgr-pill--<?php echo esc_attr( $license->plan() ); ?>"><?php echo esc_html( strtoupper( $license->plan() ) ); ?></span>
				<?php endif; ?>
			</div>

			<?php if ( $license->has() ) : ?>
				<div class="hgr-keyrow">
					<span class="hgr-keyrow__label"><?php esc_html_e( 'Licencia', 'huexs-google-reviews' ); ?></span>
					<code class="hgr-code"><?php echo esc_html( $license->maskedKey() ); ?></code>
				</div>
				<p class="hgr-note">
					<?php
					printf(
						/* translators: 1: nº de reseñas, 2: nº de ubicaciones. */
						esc_html__( 'Hasta %1$d reseñas por negocio y %2$d ubicaciones.', 'huexs-google-reviews' ),
						(int) $limits['max_reviews'],
						(int) $limits['max_locations']
					);
					?>
				</p>
			<?php else : ?>
				<p class="hgr-lead"><?php esc_html_e( 'Pega la clave que te hemos facilitado. No necesitas cuenta de Google Cloud ni configuración técnica.', 'huexs-google-reviews' ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hgr-inline">
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
				<button type="submit" class="button button-primary">
					<?php echo esc_html( $license->has() ? __( 'Cambiar', 'huexs-google-reviews' ) : __( 'Activar', 'huexs-google-reviews' ) ); ?>
				</button>
			</form>
		</div>
		<?php
	}

	private function renderOwnKeyCard(): void {
		?>
		<div class="hgr-card hgr-card--muted">
			<div class="hgr-card__head">
				<h2><?php esc_html_e( 'O usa tu propia clave de Google', 'huexs-google-reviews' ); ?></h2>
			</div>
			<p class="hgr-lead"><?php esc_html_e( 'Con una clave de Google Places propia, el plugin funciona en modo completo: sin licencia, sin límites y sin pasar por el servicio de Huexs.', 'huexs-google-reviews' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hgr-inline">
				<input type="hidden" name="action" value="hgr_save_places_key" />
				<?php wp_nonce_field( 'hgr_save_places_key' ); ?>
				<input
					type="password"
					name="hgr_places_key"
					class="regular-text"
					autocomplete="off"
					placeholder="AIza…"
					aria-label="<?php esc_attr_e( 'Clave de Google Places', 'huexs-google-reviews' ); ?>"
				/>
				<button type="submit" class="button"><?php esc_html_e( 'Guardar clave', 'huexs-google-reviews' ); ?></button>
			</form>

			<p class="hgr-note">
				<?php esc_html_e( 'Se guarda cifrada. Más seguro aún: definirla en wp-config.php como', 'huexs-google-reviews' ); ?>
				<code class="hgr-code">HGR_GOOGLE_PLACES_KEY</code>
			</p>
		</div>
		<?php
	}

	// ---- Paso 2: búsqueda ----

	/** @param \Huexs\GoogleReviews\Source\BusinessResult[]|null $results */
	private function renderSearchCard( ?array $results, string $query, ?string $error ): void {
		?>
		<div class="hgr-card">
			<div class="hgr-card__head">
				<h2><?php esc_html_e( 'Tu negocio', 'huexs-google-reviews' ); ?></h2>
			</div>
			<p class="hgr-lead"><?php esc_html_e( 'Empieza a escribir el nombre tal y como aparece en Google. Añade la ciudad si hay varios con el mismo nombre.', 'huexs-google-reviews' ); ?></p>

			<div
				class="hgr-search"
				data-hgr-search
				data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
				data-post-url="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				data-search-nonce="<?php echo esc_attr( wp_create_nonce( 'hgr_live_search' ) ); ?>"
				data-add-nonce="<?php echo esc_attr( wp_create_nonce( 'hgr_add_business' ) ); ?>"
				data-label-use="<?php esc_attr_e( 'Usar este negocio', 'huexs-google-reviews' ); ?>"
				data-label-searching="<?php esc_attr_e( 'Buscando…', 'huexs-google-reviews' ); ?>"
				data-label-empty="<?php esc_attr_e( 'Ningún negocio con ese nombre. Prueba a añadir la ciudad o revisa cómo aparece exactamente en Google Maps.', 'huexs-google-reviews' ); ?>"
				data-label-reviews="<?php esc_attr_e( 'reseñas', 'huexs-google-reviews' ); ?>"
			>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hgr-inline">
					<input type="hidden" name="action" value="hgr_search_business" />
					<?php wp_nonce_field( 'hgr_search_business' ); ?>
					<input
						type="search"
						name="hgr_query"
						class="regular-text"
						value="<?php echo esc_attr( $query ); ?>"
						placeholder="<?php esc_attr_e( 'Ej.: RotulaMax Barcelona', 'huexs-google-reviews' ); ?>"
						aria-label="<?php esc_attr_e( 'Nombre del negocio', 'huexs-google-reviews' ); ?>"
						autocomplete="off"
						required
						minlength="3"
						data-hgr-search-input
					/>
					<button type="submit" class="button"><?php esc_html_e( 'Buscar', 'huexs-google-reviews' ); ?></button>
				</form>

				<p class="hgr-search__status" role="status" aria-live="polite" data-hgr-search-status></p>
				<ul class="hgr-search-results" data-hgr-search-results hidden></ul>
			</div>

			<?php if ( null !== $error ) : ?>
				<div class="hgr-alert hgr-alert--error">
					<p><strong><?php esc_html_e( 'Google rechazó la petición. Respuesta literal:', 'huexs-google-reviews' ); ?></strong></p>
					<p class="hgr-code hgr-code--block"><?php echo self::linkify( $error ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado dentro de linkify(). ?></p>
				</div>
			<?php elseif ( is_array( $results ) && ! $results ) : ?>
				<p class="hgr-search__status hgr-search__status--warn"><?php esc_html_e( 'Ningún negocio con ese nombre. Prueba a añadir la ciudad.', 'huexs-google-reviews' ); ?></p>
			<?php elseif ( ! empty( $results ) ) : ?>
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
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Usar este negocio', 'huexs-google-reviews' ); ?></button>
							</form>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	// ---- Paso 3: negocios conectados ----

	/** @param object[] $locations */
	private function renderBusinessCards( array $locations ): void {
		if ( ! $locations ) {
			return;
		}
		?>
		<div class="hgr-card">
			<div class="hgr-card__head">
				<h2><?php esc_html_e( 'Negocios conectados', 'huexs-google-reviews' ); ?></h2>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=hgr-settings' ) ); ?>">
					<?php esc_html_e( 'Elegir diseño', 'huexs-google-reviews' ); ?>
				</a>
			</div>

			<div class="hgr-business-list">
				<?php foreach ( $locations as $location ) : ?>
					<div class="hgr-business">
						<div class="hgr-business__main">
							<strong class="hgr-business__name"><?php echo esc_html( $location->title ); ?></strong>
							<?php if ( ! empty( $location->address ) ) : ?>
								<span class="hgr-business__address"><?php echo esc_html( $location->address ); ?></span>
							<?php endif; ?>

							<?php if ( null !== $location->average_rating ) : ?>
								<span class="hgr-business__rating">
									<span class="hgr-business__average"><?php echo esc_html( number_format_i18n( (float) $location->average_rating, 1 ) ); ?></span>
									<?php echo $this->starsHtml( (float) $location->average_rating ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML interno escapado. ?>
									<span class="hgr-business__count">
										<?php
										printf(
											/* translators: %s: número de reseñas. */
											esc_html__( '%s reseñas en Google', 'huexs-google-reviews' ),
											esc_html( number_format_i18n( (int) $location->total_review_count ) )
										);
										?>
									</span>
								</span>
							<?php endif; ?>

							<span class="hgr-business__meta">
								<?php if ( 'failed' === $location->last_sync_status ) : ?>
									<span class="hgr-status-error"><?php esc_html_e( 'Última sincronización fallida', 'huexs-google-reviews' ); ?></span>
								<?php elseif ( $location->last_synced_at ) : ?>
									<?php
									printf(
										/* translators: %s: fecha UTC. */
										esc_html__( 'Sincronizado: %s UTC', 'huexs-google-reviews' ),
										esc_html( (string) $location->last_synced_at )
									);
									?>
								<?php else : ?>
									<?php esc_html_e( 'Todavía sin sincronizar', 'huexs-google-reviews' ); ?>
								<?php endif; ?>
								<?php if ( ReviewSourceInterface::SOURCE_GOOGLE === $location->source ) : ?>
									· <?php esc_html_e( 'modo avanzado', 'huexs-google-reviews' ); ?>
								<?php endif; ?>
							</span>

							<span class="hgr-business__shortcode">
								<code class="hgr-code" data-hgr-copy>[huexs_google_reviews location="<?php echo esc_attr( (string) $location->id ); ?>"]</code>
								<button type="button" class="button-link" data-hgr-copy-btn data-label-copied="<?php esc_attr_e( '¡Copiado!', 'huexs-google-reviews' ); ?>">
									<?php esc_html_e( 'Copiar', 'huexs-google-reviews' ); ?>
								</button>
							</span>
						</div>

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
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Escapa el texto y convierte en enlaces las URLs que contenga.
	 *
	 * Los errores de Google suelen incluir la URL exacta de la consola para resolver
	 * el problema: obligar a copiarla a mano no tiene sentido.
	 */
	public static function linkify( string $text ): string {
		$parts = preg_split( '#(https://[^\s<>"\']+)#', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = '';

		foreach ( (array) $parts as $part ) {
			if ( ! str_starts_with( $part, 'https://' ) ) {
				$out .= esc_html( $part );
				continue;
			}
			// Los signos de puntuación finales no forman parte del enlace.
			$url  = rtrim( $part, '.,;:)' );
			$rest = substr( $part, strlen( $url ) );
			$out .= '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '</a>' . esc_html( $rest );
		}

		return $out;
	}

	/** Estrellas para la administración, con texto accesible. */
	private function starsHtml( float $rating ): string {
		$full = (int) floor( $rating + 0.001 );
		$html = '<span class="hgr-admin-stars" role="img" aria-label="' . esc_attr(
			sprintf(
				/* translators: %s: puntuación. */
				__( '%s de 5 estrellas', 'huexs-google-reviews' ),
				number_format_i18n( $rating, 1 )
			)
		) . '">';
		for ( $i = 1; $i <= 5; $i++ ) {
			$html .= '<span class="' . ( $i <= $full ? 'hgr-admin-star hgr-admin-star--full' : 'hgr-admin-star' ) . '">&#9733;</span>';
		}
		return $html . '</span>';
	}
}
