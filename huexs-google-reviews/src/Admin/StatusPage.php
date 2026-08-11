<?php
/**
 * Pantalla Estado y diagnóstico.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Admin;

use Huexs\GoogleReviews\Plugin;
use Huexs\GoogleReviews\Source\HuexsApiSource;
use Huexs\GoogleReviews\Sync\Scheduler;

class StatusPage {

	public function __construct( private Plugin $plugin ) {}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;

		$settings  = Plugin::settings();
		$license   = $this->plugin->license();
		$crypto    = $this->plugin->crypto();
		$nextSync  = wp_next_scheduled( Scheduler::SYNC_EVENT );
		$nextPurge = wp_next_scheduled( Scheduler::PURGE_EVENT );
		$logs      = $this->plugin->syncLogs()->recent( 10 );

		$lastOk = null;
		foreach ( $logs as $log ) {
			if ( in_array( $log->status, array( 'success', 'partial' ), true ) ) {
				$lastOk = $log;
				break;
			}
		}

		$tablesOk = true;
		foreach ( array( 'hgr_locations', 'hgr_reviews', 'hgr_sync_logs' ) as $table ) {
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
				__( 'Modo', 'huexs-google-reviews' ),
				$license->isUnlocked()
					? __( 'Completo — clave propia de Google, sin licencia ni límites', 'huexs-google-reviews' )
					: __( 'Servicio central de Huexs', 'huexs-google-reviews' ),
				'ok',
			),
			array(
				__( 'Licencia', 'huexs-google-reviews' ),
				$license->isUnlocked()
					? __( 'No necesaria en este modo', 'huexs-google-reviews' )
					: ( $license->has() ? $license->maskedKey() . ' · ' . strtoupper( $license->plan() ) : __( 'Sin activar', 'huexs-google-reviews' ) ),
				$license->isUnlocked() || $license->has() ? 'ok' : 'error',
			),
			array(
				__( 'Clave de Google Places', 'huexs-google-reviews' ),
				match ( $this->plugin->placesKey()->source() ) {
					'constant' => __( 'Definida en wp-config.php', 'huexs-google-reviews' ),
					'option'   => __( 'Guardada cifrada en la base de datos', 'huexs-google-reviews' ),
					default    => __( 'No configurada', 'huexs-google-reviews' ),
				},
				'none' === $this->plugin->placesKey()->source() ? 'warn' : 'ok',
			),
			array(
				__( 'IP de este servidor', 'huexs-google-reviews' ),
				// Pista para restringir la clave de Google por IP. En hostings con
				// balanceador o NAT la IP de salida puede ser otra: la definitiva la
				// revela el propio mensaje de error de Google al bloquear.
				isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : __( 'No disponible', 'huexs-google-reviews' ),
				'ok',
			),
			array(
				__( 'Servidor de la API', 'huexs-google-reviews' ),
				$license->isUnlocked() ? __( 'No se usa en modo completo', 'huexs-google-reviews' ) : HuexsApiSource::baseUrl(),
				'ok',
			),
			array(
				__( 'Criptografía', 'huexs-google-reviews' ),
				$crypto->isAvailable() ? strtoupper( $crypto->backend() ) : __( 'No disponible', 'huexs-google-reviews' ),
				$crypto->isAvailable() ? 'ok' : 'error',
			),
			array(
				__( 'Negocios conectados', 'huexs-google-reviews' ),
				(string) $this->plugin->locations()->countEnabled(),
				$this->plugin->locations()->countEnabled() > 0 ? 'ok' : 'warn',
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
				$tablesOk
					? __( 'Correctas', 'huexs-google-reviews' ) . ' (v' . get_option( 'hgr_db_version', '?' ) . ')'
					: __( 'Faltan tablas — desactiva y reactiva el plugin', 'huexs-google-reviews' ),
				$tablesOk ? 'ok' : 'error',
			),
			array( __( 'Reseñas en caché', 'huexs-google-reviews' ), (string) $this->plugin->reviews()->countAll(), 'ok' ),
		);

		if ( $settings['advanced_mode'] ) {
			$tokens = $this->plugin->tokenStore();
			$rows[] = array(
				__( 'Modo avanzado — OAuth propio', 'huexs-google-reviews' ),
				$tokens->isExpired() ? __( 'Caducado', 'huexs-google-reviews' ) : ( $tokens->isConnected() ? __( 'Conectado', 'huexs-google-reviews' ) : __( 'Sin conectar', 'huexs-google-reviews' ) ),
				$tokens->isExpired() ? 'error' : ( $tokens->isConnected() ? 'ok' : 'warn' ),
			);
		}
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
					<?php submit_button( __( 'Probar conexión con la API (solo lectura)', 'huexs-google-reviews' ), 'secondary', 'submit', false ); ?>
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
						<?php
						$hgr_any = false;
						foreach ( $logs as $log ) :
							if ( null === $log->error_code && 'failed' !== $log->status ) {
								continue;
							}
							$hgr_any = true;
							?>
							<tr>
								<td><?php echo esc_html( $log->started_at ); ?></td>
								<td><?php echo esc_html( $log->status ); ?></td>
								<td><?php echo esc_html( $log->error_code ?? '—' ); ?></td>
								<td><?php echo esc_html( $log->error_message ?? '—' ); ?></td>
							</tr>
						<?php endforeach; ?>
						<?php if ( ! $hgr_any ) : ?>
							<tr><td colspan="4"><?php esc_html_e( 'Sin errores registrados.', 'huexs-google-reviews' ); ?></td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
