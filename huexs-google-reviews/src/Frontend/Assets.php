<?php
/**
 * Registro de assets: solo se encolan cuando un shortcode se renderiza.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Frontend;

class Assets {

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'registerFrontend' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'registerAdmin' ) );
	}

	public function registerFrontend(): void {
		wp_register_style(
			'hgr-frontend',
			HGR_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			HGR_VERSION
		);
		wp_register_script(
			'hgr-carousel',
			HGR_PLUGIN_URL . 'assets/js/carousel.js',
			array(),
			HGR_VERSION,
			array( 'in_footer' => true )
		);
	}

	public function enqueueFrontend(): void {
		// Si el shortcode se renderiza tarde (p. ej. dentro de Elementor), registramos al vuelo.
		if ( ! wp_style_is( 'hgr-frontend', 'registered' ) ) {
			$this->registerFrontend();
		}
		wp_enqueue_style( 'hgr-frontend' );
		wp_enqueue_script( 'hgr-carousel' );
	}

	public function registerAdmin( string $hook ): void {
		if ( ! str_contains( $hook, 'hgr-' ) ) {
			return;
		}
		wp_enqueue_style( 'hgr-admin', HGR_PLUGIN_URL . 'assets/css/admin.css', array(), HGR_VERSION );
		wp_enqueue_style( 'hgr-frontend', HGR_PLUGIN_URL . 'assets/css/frontend.css', array(), HGR_VERSION );
		wp_enqueue_script( 'hgr-admin', HGR_PLUGIN_URL . 'assets/js/admin.js', array(), HGR_VERSION, array( 'in_footer' => true ) );
	}
}
