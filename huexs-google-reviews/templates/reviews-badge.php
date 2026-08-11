<?php
/**
 * Diseño insignia: bloque compacto con nota media y número de reseñas.
 * No necesita reseñas individuales, solo el resumen.
 *
 * Variables: $summary (array), $args, $settings, $renderer.
 *
 * @package Huexs\GoogleReviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$hgr_has_link = ! empty( $summary['public_url'] ) && str_starts_with( (string) $summary['public_url'], 'https://' );
$hgr_tag      = $hgr_has_link ? 'a' : 'div';
?>
<div class="<?php echo esc_attr( $renderer->wrapClasses( 'badge' ) ); ?>" style="<?php echo esc_attr( $renderer->styleVars() ); ?>">
	<<?php echo esc_attr( $hgr_tag ); ?>
		class="hgr-badge"
		<?php if ( $hgr_has_link ) : ?>
			href="<?php echo esc_url( $summary['public_url'] ); ?>" target="_blank" rel="noopener noreferrer"
		<?php endif; ?>
	>
		<span class="hgr-badge__logo"><?php echo $renderer->googleLogoHtml(); // phpcs:ignore WordPress.Security.EscapeOutput -- SVG interno. ?></span>

		<span class="hgr-badge__body">
			<span class="hgr-badge__title">
				<?php
				echo esc_html(
					'' !== (string) ( $summary['name'] ?? '' )
						? (string) $summary['name']
						: __( 'Reseñas de Google', 'huexs-google-reviews' )
				);
				?>
			</span>
			<span class="hgr-badge__rating">
				<span class="hgr-badge__average"><?php echo esc_html( number_format_i18n( (float) $summary['average'], 1 ) ); ?></span>
				<?php echo $renderer->starsHtml( (float) $summary['average'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML interno escapado. ?>
			</span>
			<?php if ( ! empty( $args['show_count'] ) ) : ?>
				<span class="hgr-badge__count">
					<?php
					printf(
						/* translators: %s: número de reseñas. */
						esc_html( _n( 'Basado en %s reseña', 'Basado en %s reseñas', (int) $summary['count'], 'huexs-google-reviews' ) ),
						esc_html( number_format_i18n( (int) $summary['count'] ) )
					);
					?>
				</span>
			<?php endif; ?>
		</span>
	</<?php echo esc_attr( $hgr_tag ); ?>>
</div>
