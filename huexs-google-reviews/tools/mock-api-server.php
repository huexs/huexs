<?php
/**
 * Servidor simulado de la API central de Huexs (docs/API_CONTRACT.md).
 *
 * Sirve para probar el plugin de extremo a extremo antes de que exista el backend real,
 * y como referencia ejecutable del contrato para quien lo implemente.
 *
 * Uso:
 *   php -S localhost:8787 tools/mock-api-server.php
 *
 * Y en wp-config.php del WordPress de pruebas:
 *   define( 'HGR_API_BASE_URL', 'http://localhost:8787/v1' );
 *
 * Clave de licencia válida en el simulador: HGR-DEMO-0000-0000
 * Usa la clave HGR-PRO-0000-0000 para simular el plan Pro.
 *
 * NO USAR EN PRODUCCIÓN: sin TLS, sin persistencia y con datos ficticios.
 *
 * @package Huexs\GoogleReviews
 */

declare(strict_types=1);

header( 'Content-Type: application/json; charset=utf-8' );

const DEMO_KEY = 'HGR-DEMO-0000-0000';
const PRO_KEY  = 'HGR-PRO-0000-0000';

/** Devuelve un error con la envoltura del contrato y corta la ejecución. */
function fail( int $status, string $code, string $message ): never {
	http_response_code( $status );
	echo json_encode( array( 'error' => array( 'code' => $code, 'message' => $message ) ), JSON_UNESCAPED_UNICODE );
	exit;
}

function bearer(): string {
	$headers = function_exists( 'getallheaders' ) ? getallheaders() : array();
	foreach ( $headers as $name => $value ) {
		if ( 0 === strcasecmp( $name, 'Authorization' ) && preg_match( '/^Bearer\s+(.+)$/i', (string) $value, $m ) ) {
			return trim( $m[1] );
		}
	}
	return '';
}

$key = bearer();
if ( ! in_array( $key, array( DEMO_KEY, PRO_KEY ), true ) ) {
	fail( 401, 'invalid_license', 'Clave de licencia no válida en el simulador. Usa ' . DEMO_KEY . '.' );
}

$isPro = ( PRO_KEY === $key );
$path  = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
parse_str( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY ), $query );

/** Catálogo ficticio de negocios. */
$businesses = array(
	'ChIJ-demo-huexs-bcn' => array(
		'name'              => 'Huexs Señalética',
		'formatted_address' => "Carrer d'Exemple 12, 08001 Barcelona",
		'rating'            => 4.8,
		'review_count'      => 127,
		'public_url'        => 'https://maps.google.com/?cid=1111111111',
	),
	'ChIJ-demo-huexs-mad' => array(
		'name'              => 'Huexs Señalética Madrid',
		'formatted_address' => 'Calle de Ejemplo 45, 28001 Madrid',
		'rating'            => 4.6,
		'review_count'      => 63,
		'public_url'        => 'https://maps.google.com/?cid=2222222222',
	),
	'ChIJ-demo-cafe'      => array(
		'name'              => 'Cafè de Prova',
		'formatted_address' => 'Rambla Falsa 8, 08002 Barcelona',
		'rating'            => 4.2,
		'review_count'      => 341,
		'public_url'        => 'https://maps.google.com/?cid=3333333333',
	),
);

/** Genera reseñas deterministas para un negocio. */
function demo_reviews( string $placeId, int $count ): array {
	$authors  = array( 'María García', 'Andrés Müller', 'Lucía Pérez', 'Jordi Roca', 'Ana Sánchez', 'Tomás Ríos', 'Núria Vila', 'Carlos Ortiz' );
	$texts    = array(
		"Servicio excelente.\nMuy recomendable, volveré seguro.",
		'Trato cercano y resultado impecable. Ünïcödé ✓ 星',
		'Cumplieron plazos y el acabado es muy bueno.',
		null, // Reseña sin comentario: caso real que el plugin debe soportar.
		'Buena relación calidad-precio. Repetiré.',
		"Instalación rápida y limpia.\nGracias por todo.",
		'Correcto, aunque tardaron un poco más de lo previsto.',
		'Profesionales de primera. Sin quejas.',
	);
	$ratings  = array( 5, 4, 5, 3, 5, 4, 2, 5 );
	$reviews  = array();

	for ( $i = 0; $i < $count; $i++ ) {
		$index = $i % count( $authors );
		// Fecha determinista, descendente desde una base fija.
		$created = ( new DateTimeImmutable( '2026-08-01T12:00:00Z' ) )->modify( '-' . ( $i * 9 ) . ' days' );

		$review = array(
			'id'               => 'demo-' . substr( sha1( $placeId . '|' . $i ), 0, 16 ),
			'author_name'      => $authors[ $index ],
			'author_photo_url' => 0 === $i % 3 ? null : 'https://lh3.googleusercontent.com/a/demo-' . $index,
			'rating'           => $ratings[ $index ],
			'text'             => $texts[ $index ],
			'language'         => 'es',
			'created_at'       => $created->format( DATE_RFC3339 ),
			'updated_at'       => $created->format( DATE_RFC3339 ),
			'reply'            => null,
		);

		if ( 0 === $i % 4 ) {
			$review['reply'] = array(
				'text'       => '¡Muchas gracias por tu reseña! Un placer atenderte.',
				'updated_at' => $created->modify( '+2 days' )->format( DATE_RFC3339 ),
			);
		}

		$reviews[] = $review;
	}

	return $reviews;
}

