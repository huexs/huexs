<?php

declare(strict_types=1);

namespace Huexs\GoogleReviews\Tests\Unit;

use Huexs\GoogleReviews\Frontend\Layouts;
use Huexs\GoogleReviews\Frontend\ReviewRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Renderiza cada diseño con contenido hostil para comprobar que no se escapa nada al HTML.
 */
final class TemplateRenderTest extends TestCase {

	private const XSS_TEXT = '<script>alert("xss")</script><img src=x onerror=alert(1)>';

	private array $settings = array(
		'default_layout'   => 'grid',
		'default_limit'    => 6,
		'show_avatar'      => true,
		'show_date'        => true,
		'show_reply'       => true,
		'show_google_logo' => true,
		'excerpt_lines'    => 5,
		'theme'            => 'light',
		'card_style'       => 'shadow',
		'accent_color'     => '#fbbc04',
		'text_color'       => '',
		'bg_color'         => '',
		'badge_position'   => 'bottom-right',
	);

	private function renderer( bool $branding = false ): ReviewRenderer {
		return new ReviewRenderer( $this->settings, $branding );
	}

	private function hostileReview(): object {
		return (object) array(
			'id'                 => 1,
			'location_id'        => 1,
			'google_review_id'   => 'rev-1',
			'reviewer_name'      => self::XSS_TEXT,
			'reviewer_photo_url' => 'javascript:alert(1)',
			'star_rating'        => 5,
			'comment'            => "Primera línea\n" . self::XSS_TEXT,
			'create_time'        => '2026-07-01 10:00:00',
			'update_time'        => null,
			'reply_comment'      => self::XSS_TEXT,
			'reply_update_time'  => null,
			'last_seen_at'       => '2026-08-11 10:00:00',
			'location_title'     => self::XSS_TEXT,
			'public_google_url'  => 'https://maps.google.com/?cid=1',
		);
	}

	private function summary(): array {
		return array(
			'average'        => 4.6,
			'count'          => 210,
			'multi_location' => false,
			'public_url'     => 'https://maps.google.com/?cid=1',
			'name'           => self::XSS_TEXT,
		);
	}

	private function args(): array {
		return array(
			'show_avatar'  => true,
			'show_date'    => true,
			'show_reply'   => true,
			'show_summary' => true,
			'show_count'   => true,
			'position'     => 'bottom-right',
		);
	}

	public function testEveryLayoutRendersWithoutError(): void {
		foreach ( Layouts::ALL as $layout ) {
			$html = $this->renderer()->renderLayout( $layout, array( $this->hostileReview() ), $this->args(), $this->summary() );

			self::assertNotSame( '', $html, 'El diseño ' . $layout . ' no renderizó nada.' );
			self::assertStringContainsString( 'hgr-layout--' . $layout, $html );
		}
	}

	public function testMaliciousContentIsEscapedInEveryLayout(): void {
		foreach ( Layouts::ALL as $layout ) {
			$html = $this->renderer()->renderLayout( $layout, array( $this->hostileReview() ), $this->args(), $this->summary() );

			// Las formas crudas son las peligrosas; escapadas (&lt;script&gt;) son texto inerte.
			self::assertStringNotContainsString( '<script', $html, 'Etiqueta script sin escapar en ' . $layout );
			self::assertStringNotContainsString( '<img src=x', $html, 'Etiqueta img inyectada sin escapar en ' . $layout );
			self::assertStringNotContainsString( 'javascript:', $html, 'URL javascript: sin filtrar en ' . $layout );

			// Y la carga hostil sí debe estar presente, pero escapada.
			self::assertStringContainsString( '&lt;script&gt;', $html, 'El contenido hostil debería aparecer escapado en ' . $layout );
		}
	}

