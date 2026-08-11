<?php
/**
 * Pantalla Estado y diagnóstico.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Admin;

use Huexs\GoogleReviews\Plugin;
use Huexs\GoogleReviews\Sync\Scheduler;

class StatusPage {

	public function __construct( private Plugin $plugin ) {}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;

		$crypto    = $this->plugin->crypto();
		$tokens    = $this->plugin->tokenStore();
		$nextSync  = wp_next_scheduled( Scheduler::SYNC_EVENT );
		$nextPurge = wp_next_scheduled( Scheduler::PURGE_EVENT );
		$logs      = $this->plugin->syncLogs()->recent( 10 );
		$lastOk    = null;
		foreach ( $logs as $log ) {
			if ( in_array( $log->status, array( 'success', 'partial' ), true ) ) {
				$lastOk = $log;
				break;
			}
		}

		$tables = array( 'hgr_locations', 'hgr_reviews', 'hgr_sync_logs' );
		$tablesOk = true;
		foreach ( $tables as $table ) {
			$name = $wpdb->prefix . $table;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) !== $name ) {
				$tablesOk = false;
			}
		}

		$cronDelayed = $nextSync && $nextSync < ( time() - 15 * MINUTE_IN_SECONDS );

		$rows = array(
			array( __( 'Versión del plugin', 'huexs-google-reviews' ), HGR_VERSION, 'ok' ),
			array( __( 'WordPress', 'huexs-google-reviews' ), get_bloginfo( 'version' ), 'ok' ),
			array( __( 'PHP', 'huexs-google-reviews' ), PHP_VERSION, version_compare( PHP_VERSION, '8.1', '>=' ) ? 'ok' : 'error' ),
			array( __( 'HTTPS', 'huexs-google-reviews' ), is_ssl() ? __( 'Sí', 'huexs-google-reviews' ) : __( 'No', 'huexs-google-reviews' ), is_ssl() ? 'ok' : 'warn' ),
			array(
				__( 'Constantes de credenciales', 'huexs-google-reviews' ),
				'constants' === $this->plugin->credentials()->source() ? __( 'Definidas', 'huexs-google-reviews' ) : ( $this->plugin->credentials()->has() ? __( 'En base de datos (cifradas)', 'huexs-google-reviews' ) : __( 'Ausentes', 'huexs-google-reviews' ) ),
				$this->plugin->credentials()->has() ? 'ok' : 'error',
			),
			array(
				__( 'Criptografía', 'huexs-google-reviews' ),
				$crypto->isAvailable() ? strtoupper( $crypto->backend() ) : __( 'No disponible', 'huexs-google-reviews' ),
				$crypto->isAvailable() ? 'ok' : 'error',
			),
			array(
				__( 'Conexión OAuth', 'huexs-google-reviews' ),
				$tokens->isExpired() ? __( 'Caducada', 'huexs-google-reviews' ) : ( $tokens->isConnected() ? __( 'Válida', 'huexs-google-reviews' ) : __( 'Sin conectar', 'huexs-google-reviews' ) ),
				$tokens->isExpired() ? 'error' : ( $tokens->isConnected() ? 'ok' : 'warn' ),
			),
			array(
				__( 'Cron de sincronización', 'huexs-google-reviews' ),
				$nextSync ? gmdate( 'Y-m-d H:i:s', $nextSync ) . ' UTC' . ( $cronDelayed ? ' — ' . __( 'atrasado', 'huexs-google-reviews' ) : '' ) : __( 'No programado', 'huexs-google-reviews' ),
				$nextSync ? ( $cronDelayed ? 'warn' : 'ok' ) : 'error',
			),
			array(
				__( 'Purga diaria', 'huexs-google-reviews' ),
				$nextPurge ? gmdate( 'Y-m-d H:i:s', $nextPurge ) . ' UTC' : __( 'No programada', 'huexs-google-reviews' ),
				$nextPurge ? 'ok' : 'error',
			),
			array(
				__( 'Última sincronización correcta', 'huexs-google-reviews' ),
				$lastOk ? $lastOk->started_at . ' UTC (' . $lastOk->status . ')' : __( 'Nunca', 'huexs-google-reviews' ),
				$lastOk ? 'ok' : 'warn',
			),
			array(
				__( 'Tablas de base de datos', 'huexs-google-reviews' ),
				$tablesOk ? __( 'Correctas (versión ', 'huexs-google-reviews' ) . get_option( 'hgr_db_version', '?' ) . ')' : __( 'Faltan tablas — desactiva y reactiva el plugin', 'huexs-google-reviews' ),
				$tablesOk ? 'ok' : 'error',
			),
			array( __( 'Reseñas en caché', 'huexs-google-reviews' ), (string) $this->plugin->reviews()->countAll(), 'ok' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Huexs Google Reviews — Estado y diagnóstico', 'huexs-google-reviews' ); ?></h1>

			<div class="hgr-admin-card">
				<table class="widefat striped">
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td style="width:280px;"><strong><?php echo esc_html( $row[0] ); ?></strong></td>
								<td><span class="hgr-status-<?php echo esc_attr( $row[2] ); ?>"><?php echo esc_html( $row[1] ); ?></span></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
					<input type="hidden" name="action" value="hgr_test_connection" />
					<?php wp_nonce_field( 'hgr_test_connection' ); ?>
					<?php submit_button( __( 'Probar conexión con Google (solo lectura)', 'huexs-google-reviews' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<?php if ( $logs ) : ?>
			<div class="hgr-admin-card">
				<h2><?php esc_html_e( 'Últimos errores', 'huexs-google-reviews' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Fecha (UTC)', 'huexs-google-reviews' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'huexs-google-reviews' ); ?></th>
							<th><?php esc_html_e( 'Código', 'huexs-google-reviews' ); ?></th>
							<th><?php esc_html_e( 'Detalle', 'huexs-google-reviews' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $log ) : ?>
							<?php if ( null !== $log->error_code || 'failed' === $log->status ) : ?>
								<tr>
									<td><?php echo esc_html( $log->started_at ); ?></td>
									<td><?php echo esc_html( $log->status ); ?></td>
									<td><?php echo esc_html( $log->error_code ?? '—' ); ?></td>
									<td><?php echo esc_html( $log->error_message ?? '—' ); ?></td>
								</tr>
							<?php endif; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
