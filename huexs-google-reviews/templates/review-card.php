<?php
/**
 * Tarjeta de una reseña individual.
 *
 * Variables: $review (object), $args (array), $settings (array), $renderer (ReviewRenderer).
 *
 * @package Huexs\GoogleReviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<article class="hgr-card">
	<header class="hgr-card__header">
		<?php if ( ! empty( $args['show_avatar'] ) && ! empty( $review->reviewer_photo_url ) && str_starts_with( (string) $review->reviewer_photo_url, 'https://' ) ) : ?>
			<img
				class="hgr-card__avatar"
				src="<?php echo esc_url( $review->reviewer_photo_url ); ?>"
				alt=""
				loading="lazy"
				referrerpolicy="no-referrer"
				width="40"
				height="40"
			/>
		<?php else : ?>
			<span class="hgr-card__avatar hgr-card__avatar--placeholder" aria-hidden="true"><?php echo esc_html( mb_substr( (string) ( $review->reviewer_name ?? '?' ), 0, 1 ) ); ?></span>
		<?php endif; ?>
		<div class="hgr-card__meta">
			<span class="hgr-card__author"><?php echo esc_html( $review->reviewer_name ?? __( 'Usuario de Google', 'huexs-google-reviews' ) ); ?></span>
			<?php if ( ! empty( $args['show_date'] ) && ! empty( $review->create_time ) ) : ?>
				<time class="hgr-card__date" datetime="<?php echo esc_attr( str_replace( ' ', 'T', (string) $review->create_time ) . 'Z' ); ?>">
					<?php echo esc_html( $renderer->formatDate( (string) $review->create_time ) ); ?>
				</time>
			<?php endif; ?>
		</div>
	</header>

	<?php echo $renderer->starsHtml( (float) $review->star_rating ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML generado y escapado internamente. ?>

	<?php if ( ! empty( $review->comment ) ) : ?>
		<div class="hgr-card__comment" data-hgr-clamp>
			<?php echo $renderer->commentHtml( (string) $review->comment ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en commentHtml(). ?>
		</div>
		<button
			type="button"
			class="hgr-card__more"
			data-hgr-more
			data-more-label="<?php esc_attr_e( 'Leer más', 'huexs-google-reviews' ); ?>"
			data-less-label="<?php esc_attr_e( 'Leer menos', 'huexs-google-reviews' ); ?>"
			hidden
			aria-expanded="false"
		>
			<?php esc_html_e( 'Leer más', 'huexs-google-reviews' ); ?>
		</button>
	<?php endif; ?>

	<?php if ( ! empty( $args['show_reply'] ) && ! empty( $review->reply_comment ) ) : ?>
		<blockquote class="hgr-card__reply">
			<span class="hgr-card__reply-label"><?php esc_html_e( 'Respuesta del propietario', 'huexs-google-reviews' ); ?></span>
			<div><?php echo $renderer->commentHtml( (string) $review->reply_comment ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en commentHtml(). ?></div>
		</blockquote>
	<?php endif; ?>
</article>
