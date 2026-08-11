<?php
/**
 * Registro de fuentes disponibles. Cada ubicación recuerda con qué fuente se creó.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Source;

class SourceRegistry {

	/** @var array<string, ReviewSourceInterface> */
	private array $sources = array();

	private string $defaultId = ReviewSourceInterface::SOURCE_HUEXS;

	public function add( ReviewSourceInterface $source ): void {
		$this->sources[ $source->id() ] = $source;
	}

	public function setDefault( string $id ): void {
		$this->defaultId = $id;
	}

	/** @throws SourceException Si la fuente no está registrada. */
	public function get( string $id ): ReviewSourceInterface {
		if ( ! isset( $this->sources[ $id ] ) ) {
			throw new SourceException( 'configuration_error', 'Fuente de reseñas desconocida: ' . $id );
		}
		return $this->sources[ $id ];
	}

	public function default(): ReviewSourceInterface {
		return $this->get( $this->defaultId );
	}

	public function has( string $id ): bool {
		return isset( $this->sources[ $id ] );
	}

	/** @return ReviewSourceInterface[] */
	public function all(): array {
		return array_values( $this->sources );
	}
}