	public function testNonHttpsAvatarFallsBackToPlaceholder(): void {
		$html = $this->renderer()->renderLayout( Layouts::GRID, array( $this->hostileReview() ), $this->args(), null );

		self::assertStringContainsString( 'hgr-card__avatar--placeholder', $html );
		self::assertStringNotContainsString( '<img', $html, 'Una URL de avatar no HTTPS no debe generar ninguna etiqueta img.' );
	}

	public function testCommentKeepsLineBreaksAsMarkup(): void {
		$html = $this->renderer()->renderLayout( Layouts::GRID, array( $this->hostileReview() ), $this->args(), null );

		self::assertStringContainsString( '<br>', $html, 'Los saltos de línea deben convertirse en <br>.' );
		self::assertStringContainsString( 'Primera línea', $html );
	}

	public function testFullCommentIsAlwaysPresentInTheDom(): void {
		$review          = $this->hostileReview();
		$review->comment = str_repeat( 'Texto muy largo que supera el recorte visual. ', 40 );

		$html = $this->renderer()->renderLayout( Layouts::GRID, array( $review ), $this->args(), null );

		// El recorte es solo visual (CSS + JS): el texto completo sigue en el DOM accesible.
		self::assertSame(
			40,
			substr_count( $html, 'Texto muy largo que supera el recorte visual.' ),
			'El comentario debe conservarse íntegro en el DOM.'
		);
	}

	public function testBadgeRendersWithoutAnyReview(): void {
		$html = $this->renderer()->renderLayout( Layouts::BADGE, array(), $this->args(), $this->summary() );

		self::assertStringContainsString( 'hgr-badge', $html );
		self::assertStringContainsString( '4,6', $html, 'La insignia debe mostrar la nota media localizada.' );
		self::assertStringContainsString( '210', $html, 'La insignia debe mostrar el total real de reseñas.' );
	}

	public function testBrandingAppearsOnlyWhenPlanRequiresIt(): void {
		$withBranding = $this->renderer( true )->renderLayout( Layouts::GRID, array( $this->hostileReview() ), $this->args(), null );
		$noBranding   = $this->renderer( false )->renderLayout( Layouts::GRID, array( $this->hostileReview() ), $this->args(), null );

		self::assertStringContainsString( 'Huexs', $withBranding );
		self::assertStringNotContainsString( 'por Huexs', $noBranding );
	}

	public function testGoogleAttributionIsAlwaysPresent(): void {
		foreach ( array( Layouts::GRID, Layouts::LIST, Layouts::CAROUSEL, Layouts::SIDEBAR, Layouts::FLOATING ) as $layout ) {
			$html = $this->renderer()->renderLayout( $layout, array( $this->hostileReview() ), $this->args(), null );
			self::assertStringContainsString( 'Reseñas de Google', $html, 'Falta la atribución a Google en ' . $layout );
		}
	}

	public function testThemeAndCardStyleReachTheMarkup(): void {
		$settings               = $this->settings;
		$settings['theme']      = 'dark';
		$settings['card_style'] = 'flat';

		$html = ( new ReviewRenderer( $settings ) )->renderLayout( Layouts::GRID, array( $this->hostileReview() ), $this->args(), null );

		self::assertStringContainsString( 'hgr-theme--dark', $html );
		self::assertStringContainsString( 'hgr-cards--flat', $html );
	}

	public function testInvalidColorIsNotInjectedIntoStyleAttribute(): void {
		$settings                 = $this->settings;
		$settings['accent_color'] = 'red; background:url(javascript:alert(1))';

		$html = ( new ReviewRenderer( $settings ) )->renderLayout( Layouts::GRID, array( $this->hostileReview() ), $this->args(), null );

		self::assertStringNotContainsString( 'javascript:', $html );
		self::assertStringNotContainsString( '--hgr-accent:red', $html );
	}

	public function testStarsExposeAccessibleLabel(): void {
		$html = $this->renderer()->starsHtml( 4.0 );

		self::assertStringContainsString( 'role="img"', $html );
		self::assertStringContainsString( 'de 5 estrellas', $html );
	}
}
