<?php
/**
 * Shortcodes [huexs_google_reviews] y [huexs_google_rating].
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Frontend;

use Huexs\GoogleReviews\Plugin;
use Huexs\GoogleReviews\Sync\RetentionService;

class Shortcodes {

	public const ALLOWED_LAYOUTS = array( 'list', 'grid', 'carousel' );
	public const ALLOWED_ORDERS  = array( 'newest', 'oldest' );

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_shortcode( 'huexs_google_reviews', array( $this, 'renderReviews' ) );
		add_shortcode( 'huexs_google_rating', array( $this, 'renderRating' ) );
	}

	/**
	 * Normaliza atributos con listas permitidas. Pura para poder testearla.
	 *
	 * @param array $atts     Atributos crudos del shortcode.
	 * @param array $settings Ajustes globales (defaults).
	 */
	public static function normalizeAtts( array $atts, array $settings ): array {
		$layout = strtolower( trim( (string) ( $atts['layout'] ?? $settings['default_layout'] ) ) );
		if ( ! in_array( $layout, self::ALLOWED_LAYOUTS, true ) ) {
			$layout = 'grid';
		}

		$order = strtolower( trim( (string) ( $atts['order'] ?? 'newest' ) ) );
		if ( ! in_array( $order, self::ALLOWED_ORDERS, true ) ) {
			$order = 'newest';
		}

		$limit = (int) ( $atts['limit'] ?? $settings['default_limit'] );
		$limit = max( 1, min( 50, $limit ) );

		$location = trim( (string) ( $atts['location'] ?? 'all' ) );
		if ( 'all' !== $location ) {
			// Solo un ID interno numérico; cualquier otro valor cae al conjunto de activas.
			$location = ctype_digit( $location ) && absint( $location ) > 0 ? (string) absint( $location ) : 'all';
		}

		return array(
			'location'     => $location,
			'layout'       => $layout,
			'limit'        => $limit,
			'order'        => $order,
			'show_avatar'  => self::boolAtt( $atts['show_avatar'] ?? null, (bool) $settings['show_avatar'] ),
			'show_date'    => self::boolAtt( $atts['show_date'] ?? null, (bool) $settings['show_date'] ),
			'show_reply'   => self::boolAtt( $atts['show_reply'] ?? null, (bool) $settings['show_reply'] ),
			'show_summary' => self::boolAtt( $atts['show_summary'] ?? null, true ),
			'show_count'   => self::boolAtt( $atts['show_count'] ?? null, true ),
		);
	}

	public static function boolAtt( mixed $value, bool $default ): bool {
		if ( null === $value || '' === $value ) {
			return $default;
		}
		if ( is_bool( $value ) ) {
			return $value;
		}
		$value = strtolower( trim( (string) $value ) );
		if ( in_array( $value, array( 'true', '1', 'yes', 'si', 'sí', 'on' ), true ) ) {
			return true;
		}
		if ( in_array( $value, array( 'false', '0', 'no', 'off' ), true ) ) {
			return false;
		}
		return $default;
	}

	public function renderReviews( $atts ): string {
		$settings = Plugin::settings();
		$args     = self::normalizeAtts( is_array( $atts ) ? $atts : array(), $settings );

		$locations = $this->resolveLocations( $args['location'] );
		if ( ! $locations ) {
			return $this->adminNotice( __( 'Huexs Google Reviews: no hay ubicaciones activas. Actívalas en Google Reviews → Ubicaciones.', 'huexs-google-reviews' ) );
		}

		$locationIds = array_map( static fn( $l ) => (int) $l->id, $locations );
		$rows        = $this->plugin->reviews()->findForDisplay( $locationIds, $args['order'], $args['limit'] );
		$rows        = $this->filterFresh( $rows );

		if ( ! $rows ) {
			return $this->adminNotice( __( 'Huexs Google Reviews: todavía no hay reseñas sincronizadas (o han caducado). Ejecuta una sincronización.', 'huexs-google-reviews' ) );
		}

		$this->plugin->assets()->enqueueFrontend( $settings );

		$renderer = new ReviewRenderer( $settings );
		$summary  = $args['show_summary'] ? $this->summaryData( $locations ) : null;

		return $renderer->renderLayout( $args['layout'], $rows, $args, $summary );
	}

	public function renderRating( $atts ): string {
		$settings = Plugin::settings();
		$args     = self::normalizeAtts( is_array( $atts ) ? $atts : array(), $settings );

		$locations = $this->resolveLocations( $args['location'] );
		if ( ! $locations ) {
			return $this->adminNotice( __( 'Huexs Google Reviews: no hay ubicaciones activas.', 'huexs-google-reviews' ) );
		}

		$summary = $this->summaryData( $locations );
		if ( null === $summary['average'] ) {
			return $this->adminNotice( __( 'Huexs Google Reviews: todavía no hay valoración sincronizada.', 'huexs-google-reviews' ) );
		}

		$this->plugin->assets()->enqueueFrontend( $settings );

		$renderer = new ReviewRenderer( $settings );
		return $renderer->renderSummary( $summary, $args );
	}

	/** @return object[] */
	private function resolveLocations( string $location ): array {
		if ( 'all' === $location ) {
			return $this->plugin->locations()->findEnabled();
		}
		$row = $this->plugin->locations()->find( (int) $location );
		return $row && (int) $row->enabled === 1 ? array( $row ) : array();
	}

	/** Excluye filas cuya última renovación supere la ventana de retención. */
	private function filterFresh( array $rows ): array {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - RetentionService::RETENTION_DAYS * DAY_IN_SECONDS );
		return array_values(
			array_filter( $rows, static fn( $row ) => (string) $row->last_seen_at >= $cutoff )
		);
	}

	private function summaryData( array $locations ): array {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - RetentionService::RETENTION_DAYS * DAY_IN_SECONDS );
		$input  = array();
		foreach ( $locations as $location ) {
			if ( null === $location->last_synced_at || (string) $location->last_synced_at < $cutoff ) {
				continue; // Resumen caducado: no se presenta como actual.
			}
			$input[] = array(
				'average' => null !== $location->average_rating ? (float) $location->average_rating : null,
				'count'   => (int) $location->total_review_count,
			);
		}
		$combined                   = RatingCalculator::weighted( $input );
		$combined['multi_location'] = count( $input ) > 1;
		$combined['public_url']     = count( $locations ) === 1 ? (string) ( $locations[0]->public_google_url ?? '' ) : '';
		return $combined;
	}

	private function adminNotice( string $message ): string {
		if ( ! current_user_can( 'manage_options' ) || ! Plugin::settings()['stale_notice_admins'] ) {
			return '';
		}
		return '<div class="hgr-admin-notice" role="note">' . esc_html( $message ) . '</div>';
	}
}
