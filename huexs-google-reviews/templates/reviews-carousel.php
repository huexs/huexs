<?php
/**
 * Diseño carrusel accesible. Sin JavaScript degrada a una fila desplazable.
 *
 * Variables: $reviews (object[]), $args, $summary (?array), $settings, $renderer.
 *
 * @package Huexs\GoogleReviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="<?php echo esc_attr( $renderer->wrapClasses( 'carousel' ) ); ?>" style="<?php echo esc_attr( $renderer->styleVars() ); ?>">
	<?php
	if ( null !== $summary && null !== $summary['average'] ) {
		$standalone = false;
		include __DIR__ . '/rating-summary.php';
	}
	?>
	<section
		class="hgr-carousel"
		data-hgr-carousel
		data-autoplay="<?php echo empty( $args['autoplay'] ) ? '0' : '1'; ?>"
		data-autoplay-seconds="<?php echo esc_attr( (string) ( $args['autoplay_seconds'] ?? 5 ) ); ?>"
		aria-roledescription="carrusel"
		aria-label="<?php esc_attr_e( 'Reseñas de Google', 'huexs-google-reviews' ); ?>"
	>
		<div class="hgr-carousel__controls">
			<?php if ( ! empty( $args['autoplay'] ) ) : ?>
				<button
					type="button"
					class="hgr-carousel__btn hgr-carousel__btn--pause"
					data-hgr-pause
					data-label-pause="<?php esc_attr_e( 'Pausar', 'huexs-google-reviews' ); ?>"
					data-label-play="<?php esc_attr_e( 'Reanudar', 'huexs-google-reviews' ); ?>"
					aria-pressed="false"
					hidden
				><?php esc_html_e( 'Pausar', 'huexs-google-reviews' ); ?></button>
			<?php endif; ?>
			<button type="button" class="hgr-carousel__btn" data-hgr-prev aria-label="<?php esc_attr_e( 'Reseña anterior', 'huexs-google-reviews' ); ?>" hidden>&#8592;</button>
			<button type="button" class="hgr-carousel__btn" data-hgr-next aria-label="<?php esc_attr_e( 'Reseña siguiente', 'huexs-google-reviews' ); ?>" hidden>&#8594;</button>
		</div>
		<div class="hgr-carousel__track" data-hgr-track tabindex="0">
			<?php foreach ( $reviews as $hgr_index => $review ) : ?>
				<div
					class="hgr-carousel__slide"
					role="group"
					aria-roledescription="diapositiva"
					aria-label="<?php echo esc_attr( sprintf( '%1$d / %2$d', $hgr_index + 1, count( $reviews ) ) ); ?>"
				>
					<?php echo $renderer->renderCard( $review, $args ); // phpcs:ignore WordPress.Security.EscapeOutput -- plantilla interna escapada. ?>
				</div>
			<?php endforeach; ?>
		</div>
	</section>
	<?php echo $renderer->attributionHtml(); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML interno escapado. ?>
</div>
