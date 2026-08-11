<?php
/**
 * Transporte HTTP inyectable, para poder sustituirlo por un doble en pruebas.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Support;

use Huexs\GoogleReviews\Source\SourceException;

interface HttpClientInterface {

	/**
	 * @return array Cuerpo JSON decodificado.
	 * @throws SourceException
	 */
	public function getJson( string $url, array $headers = array() ): array;

	/**
	 * @return array Cuerpo JSON decodificado.
	 * @throws SourceException
	 */
	public function postJson( string $url, array $body, array $headers = array() ): array;
}
