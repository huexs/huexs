<?php
/**
 * Catálogo de diseños disponibles.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Frontend;

final class Layouts {

	public const LIST     = 'list';
	public const GRID     = 'grid';
	public const CAROUSEL = 'carousel';
	public const BADGE    = 'badge';
	public const FLOATING = 'floating';
	public const SIDEBAR  = 'sidebar';

	public const ALL = array( self::LIST, self::GRID, self::CAROUSEL, self::BADGE, self::FLOATING, self::SIDEBAR );

	/** Diseños que muestran tarjetas de reseñas (frente a los que solo resumen). */
	public const WITH_CARDS = array( self::LIST, self::GRID, self::CAROUSEL, self::SIDEBAR, self::FLOATING );

	/** Diseños que solo necesitan nota media y número de reseñas. */
	public const SUMMARY_ONLY = array( self::BADGE );

	/** @return array<string, string> Clave => etiqueta legible. */
	public static function labels(): array {
		return array(
			self::GRID     => __( 'Cuadrícula', 'huexs-google-reviews' ),
			self::LIST     => __( 'Lista', 'huexs-google-reviews' ),
			self::CAROUSEL => __( 'Carrusel', 'huexs-google-reviews' ),
			self::BADGE    => __( 'Insignia (nota + nº de reseñas)', 'huexs-google-reviews' ),
			self::FLOATING => __( 'Burbuja flotante', 'huexs-google-reviews' ),
			self::SIDEBAR  => __( 'Columna lateral', 'huexs-google-reviews' ),
		);
	}

	public static function isValid( string $layout ): bool {
		return in_array( $layout, self::ALL, true );
	}

	public static function template( string $layout ): string {
		return match ( $layout ) {
			self::LIST     => 'reviews-list.php',
			self::CAROUSEL => 'reviews-carousel.php',
			self::BADGE    => 'reviews-badge.php',
			self::FLOATING => 'reviews-floating.php',
			self::SIDEBAR  => 'reviews-sidebar.php',
			default        => 'reviews-grid.php',
		};
	}

	public static function needsReviews( string $layout ): bool {
		return in_array( $layout, self::WITH_CARDS, true );
	}
}
