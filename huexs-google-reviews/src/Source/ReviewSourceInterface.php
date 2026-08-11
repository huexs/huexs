<?php
/**
 * Contrato de una fuente de reseñas.
 *
 * Implementaciones: HuexsApiSource (API central, por defecto) y
 * SelfHostedGoogleSource (proyecto propio de Google Cloud, modo avanzado).
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Source;

interface ReviewSourceInterface {

	public const SOURCE_HUEXS  = 'huexs';
	public const SOURCE_GOOGLE = 'google_oauth';

	/** Identificador persistido en la columna `source` de las ubicaciones. */
	public function id(): string;

	/** ¿Tiene la configuración mínima para operar (licencia / credenciales)? */
	public function isConfigured(): bool;

	/** ¿Permite buscar negocios por nombre? */
	public function supportsSearch(): bool;

	/**
	 * Busca negocios por nombre.
	 *
	 * @return BusinessResult[]
	 * @throws SourceException
	 */
	public function searchBusinesses( string $query ): array;

	/**
	 * Lee TODAS las reseñas de una ubicación. Pagina internamente.
	 *
	 * @param object $location Fila de {prefix}hgr_locations.
	 * @throws SourceException Si la lectura no puede completarse.
	 */
	public function fetchReviews( object $location ): LocationReviews;
}
