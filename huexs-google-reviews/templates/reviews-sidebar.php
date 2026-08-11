<?php
/**
 * Diseño columna lateral: pensado para widgets/barras estrechas.
 * Una sola columna compacta con desplazamiento vertical propio.
 *
 * Variables: $reviews (object[]), $args, $summary (?array), $settings, $renderer.
 *
 * @package Huexs\GoogleReviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="<?php echo esc_attr( $renderer->wrapClasses( 'sidebar' ) ); ?>" style="<?php echo esc_attr( $renderer->styleVars() ); ?>">
	<?php
	if ( null !== $summary && null !== $summary['average'] ) {
		$standalone = false;
		include __DIR__ . '/rating-summary.php';
	}
	?>
	<div class="hgr-sidebar" role="list" tabindex="0" aria-label="<?php esc_attr_e( 'Reseñas de Google', 'huexs-google-reviews' ); ?>">
		<?php foreach ( $reviews as $review ) : ?>
			<div role="listitem">
				<?php echo $renderer->renderCard( $review, $args ); // phpcs:ignore WordPress.Security.EscapeOutput -- plantilla interna escapada. ?>
			</div>
		<?php endforeach; ?>
	</div>
	<?php echo $renderer->attributionHtml(); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML interno escapado. ?>
</div>
