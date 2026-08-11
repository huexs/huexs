<?php

declare(strict_types=1);

namespace Huexs\GoogleReviews\Tests\Unit;

use Huexs\GoogleReviews\Admin\ConnectionPage;
use PHPUnit\Framework\TestCase;

/**
 * Los errores de Google incluyen la URL exacta de la consola para resolverlos.
 * Se convierte en enlace, pero el texto sigue siendo contenido no confiable.
 */
final class LinkifyTest extends TestCase {

	public function testConvertsUrlIntoLink(): void {
		$html = ConnectionPage::linkify(
			'Enable it by visiting https://console.developers.google.com/apis/api/places.googleapis.com/overview?project=123 then retry.'
		);

		self::assertStringContainsString(
			'<a href="https://console.developers.google.com/apis/api/places.googleapis.com/overview?project=123"',
			$html
		);
		self::assertStringContainsString( 'rel="noopener noreferrer"', $html );
	}

	public function testTrailingPunctuationStaysOutsideTheLink(): void {
		$html = ConnectionPage::linkify( 'Visita https://example.com/ruta.' );

		self::assertStringContainsString( '>https://example.com/ruta</a>.', $html );
	}

	public function testSurroundingTextIsEscaped(): void {
		$html = ConnectionPage::linkify( '<script>alert(1)</script> visita https://example.com/x' );

		self::assertStringNotContainsString( '<script', $html );
		self::assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function testMarkupInsideTheUrlCannotBreakOut(): void {
		// Una URL con comillas no debe poder cerrar el atributo href.
		$html = ConnectionPage::linkify( 'https://example.com/"><img src=x onerror=alert(1)>' );

		self::assertStringNotContainsString( '<img', $html );
	}

	public function testPlainTextWithoutUrlsIsJustEscaped(): void {
		self::assertSame( 'Sin enlaces &amp; con &lt;s&gt;', ConnectionPage::linkify( 'Sin enlaces & con <s>' ) );
	}

	public function testHttpUrlsAreNotLinked(): void {
		// Solo HTTPS: un enlace http:// en un mensaje de error no aporta y es peor.
		$html = ConnectionPage::linkify( 'Mira http://inseguro.example.com/x' );

		self::assertStringNotContainsString( '<a href', $html );
	}
}
