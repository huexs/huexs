<?php
/**
 * Diseño lista.
 *
 * Variables: $reviews (object[]), $args, $summary (?array), $settings, $renderer.
 *
 * @package Huexs\GoogleReviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="hgr-wrap hgr-layout--list <?php echo esc_attr( $renderer->themeClass() ); ?>" style="<?php echo esc_attr( $renderer->styleVars() ); ?>">
	<?php
	if ( null !== $summary && null !== $summary['average'] ) {
		$standalone = false;
		include __DIR__ . '/rating-summary.php';
	}
	?>
	<div class="hgr-list" role="list">
		<?php foreach ( $reviews as $review ) : ?>
			<div role="listitem">
				<?php echo $renderer->renderCard( $review, $args ); // phpcs:ignore WordPress.Security.EscapeOutput -- plantilla interna escapada. ?>
			</div>
		<?php endforeach; ?>
	</div>
	<?php echo $renderer->attributionHtml(); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML interno escapado. ?>
</div>
