<?php
/**
 * Diseño cuadrícula.
 *
 * Variables: $reviews (object[]), $args, $summary (?array), $settings, $renderer.
 *
 * @package Huexs\GoogleReviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="<?php echo esc_attr( $renderer->wrapClasses( 'grid' ) ); ?>" style="<?php echo esc_attr( $renderer->styleVars() ); ?>">
	<?php
	if ( null !== $summary && null !== $summary['average'] ) {
		$standalone = false;
		include __DIR__ . '/rating-summary.php';
	}
	?>
	<div class="hgr-grid">
		<?php foreach ( $reviews as $review ) : ?>
			<?php echo $renderer->renderCard( $review, $args ); // phpcs:ignore WordPress.Security.EscapeOutput -- plantilla interna escapada. ?>
		<?php endforeach; ?>
	</div>
	<?php echo $renderer->attributionHtml(); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML interno escapado. ?>
</div>
