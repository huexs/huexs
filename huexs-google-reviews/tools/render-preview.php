<?php
/**
 * Genera una página HTML con los seis diseños renderizados, para revisarlos de un vistazo.
 *
 * Usa las plantillas y el CSS reales del plugin; solo sustituye WordPress por los
 * stubs de las pruebas. No forma parte del ZIP distribuible.
 *
 * Uso:
 *   php tools/render-preview.php > /tmp/preview.html
 *   php tools/render-preview.php --theme=dark --cards=border > /tmp/preview-dark.html
 *
 * @package Huexs\GoogleReviews
 */

declare(strict_types=1);

require __DIR__ . '/../tests/bootstrap.php';

use Huexs\GoogleReviews\Frontend\Layouts;
use Huexs\GoogleReviews\Frontend\ReviewRenderer;

$args = array();
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( preg_match( '/^--([a-z_]+)=(.*)$/', $arg, $m ) ) {
		$args[ $m[1] ] = $m[2];
	}
}

$settings = array(
	'default_layout'   => 'grid',
	'default_limit'    => 6,
	'show_avatar'      => true,
	'show_date'        => true,
	'show_reply'       => true,
	'show_google_logo' => true,
	'excerpt_lines'    => 5,
	'theme'            => $args['theme'] ?? 'light',
	'card_style'       => $args['cards'] ?? 'shadow',
	'accent_color'     => $args['accent'] ?? '#fbbc04',
	'text_color'       => '',
	'bg_color'         => '',
	'badge_position'   => 'bottom-right',
);

/** Reseñas de muestra: casos reales que el plugin debe soportar. */
function demo_reviews(): array {
	$rows = array(
		array(
			'reviewer_name' => 'María García',
			'star_rating'   => 5,
			'comment'       => "Servicio excelente de principio a fin.\nInstalaron la señalética en un día y el acabado es impecable. Volveré sin dudarlo.",
			'create_time'   => '2026-07-28 10:15:30',
			'reply_comment' => '¡Muchas gracias, María! Un placer trabajar contigo.',
			'photo'         => true,
		),
		array(
			'reviewer_name' => 'Andrés Müller',
			'star_rating'   => 4,
			'comment'       => 'Muy buen trato y cumplieron los plazos. El resultado final quedó tal y como lo habíamos hablado.',
			'create_time'   => '2026-07-14 18:45:00',
			'reply_comment' => null,
			'photo'         => false,
		),
		array(
			// Reseña sin comentario: solo puntuación.
			'reviewer_name' => 'Lucía Pérez',
			'star_rating'   => 5,
			'comment'       => null,
			'create_time'   => '2026-06-30 09:00:00',
			'reply_comment' => null,
			'photo'         => true,
		),
		array(
			'reviewer_name' => 'Jordi Roca i Vilanova',
			'star_rating'   => 5,
			'comment'       => 'Profesionales de verdad. Nos asesoraron sobre el material y acertaron de pleno: dos años después el rótulo sigue como el primer día, y eso que le da el sol de lleno todo el día.',
			'create_time'   => '2026-06-11 12:30:00',
			'reply_comment' => 'Gracias Jordi, nos alegra que siga perfecto.',
			'photo'         => true,
		),
		array(
			'reviewer_name' => 'Ana Sánchez',
			'star_rating'   => 3,
			'comment'       => 'El trabajo está bien, pero tardaron algo más de lo previsto en venir a instalar.',
			'create_time'   => '2026-05-22 16:20:00',
			'reply_comment' => 'Sentimos el retraso, Ana. Tomamos nota para mejorar.',
			'photo'         => false,
		),
		array(
			'reviewer_name' => 'Tomás Ríos',
			'star_rating'   => 5,
			'comment'       => 'Rápidos, limpios y con buen precio. Recomendables.',
			'create_time'   => '2026-05-03 11:05:00',
			'reply_comment' => null,
			'photo'         => true,
		),
	);

	$reviews = array();
	foreach ( $rows as $i => $row ) {
		$reviews[] = (object) array(
			'id'                 => $i + 1,
			'location_id'        => 1,
			'google_review_id'   => 'demo-' . $i,
			'reviewer_name'      => $row['reviewer_name'],
			// Avatar como data URI: la vista previa no depende de la red.
			'reviewer_photo_url' => $row['photo'] ? avatar_data_uri( $row['reviewer_name'] ) : null,
			'star_rating'        => $row['star_rating'],
			'comment'            => $row['comment'],
			'create_time'        => $row['create_time'],
			'update_time'        => null,
			'reply_comment'      => $row['reply_comment'],
			'reply_update_time'  => null,
			'last_seen_at'       => gmdate( 'Y-m-d H:i:s' ),
			'location_title'     => 'Huexs Señalética',
			'public_google_url'  => 'https://maps.google.com/?cid=1234567890',
		);
	}
	return $reviews;
}

