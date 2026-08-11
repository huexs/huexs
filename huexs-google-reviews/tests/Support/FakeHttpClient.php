<?php

declare(strict_types=1);

namespace Huexs\GoogleReviews\Tests\Support;

use Huexs\GoogleReviews\Source\SourceException;
use Huexs\GoogleReviews\Support\HttpClientInterface;

/**
 * Doble del transporte HTTP: responde con cargas fijas por URL (coincidencia por subcadena).
 */
final class FakeHttpClient implements HttpClientInterface {

	/** @var array<int, array{needle:string, payload:array}> */
	private array $responses = array();

	/** @var array<int, array{needle:string, exception:SourceException}> */
	private array $failures = array();

	/** @var string[] URLs solicitadas, en orden. */
	public array $requestedUrls = array();

	/** @var array<int, array> Cabeceras de cada petición. */
	public array $sentHeaders = array();

	public function on( string $needle, array $payload ): void {
		$this->responses[] = array(
			'needle'  => $needle,
			'payload' => $payload,
		);
	}

	public function failOn( string $needle, string $code, string $message = 'Fallo simulado.' ): void {
		$this->failures[] = array(
			'needle'    => $needle,
			'exception' => new SourceException( $code, $message ),
		);
	}

	public function getJson( string $url, array $headers = array() ): array {
		return $this->resolve( $url, $headers );
	}

	public function postJson( string $url, array $body, array $headers = array() ): array {
		return $this->resolve( $url, $headers );
	}

	private function resolve( string $url, array $headers ): array {
		$this->requestedUrls[] = $url;
		$this->sentHeaders[]   = $headers;

		foreach ( $this->failures as $failure ) {
			if ( str_contains( $url, $failure['needle'] ) ) {
				throw $failure['exception'];
			}
		}
		foreach ( $this->responses as $index => $response ) {
			if ( str_contains( $url, $response['needle'] ) ) {
				// Cada carga se consume una vez, lo que permite simular paginación.
				unset( $this->responses[ $index ] );
				return $response['payload'];
			}
		}

		throw new SourceException( 'not_found', 'Sin respuesta simulada para ' . $url );
	}
}
