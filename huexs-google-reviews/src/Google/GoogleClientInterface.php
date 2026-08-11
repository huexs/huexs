<?php
/**
 * Contrato de la capa de transporte con Google, sustituible por un fake en tests.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Google;

use Huexs\GoogleReviews\Google\Dto\AccountDto;
use Huexs\GoogleReviews\Google\Dto\LocationDto;
use Huexs\GoogleReviews\Google\Dto\ReviewsPage;

interface GoogleClientInterface {

	/**
	 * Todas las cuentas accesibles (pagina internamente).
	 *
	 * @return AccountDto[]
	 * @throws GoogleApiException
	 */
	public function listAccounts(): array;

	/**
	 * Todas las ubicaciones de una cuenta (pagina internamente).
	 *
	 * @return LocationDto[]
	 * @throws GoogleApiException
	 */
	public function listLocations( string $accountName ): array;

	/**
	 * Una página de reseñas de una ubicación.
	 *
	 * @param string      $accountName  "accounts/123"
	 * @param string      $locationId   "456"
	 * @param string|null $pageToken
	 * @throws GoogleApiException
	 */
	public function listReviewsPage( string $accountName, string $locationId, ?string $pageToken = null ): ReviewsPage;
}
