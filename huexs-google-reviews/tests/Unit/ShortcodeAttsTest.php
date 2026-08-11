<?php

declare(strict_types=1);

namespace Huexs\GoogleReviews\Tests\Unit;

use Huexs\GoogleReviews\Frontend\Shortcodes;
use PHPUnit\Framework\TestCase;

final class ShortcodeAttsTest extends TestCase {

	private array $settings = array(
		'default_layout' => 'grid',
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
