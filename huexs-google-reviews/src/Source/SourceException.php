<?php
/**
 * Excepción normalizada de cualquier fuente de reseñas.
 *
 * Códigos: invalid_license, plan_limit, not_found, rate_limited, upstream_error,
 * configuration_error, auth_expired, permission_denied, quota, invalid_response,
 * remote_unavailable, not_supported.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Source;

class SourceException extends \RuntimeException {

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

	/** Mensaje accionable para el administrador, según el código. */
	public function suggestedAction(): string {
		return match ( $this->errorCode ) {
			'invalid_license'     => __( 'Revisa tu clave de licencia en Google Reviews → Conexión.', 'huexs-google-reviews' ),
			'plan_limit'          => __( 'Tu plan actual no cubre esta acción. Amplía el plan para continuar.', 'huexs-google-reviews' ),
			'not_found'           => __( 'El negocio ya no está accesible. Vuelve a buscarlo y selecciónalo de nuevo.', 'huexs-google-reviews' ),
			'rate_limited', 'quota' => __( 'Se ha alcanzado el límite de peticiones. Espera unos minutos y reintenta.', 'huexs-google-reviews' ),
			'auth_expired'        => __( 'La conexión con Google ha caducado. Reconecta la cuenta.', 'huexs-google-reviews' ),
			'permission_denied'   => __( 'Sin permisos sobre esta ficha. Comprueba que la cuenta la administra.', 'huexs-google-reviews' ),
			'configuration_error' => __( 'Falta configuración. Revisa la pantalla Conexión.', 'huexs-google-reviews' ),
			default               => __( 'Reintenta más tarde. Si persiste, revisa la pantalla Estado.', 'huexs-google-reviews' ),
		};
	}
}
