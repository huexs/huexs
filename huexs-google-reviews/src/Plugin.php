<?php
/**
 * Núcleo del plugin: composición de servicios y registro de hooks.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews;

use Huexs\GoogleReviews\Admin\Actions;
use Huexs\GoogleReviews\Admin\Menu;
use Huexs\GoogleReviews\Auth\OAuthController;
use Huexs\GoogleReviews\Auth\OAuthStateStore;
use Huexs\GoogleReviews\Auth\TokenService;
use Huexs\GoogleReviews\Auth\TokenStore;
use Huexs\GoogleReviews\Cli\Commands;
use Huexs\GoogleReviews\Frontend\Assets;
use Huexs\GoogleReviews\Frontend\Shortcodes;
use Huexs\GoogleReviews\Google\GoogleBusinessProfileClient;
use Huexs\GoogleReviews\Google\GoogleClientInterface;
use Huexs\GoogleReviews\Repository\LocationRepository;
use Huexs\GoogleReviews\Repository\ReviewRepository;
use Huexs\GoogleReviews\Repository\SyncLogRepository;
use Huexs\GoogleReviews\Source\DirectPlacesSource;
use Huexs\GoogleReviews\Source\HuexsApiSource;
use Huexs\GoogleReviews\Source\ReviewSourceInterface;
use Huexs\GoogleReviews\Source\SelfHostedGoogleSource;
use Huexs\GoogleReviews\Source\SourceRegistry;
use Huexs\GoogleReviews\Support\Clock;
use Huexs\GoogleReviews\Support\Credentials;
use Huexs\GoogleReviews\Support\Crypto;
use Huexs\GoogleReviews\Support\HttpClientInterface;
use Huexs\GoogleReviews\Support\License;
use Huexs\GoogleReviews\Support\Logger;
use Huexs\GoogleReviews\Support\PlacesKey;
use Huexs\GoogleReviews\Support\WpHttpClient;
use Huexs\GoogleReviews\Sync\RetentionService;
use Huexs\GoogleReviews\Sync\Scheduler;
use Huexs\GoogleReviews\Sync\SyncLock;
use Huexs\GoogleReviews\Sync\SyncService;

final class Plugin {

	private static ?Plugin $instance = null;

	private array $services = array();

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init(): void {
		load_plugin_textdomain( 'huexs-google-reviews', false, dirname( plugin_basename( HGR_PLUGIN_FILE ) ) . '/languages' );

		$this->scheduler()->register();
		$this->shortcodes()->register();
		$this->assets()->register();

		if ( is_admin() ) {
			( new Menu( $this ) )->register();
			( new Actions( $this ) )->register();
			if ( self::settings()['advanced_mode'] ) {
				$this->oauthController()->register();
			}
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Commands::register( $this );
		}

		Activation::maybe_upgrade();
	}

	// ---- Soporte ----

	public function clock(): Clock {
		return $this->service( Clock::class, static fn() => new Clock() );
	}

	public function logger(): Logger {
		return $this->service( Logger::class, static fn() => new Logger() );
	}

	public function crypto(): Crypto {
		return $this->service( Crypto::class, static fn() => new Crypto() );
	}

	public function http(): HttpClientInterface {
		return $this->service( 'http', fn() => new WpHttpClient( $this->logger() ) );
	}

	public function license(): License {
		return $this->service( License::class, fn() => new License( $this->crypto() ) );
	}

	public function credentials(): Credentials {
		return $this->service( Credentials::class, fn() => new Credentials( $this->crypto() ) );
	}

	// ---- Fuentes ----

	public function huexsSource(): HuexsApiSource {
		return $this->service( HuexsApiSource::class, fn() => new HuexsApiSource( $this->http(), $this->license() ) );
	}

	public function placesKey(): PlacesKey {
		return $this->service( PlacesKey::class, fn() => new PlacesKey( $this->crypto() ) );
	}

	public function directSource(): DirectPlacesSource {
		return $this->service( DirectPlacesSource::class, fn() => new DirectPlacesSource( $this->http(), $this->placesKey() ) );
	}

	public function googleSource(): SelfHostedGoogleSource {
		return $this->service( SelfHostedGoogleSource::class, fn() => new SelfHostedGoogleSource( $this->googleClient(), $this->tokenStore() ) );
	}

	public function sources(): SourceRegistry {
		return $this->service(
			SourceRegistry::class,
			function () {
				$registry = new SourceRegistry();
				$registry->add( $this->huexsSource() );
				$registry->add( $this->directSource() );
				$registry->add( $this->googleSource() );
				$registry->setDefault( $this->defaultSourceId() );
				return $registry;
			}
		);
	}

	/**
	 * Fuente con la que se dan de alta los negocios nuevos.
	 *
	 * Prioridad: clave propia de Places (modo directo) → OAuth propio (modo
	 * avanzado) → API central de Huexs. Así un sitio con clave propia nunca
	 * depende del servicio central ni pide licencia.
	 */
	public function defaultSourceId(): string {
		if ( $this->placesKey()->has() ) {
			return DirectPlacesSource::SOURCE_ID;
		}
		if ( self::settings()['advanced_mode'] && $this->tokenStore()->isConnected() ) {
			return ReviewSourceInterface::SOURCE_GOOGLE;
		}
		return ReviewSourceInterface::SOURCE_HUEXS;
	}

	/** Fuente activa para búsquedas y altas desde la administración. */
	public function activeSource(): ReviewSourceInterface {
		return $this->sources()->get( $this->defaultSourceId() );
	}

	// ---- OAuth propio (modo avanzado) ----

	public function tokenStore(): TokenStore {
		return $this->service( TokenStore::class, fn() => new TokenStore( $this->crypto() ) );
	}

	public function tokenService(): TokenService {
		return $this->service( TokenService::class, fn() => new TokenService( $this->tokenStore(), $this->credentials(), $this->logger() ) );
	}

	public function oauthController(): OAuthController {
		return $this->service( OAuthController::class, fn() => new OAuthController( $this->credentials(), $this->tokenStore(), new OAuthStateStore(), $this ) );
	}

	public function googleClient(): GoogleClientInterface {
		return $this->service( 'google_client', fn() => new GoogleBusinessProfileClient( $this->tokenService(), $this->logger() ) );
	}

	// ---- Persistencia ----

	public function locations(): LocationRepository {
		return $this->service( LocationRepository::class, static fn() => new LocationRepository() );
	}

	public function reviews(): ReviewRepository {
		return $this->service( ReviewRepository::class, static fn() => new ReviewRepository() );
	}

	public function syncLogs(): SyncLogRepository {
		return $this->service( SyncLogRepository::class, static fn() => new SyncLogRepository() );
	}

	// ---- Sincronización ----

	public function syncLock(): SyncLock {
		return $this->service( SyncLock::class, fn() => new SyncLock( $this->clock() ) );
	}

	public function syncService(): SyncService {
		return $this->service(
			SyncService::class,
			fn() => new SyncService(
				$this->sources(),
				$this->locations(),
				$this->reviews(),
				$this->syncLogs(),
				$this->syncLock(),
				$this->clock(),
				$this->logger()
			)
		);
	}

	public function retention(): RetentionService {
		return $this->service( RetentionService::class, fn() => new RetentionService( $this->reviews(), $this->syncLogs(), $this->clock() ) );
	}

	public function scheduler(): Scheduler {
		return $this->service( Scheduler::class, fn() => new Scheduler( $this ) );
	}

	// ---- Frontend ----

	public function shortcodes(): Shortcodes {
		return $this->service( Shortcodes::class, fn() => new Shortcodes( $this ) );
	}

	public function assets(): Assets {
		return $this->service( Assets::class, static fn() => new Assets() );
	}

	// ---- Ajustes ----

	public static function settings(): array {
		$defaults = array(
			'sync_frequency_hours' => 24,
			'advanced_mode'        => false,
			'default_layout'       => 'grid',
			'default_limit'        => 6,
			'show_avatar'          => true,
			'show_date'            => true,
			'show_reply'           => true,
			'show_google_logo'     => true,
			'excerpt_lines'        => 5,
			'theme'                => 'light',
			'card_style'           => 'shadow',
			'accent_color'         => '#fbbc04',
			'text_color'           => '',
			'bg_color'             => '',
			'badge_position'       => 'bottom-right',
			'carousel_autoplay'    => true,
			'carousel_autoplay_seconds' => 5,
			'stale_notice_admins'  => true,
			'delete_on_uninstall'  => false,
		);
		$saved = get_option( 'hgr_settings', array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( $defaults, $saved );
	}

	public static function update_settings( array $settings ): void {
		update_option( 'hgr_settings', $settings, false );
	}

	private function service( string $key, callable $factory ) {
		if ( ! isset( $this->services[ $key ] ) ) {
			$this->services[ $key ] = $factory();
		}
		return $this->services[ $key ];
	}
}
