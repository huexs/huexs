<?php

declare(strict_types=1);

namespace Huexs\GoogleReviews\Tests\Unit;

use Huexs\GoogleReviews\Frontend\Layouts;
use Huexs\GoogleReviews\Frontend\Shortcodes;
use PHPUnit\Framework\TestCase;

final class ShortcodeAttsTest extends TestCase {

	private array $settings = array(
		'default_layout'            => 'grid',
		'badge_position'            => 'bottom-right',
		'carousel_autoplay'         => true,
		'carousel_autoplay_seconds' => 5,
		'default_limit'  => 6,
		'show_avatar'    => true,
		'show_date'      => true,
		'show_reply'     => true,
	);

	public function testDefaults(): void {
		$atts = Shortcodes::normalizeAtts( array(), $this->settings );

		self::assertSame( 'all', $atts['location'] );
		self::assertSame( 'grid', $atts['layout'] );
		self::assertSame( 6, $atts['limit'] );
		self::assertSame( 'newest', $atts['order'] );
		self::assertTrue( $atts['show_avatar'] );
	}

	public function testLayoutAllowlistRejectsArbitraryValues(): void {
		$atts = Shortcodes::normalizeAtts( array( 'layout' => '../../etc/passwd' ), $this->settings );
		self::assertSame( 'grid', $atts['layout'] );

		$atts = Shortcodes::normalizeAtts( array( 'layout' => 'carousel' ), $this->settings );
		self::assertSame( 'carousel', $atts['layout'] );
	}

	public function testAllCatalogLayoutsAreAccepted(): void {
		foreach ( Layouts::ALL as $layout ) {
			self::assertSame(
				$layout,
				Shortcodes::normalizeAtts( array( 'layout' => $layout ), $this->settings )['layout']
			);
		}
	}

	public function testEveryLayoutMapsToAnExistingTemplate(): void {
		foreach ( Layouts::ALL as $layout ) {
			self::assertFileExists(
				dirname( __DIR__, 2 ) . '/templates/' . Layouts::template( $layout ),
				'Falta la plantilla del diseño ' . $layout
			);
		}
	}

	public function testOnlyBadgeSkipsReviewLookup(): void {
		self::assertFalse( Layouts::needsReviews( Layouts::BADGE ) );
		foreach ( array( Layouts::GRID, Layouts::LIST, Layouts::CAROUSEL, Layouts::SIDEBAR, Layouts::FLOATING ) as $layout ) {
			self::assertTrue( Layouts::needsReviews( $layout ) );
		}
	}

	public function testCarouselAutoplayDefaultsFromSettings(): void {
		$atts = Shortcodes::normalizeAtts( array(), $this->settings );

		self::assertTrue( $atts['autoplay'] );
		self::assertSame( 5, $atts['autoplay_seconds'] );
	}

	public function testCarouselAutoplayCanBeDisabledPerShortcode(): void {
		self::assertFalse( Shortcodes::normalizeAtts( array( 'autoplay' => 'false' ), $this->settings )['autoplay'] );
	}

	public function testAutoplayIntervalIsClamped(): void {
		// Por debajo de 2 s no da tiempo a leer; por encima de 30 parece estático.
		self::assertSame( 2, Shortcodes::normalizeAtts( array( 'autoplay_seconds' => '0' ), $this->settings )['autoplay_seconds'] );
		self::assertSame( 30, Shortcodes::normalizeAtts( array( 'autoplay_seconds' => '9999' ), $this->settings )['autoplay_seconds'] );
		self::assertSame( 8, Shortcodes::normalizeAtts( array( 'autoplay_seconds' => '8' ), $this->settings )['autoplay_seconds'] );
	}

	public function testFloatingPositionAllowlist(): void {
		self::assertSame( 'top-left', Shortcodes::normalizeAtts( array( 'position' => 'top-left' ), $this->settings )['position'] );
		self::assertSame( 'bottom-right', Shortcodes::normalizeAtts( array( 'position' => 'javascript:alert(1)' ), $this->settings )['position'] );
	}

	public function testOrderAllowlist(): void {
		self::assertSame( 'oldest', Shortcodes::normalizeAtts( array( 'order' => 'OLDEST' ), $this->settings )['order'] );
		self::assertSame( 'newest', Shortcodes::normalizeAtts( array( 'order' => 'rating; DROP TABLE' ), $this->settings )['order'] );
	}

	public function testLimitIsClamped(): void {
		self::assertSame( 1, Shortcodes::normalizeAtts( array( 'limit' => '-5' ), $this->settings )['limit'] );
		self::assertSame( 50, Shortcodes::normalizeAtts( array( 'limit' => '500' ), $this->settings )['limit'] );
		self::assertSame( 12, Shortcodes::normalizeAtts( array( 'limit' => '12' ), $this->settings )['limit'] );
	}

	public function testLocationOnlyAcceptsIdsOrAll(): void {
		self::assertSame( 'all', Shortcodes::normalizeAtts( array( 'location' => 'all' ), $this->settings )['location'] );
		self::assertSame( '7', Shortcodes::normalizeAtts( array( 'location' => '7' ), $this->settings )['location'] );
		self::assertSame( 'all', Shortcodes::normalizeAtts( array( 'location' => "1'; DROP TABLE wp_users; --" ), $this->settings )['location'] );
	}

	public function testBooleanNormalization(): void {
		self::assertFalse( Shortcodes::normalizeAtts( array( 'show_avatar' => 'false' ), $this->settings )['show_avatar'] );
		self::assertFalse( Shortcodes::normalizeAtts( array( 'show_avatar' => '0' ), $this->settings )['show_avatar'] );
		self::assertTrue( Shortcodes::normalizeAtts( array( 'show_avatar' => 'yes' ), $this->settings )['show_avatar'] );
		self::assertTrue( Shortcodes::normalizeAtts( array( 'show_avatar' => 'sí' ), $this->settings )['show_avatar'] );
		// Valor no reconocido → default.
		self::assertTrue( Shortcodes::normalizeAtts( array( 'show_avatar' => 'quizás' ), $this->settings )['show_avatar'] );
	}
}