/** Avatar circular con la inicial, generado como SVG en data URI. */
function avatar_data_uri( string $name ): string {
	$colors  = array( '#4285F4', '#EA4335', '#34A853', '#FBBC05', '#9333ea', '#0891b2' );
	$initial = mb_strtoupper( mb_substr( $name, 0, 1 ) );
	$color   = $colors[ abs( crc32( $name ) ) % count( $colors ) ];
	$svg     = '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 80 80">'
		. '<circle cx="40" cy="40" r="40" fill="' . $color . '"/>'
		. '<text x="40" y="54" font-family="sans-serif" font-size="38" font-weight="600" fill="#fff" text-anchor="middle">'
		. htmlspecialchars( $initial, ENT_QUOTES, 'UTF-8' )
		. '</text></svg>';
	return 'data:image/svg+xml;base64,' . base64_encode( $svg );
}

$summary = array(
	'average'        => 4.8,
	'count'          => 127,
	'multi_location' => false,
	'public_url'     => 'https://maps.google.com/?cid=1234567890',
	'name'           => 'Huexs Señalética',
);

$args_shortcode = array(
	'show_avatar'  => true,
	'show_date'    => true,
	'show_reply'   => true,
	'show_summary' => true,
	'show_count'   => true,
	'position'     => 'bottom-right',
	'force_open'   => isset( $args['picker'] ),
);

$renderer = new ReviewRenderer( $settings, false );
$reviews  = demo_reviews();
$css      = file_get_contents( __DIR__ . '/../assets/css/frontend.css' );
if ( isset( $args['picker'] ) ) {
	$css .= "\n" . file_get_contents( __DIR__ . '/../assets/css/admin.css' );
}

$titles = array(
	Layouts::GRID     => 'Cuadrícula — el más usado en páginas de inicio',
	Layouts::LIST     => 'Lista — para páginas de testimonios',
	Layouts::CAROUSEL => 'Carrusel — ahorra espacio, navegable con teclado',
	Layouts::SIDEBAR  => 'Columna lateral — para barras laterales estrechas',
	Layouts::BADGE    => 'Insignia — solo nota y total, siempre datos completos',
	Layouts::FLOATING => 'Burbuja flotante — fija en una esquina de la pantalla',
);

echo "<!doctype html>\n<html lang=\"es\"><head><meta charset=\"utf-8\">\n";
echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
echo "<title>Huexs Google Reviews — diseños</title>\n<style>\n";
echo "body{margin:0;padding:32px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f0f1;}\n";
echo ".demo{max-width:1000px;margin:0 auto 40px;}\n";
echo ".demo h2{font-size:15px;text-transform:uppercase;letter-spacing:.06em;color:#50575e;margin:0 0 4px;}\n";
echo ".demo p.code{font-family:monospace;font-size:13px;color:#787c82;margin:0 0 14px;}\n";
echo ".stage{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:24px;position:relative;min-height:80px;}\n";
echo ".stage--dark{background:#111827;}\n";
echo ".stage--narrow{max-width:320px;}\n";
// La burbuja es position:fixed; en la vista previa se ancla a su contenedor.
echo ".stage--floating{height:420px;overflow:hidden;}\n";
echo ".stage--floating .hgr-layout--floating{position:absolute;}\n";
echo $css;
echo "\n</style></head><body>\n";

foreach ( isset( $args['picker'] ) ? array() : array( Layouts::GRID, Layouts::LIST, Layouts::CAROUSEL, Layouts::SIDEBAR, Layouts::BADGE, Layouts::FLOATING ) as $layout ) {
	$stageClass = 'stage';
	if ( 'dark' === $settings['theme'] ) {
		$stageClass .= ' stage--dark';
	}
	if ( Layouts::SIDEBAR === $layout ) {
		$stageClass .= ' stage--narrow';
	}
	if ( Layouts::FLOATING === $layout ) {
		$stageClass .= ' stage--floating';
	}

	$shown = Layouts::SIDEBAR === $layout ? array_slice( $reviews, 0, 4 ) : $reviews;
	$shown = Layouts::CAROUSEL === $layout ? $reviews : $shown;

	echo '<section class="demo">';
	echo '<h2>' . htmlspecialchars( $titles[ $layout ], ENT_QUOTES, 'UTF-8' ) . '</h2>';
	echo '<p class="code">[huexs_google_reviews layout="' . $layout . '"]</p>';
	echo '<div class="' . $stageClass . '">';
	echo $renderer->renderLayout( $layout, $shown, $args_shortcode, $summary );
	echo '</div></section>' . "\n";
}

// Modo --picker: reproduce el selector de diseño de la pantalla Diseño.
if ( isset( $args['picker'] ) ) {
	echo '<section class="demo"><h2>' . 'Selector de diseño — cada opción con reseñas reales' . '</h2>';
	echo '<fieldset class="hgr-layout-picker hgr-layout-picker--live">';
	foreach ( Layouts::labels() as $key => $label ) {
		$checked = 'grid' === $key ? ' checked' : '';
		echo '<label class="hgr-layout-option">';
		echo '<input type="radio" name="hgr_layout" value="' . $key . '"' . $checked . ' />';
		echo '<span class="hgr-layout-option__preview hgr-layout-option__preview--live hgr-live--' . $key . '">';
		echo '<span class="hgr-layout-option__scale" aria-hidden="true">';
		echo $renderer->renderLayout( $key, array_slice( $reviews, 0, 4 ), $args_shortcode, $summary );
		echo '</span></span>';
		echo '<span class="hgr-layout-option__label">' . htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' ) . '</span>';
		echo '</label>';
	}
	echo '</fieldset></section>';
}

echo "</body></html>\n";
