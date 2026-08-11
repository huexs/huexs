<?php
/**
 * Diseño burbuja flotante: pestaña fija en una esquina que despliega las reseñas.
 * Sin JavaScript queda desplegado como un bloque normal (details/summary nativo).
 *
 * Variables: $reviews (object[]), $args, $summary (?array), $settings, $renderer.
 *
 * @package Huexs\GoogleReviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$hgr_position = isset( $args['position'] ) ? (string) $args['position'] : 'bottom-right';
$hgr_average  = null !== $summary && null !== $summary['average'] ? (float) $summary['average'] : null;
?>
<div
	class="<?php echo esc_attr( $renderer->wrapClasses( 'floating' ) ); ?> hgr-floating--<?php echo esc_attr( $hgr_position ); ?>"
	style="<?php echo esc_attr( $renderer->styleVars() ); ?>"
>
	<details class="hgr-floating" data-hgr-floating <?php echo empty( $args['force_open'] ) ? '' : 'open'; ?>>
		<summary class="hgr-floating__toggle">
			<?php echo $renderer->googleLogoHtml(); // phpcs:ignore WordPress.Security.EscapeOutput -- SVG interno. ?>
			<?php if ( null !== $hgr_average ) : ?>
				<span class="hgr-floating__average"><?php echo esc_html( number_format_i18n( $hgr_average, 1 ) ); ?></span>
				<?php echo $renderer->starsHtml( $hgr_average ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML interno escapado. ?>
			<?php else : ?>
				<span class="hgr-floating__average"><?php esc_html_e( 'Reseñas', 'huexs-google-reviews' ); ?></span>
			<?php endif; ?>
			<span class="screen-reader-text"><?php esc_html_e( 'Mostrar u ocultar las reseñas de Google', 'huexs-google-reviews' ); ?></span>
		</summary>

		<div class="hgr-floating__panel">
			<?php if ( null !== $summary && null !== $summary['average'] ) : ?>
				<?php
				$standalone = false;
				include __DIR__ . '/rating-summary.php';
				?>
			<?php endif; ?>

			<div class="hgr-floating__list">
				<?php foreach ( $reviews as $review ) : ?>
					<?php echo $renderer->renderCard( $review, $args ); // phpcs:ignore WordPress.Security.EscapeOutput -- plantilla interna escapada. ?>
				<?php endforeach; ?>
			</div>

			<?php echo $renderer->attributionHtml(); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML interno escapado. ?>
		</div>
	</details>
</div>
