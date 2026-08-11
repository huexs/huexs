<?php
/**
 * Renderizado de reseñas: carga plantillas del plugin con datos ya preparados.
 * Las plantillas escapan en el punto de salida y no tocan la base de datos.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Frontend;

class ReviewRenderer {

	public function __construct( private array $settings ) {}

	/**
	 * @param object[]   $reviews
	 * @param array      $args    Atributos normalizados del shortcode.
	 * @param array|null $summary Datos del resumen (average, count, multi_location, public_url) o null.
	 */
	public function renderLayout( string $layout, array $reviews, array $args, ?array $summary ): string {
		$template = match ( $layout ) {
			'list'     => 'reviews-list.php',
			'carousel' => 'reviews-carousel.php',
			default    => 'reviews-grid.php',
		};
		return $this->loadTemplate(
			$template,
			array(
				'reviews'  => $reviews,
				'args'     => $args,
				'summary'  => $summary,
				'settings' => $this->settings,
				'renderer' => $this,
			)
		);
	}

	public function renderSummary( array $summary, array $args ): string {
		return $this->loadTemplate(
			'rating-summary.php',
			array(
				'summary'  => $summary,
				'args'     => $args,
				'settings' => $this->settings,
				'renderer' => $this,
				'standalone' => true,
			)
		);
	}

	public function renderCard( object $review, array $args ): string {
		return $this->loadTemplate(
			'review-card.php',
			array(
				'review'   => $review,
				'args'     => $args,
				'settings' => $this->settings,
				'renderer' => $this,
			)
		);
	}

	/** Estrellas accesibles: representación visual + texto para lectores de pantalla. */
	public function starsHtml( float $rating, ?int $outOf = 5 ): string {
		$full = (int) floor( $rating + 0.001 );
		$html = '<span class="hgr-stars" role="img" aria-label="' . esc_attr(
			sprintf(
				/* translators: 1: puntuación, 2: máximo. */
				__( '%1$s de %2$d estrellas', 'huexs-google-reviews' ),
				rtrim( rtrim( number_format_i18n( $rating, 1 ), '0' ), ',.' ),
				$outOf
			)
		) . '">';
		for ( $i = 1; $i <= $outOf; $i++ ) {
			$class = $i <= $full ? 'hgr-star hgr-star--full' : 'hgr-star hgr-star--empty';
			$html .= '<svg class="' . esc_attr( $class ) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 17.27 18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>';
		}
		$html .= '</span>';
		return $html;
	}

	/** Fecha formateada con la zona horaria de WordPress. */
	public function formatDate( ?string $utcDatetime ): string {
		if ( null === $utcDatetime || '' === $utcDatetime ) {
			return '';
		}
		$timestamp = strtotime( $utcDatetime . ' UTC' );
		if ( false === $timestamp ) {
			return '';
		}
		return (string) wp_date( get_option( 'date_format', 'j F Y' ), $timestamp );
	}

	/** Comentario con saltos de línea seguros (texto plano escapado + <br>). */
	public function commentHtml( ?string $comment ): string {
		if ( null === $comment || '' === $comment ) {
			return '';
		}
		return nl2br( esc_html( $comment ), false );
	}

	/** Atribución a Google requerida por las políticas de presentación. */
	public function attributionHtml(): string {
		$logo = '';
		if ( ! empty( $this->settings['show_google_logo'] ) ) {
			$logo = '<svg class="hgr-google-g" viewBox="0 0 48 48" aria-hidden="true" focusable="false">'
				. '<path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>'
				. '<path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>'
				. '<path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>'
				. '<path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>'
				. '</svg>';
		}
		return '<div class="hgr-attribution">' . $logo
			. '<span>' . esc_html__( 'Reseñas de Google', 'huexs-google-reviews' ) . '</span></div>';
	}

	/** Variables CSS limitadas derivadas de los ajustes de presentación. */
	public function styleVars(): string {
		$vars = array();
		$accent = $this->sanitizeColor( (string) ( $this->settings['accent_color'] ?? '' ) );
		if ( $accent ) {
			$vars[] = '--hgr-accent:' . $accent;
		}
		$text = $this->sanitizeColor( (string) ( $this->settings['text_color'] ?? '' ) );
		if ( $text ) {
			$vars[] = '--hgr-text:' . $text;
		}
		$bg = $this->sanitizeColor( (string) ( $this->settings['bg_color'] ?? '' ) );
		if ( $bg ) {
			$vars[] = '--hgr-card-bg:' . $bg;
		}
		$lines = (int) ( $this->settings['excerpt_lines'] ?? 0 );
		if ( $lines > 0 ) {
			$vars[] = '--hgr-clamp-lines:' . $lines;
		}
		return implode( ';', $vars );
	}

	public function themeClass(): string {
		$theme = (string) ( $this->settings['theme'] ?? 'light' );
		if ( ! in_array( $theme, array( 'light', 'dark', 'transparent' ), true ) ) {
			$theme = 'light';
		}
		return 'hgr-theme--' . $theme;
	}

	private function sanitizeColor( string $color ): string {
		$color = trim( $color );
		return preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color ) ? $color : '';
	}

	private function loadTemplate( string $file, array $data ): string {
		$path = HGR_PLUGIN_DIR . 'templates/' . $file;
		if ( ! is_readable( $path ) ) {
			return '';
		}
		ob_start();
		( static function ( $__path, $__data ) {
			extract( $__data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
			include $__path;
		} )( $path, $data );
		return (string) ob_get_clean();
	}
}
