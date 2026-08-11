<?php
/**
 * Resumen de valoración (bloque propio o cabecera de los listados).
 *
 * Variables: $summary (array{average,count,multi_location,public_url}), $args, $settings, $renderer.
 * Opcional: $standalone (bool).
 *
 * @package Huexs\GoogleReviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$hgr_is_standalone = ! empty( $standalone );
?>
<?php if ( $hgr_is_standalone ) : ?>
<div class="hgr-wrap <?php echo esc_attr( $renderer->themeClass() ); ?>" style="<?php echo esc_attr( $renderer->styleVars() ); ?>">
<?php endif; ?>
	<div class="hgr-summary">
		<span class="hgr-summary__average"><?php echo esc_html( number_format_i18n( (float) $summary['average'], 1 ) ); ?></span>
		<?php echo $renderer->starsHtml( (float) $summary['average'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML interno escapado. ?>
		<?php if ( ! empty( $args['show_count'] ) ) : ?>
			<span class="hgr-summary__count">
				<?php
				printf(
					/* translators: %s: número de reseñas. */
					esc_html( _n( '%s reseña', '%s reseñas', (int) $summary['count'], 'huexs-google-reviews' ) ),
					esc_html( number_format_i18n( (int) $summary['count'] ) )
				);
				?>
			</span>
		<?php endif; ?>
		<?php if ( ! empty( $summary['multi_location'] ) ) : ?>
			<span class="hgr-summary__multi"><?php esc_html_e( 'Valoración combinada de varias ubicaciones', 'huexs-google-reviews' ); ?></span>
		<?php endif; ?>
		<?php if ( ! empty( $summary['public_url'] ) && str_starts_with( (string) $summary['public_url'], 'https://' ) ) : ?>
			<a class="hgr-summary__link" href="<?php echo esc_url( $summary['public_url'] ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Ver en Google', 'huexs-google-reviews' ); ?>
			</a>
		<?php endif; ?>
	</div>
<?php if ( $hgr_is_standalone ) : ?>
	<?php echo $renderer->attributionHtml(); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML interno escapado. ?>
</div>
<?php endif; ?>
