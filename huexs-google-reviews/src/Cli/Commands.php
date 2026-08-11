<?php
/**
 * Comandos WP-CLI: wp hgr sync|status|purge-expired.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Cli;

use Huexs\GoogleReviews\Plugin;
use Huexs\GoogleReviews\Sync\Scheduler;
use Huexs\GoogleReviews\Sync\SyncResult;

class Commands {

	public function __construct( private Plugin $plugin ) {}

	public static function register( Plugin $plugin ): void {
		\WP_CLI::add_command( 'hgr', new self( $plugin ) );
	}

	/**
	 * Sincroniza las reseñas de las ubicaciones activas.
	 *
	 * ## OPTIONS
	 *
	 * [--location=<id>]
	 * : Sincronizar solo esta ubicación (ID interno).
	 *
	 * [--force]
	 * : Ejecutar aunque no toque por programación. No salta un lock activo no vencido.
	 *
	 * @subcommand sync
	 */
	public function sync( array $args, array $assocArgs ): void {
		$locationId = isset( $assocArgs['location'] ) ? (int) $assocArgs['location'] : null;

		$result = $this->plugin->syncService()->run( 'cli', $locationId );

		if ( SyncResult::STATUS_SKIPPED === $result->status ) {
			\WP_CLI::error( 'Ya hay una sincronización en curso (lock activo).' );
		}

		\WP_CLI::log(
			sprintf(
				'Estado: %s — vistas %d, nuevas %d, actualizadas %d, eliminadas %d.',
				$result->status,
				$result->seen,
				$result->inserted,
				$result->updated,
				$result->deleted
			)
		);
		foreach ( $result->errors as $error ) {
			\WP_CLI::warning( sprintf( '[%s] %s', $error['code'], $error['message'] ) );
		}

		if ( SyncResult::STATUS_FAILED === $result->status ) {
			\WP_CLI::error( 'La sincronización falló.' );
		}
		\WP_CLI::success( 'Sincronización terminada.' );
	}

	/**
	 * Muestra el estado de conexión, cron y última sincronización.
	 *
	 * @subcommand status
	 */
	public function status( array $args, array $assocArgs ): void {
		$tokens   = $this->plugin->tokenStore();
		$nextSync = wp_next_scheduled( Scheduler::SYNC_EVENT );
		$logs     = $this->plugin->syncLogs()->recent( 1 );
		$last     = $logs[0] ?? null;

		\WP_CLI::log( 'Conexión OAuth: ' . ( $tokens->isExpired() ? 'caducada' : ( $tokens->isConnected() ? 'válida' : 'sin conectar' ) ) );
		\WP_CLI::log( 'Credenciales: ' . $this->plugin->credentials()->source() );
		\WP_CLI::log( 'Criptografía: ' . $this->plugin->crypto()->backend() );
		\WP_CLI::log( 'Ubicaciones activas: ' . count( $this->plugin->locations()->findEnabled() ) );
		\WP_CLI::log( 'Reseñas en caché: ' . $this->plugin->reviews()->countAll() );
		\WP_CLI::log( 'Próximo cron: ' . ( $nextSync ? gmdate( 'Y-m-d H:i:s', $nextSync ) . ' UTC' : 'no programado' ) );
		\WP_CLI::log( 'Última ejecución: ' . ( $last ? $last->started_at . ' UTC (' . $last->status . ')' : 'nunca' ) );
	}

	/**
	 * Purga reseñas no renovadas en 30 días y logs antiguos.
	 *
	 * @subcommand purge-expired
	 */
	public function purge_expired( array $args, array $assocArgs ): void {
		$purged = $this->plugin->retention()->purge();
		\WP_CLI::success( sprintf( 'Purga completada: %d reseñas eliminadas.', $purged ) );
	}
}
