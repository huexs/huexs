<?php
/**
 * Excepción normalizada de la capa Google.
 *
 * Códigos internos: auth_expired, permission_denied, quota, rate_limited,
 * remote_unavailable, invalid_response, configuration_error.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Google;

class GoogleApiException extends \RuntimeException {

	public function __construct(
		private string $errorCode,
		string $message,
		?\Throwable $previous = null
	) {
		parent::__construct( $message, 0, $previous );
	}

	public function errorCode(): string {
		return $this->errorCode;
	}
}