// ---- Enrutado ----

if ( '/v1/account' === $path ) {
	echo json_encode(
		array(
			'plan'       => $isPro ? 'pro' : 'free',
			'status'     => 'active',
			'expires_at' => null,
			'limits'     => $isPro
				? array(
					'max_reviews'             => 200,
					'max_locations'           => 25,
					'layouts'                 => array( 'list', 'grid', 'carousel', 'badge', 'floating', 'sidebar' ),
					'branding_required'       => false,
					'min_sync_interval_hours' => 6,
				)
				: array(
					'max_reviews'             => 5,
					'max_locations'           => 1,
					'layouts'                 => array( 'list', 'grid', 'carousel', 'badge' ),
					'branding_required'       => true,
					'min_sync_interval_hours' => 24,
				),
		),
		JSON_UNESCAPED_UNICODE
	);
	exit;
}

if ( '/v1/businesses/search' === $path ) {
	$q = trim( (string) ( $query['q'] ?? '' ) );
	if ( mb_strlen( $q ) < 3 ) {
		fail( 400, 'configuration_error', 'La consulta debe tener al menos 3 caracteres.' );
	}

	$results = array();
	foreach ( $businesses as $placeId => $business ) {
		if ( false === mb_stripos( $business['name'] . ' ' . $business['formatted_address'], $q ) ) {
			continue;
		}
		$results[] = array(
			'place_id'          => $placeId,
			'name'              => $business['name'],
			'formatted_address' => $business['formatted_address'],
			'rating'            => $business['rating'],
			'review_count'      => $business['review_count'],
		);
	}

	echo json_encode( array( 'results' => $results ), JSON_UNESCAPED_UNICODE );
	exit;
}

if ( preg_match( '#^/v1/locations/([^/]+)/reviews$#', $path, $matches ) ) {
	$placeId = rawurldecode( $matches[1] );
	if ( ! isset( $businesses[ $placeId ] ) ) {
		fail( 404, 'not_found', 'Ese negocio no existe en el simulador.' );
	}

	$business = $businesses[ $placeId ];
	$maxTotal = $isPro ? min( 24, (int) $business['review_count'] ) : 5;
	$pageSize = $isPro ? 10 : 5;
	$cursor   = (int) ( $query['cursor'] ?? 0 );

	$all   = demo_reviews( $placeId, $maxTotal );
	$slice = array_slice( $all, $cursor, $pageSize );
	$next  = ( $cursor + $pageSize ) < count( $all ) ? (string) ( $cursor + $pageSize ) : null;

	echo json_encode(
		array(
			'place_id'     => $placeId,
			'name'         => $business['name'],
			'public_url'   => $business['public_url'],
			'rating'       => $business['rating'],
			'review_count' => $business['review_count'],
			'source'       => $isPro ? 'business_profile' : 'places',
			'truncated'    => count( $all ) < (int) $business['review_count'],
			'fetched_at'   => gmdate( DATE_RFC3339 ),
			'next_cursor'  => $next,
			'reviews'      => $slice,
		),
		JSON_UNESCAPED_UNICODE
	);
	exit;
}

if ( '/v1/oauth/start' === $path ) {
	fail( 501, 'upstream_error', 'El flujo OAuth Pro no está simulado. Implementarlo en el backend real.' );
}

fail( 404, 'not_found', 'Ruta no encontrada en el simulador: ' . $path );
