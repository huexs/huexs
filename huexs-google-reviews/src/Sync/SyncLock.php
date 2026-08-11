<?php
/**
 * Lock de sincronización basado en opción (autoload no), con recuperación de locks huérfanos.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Sync;

use Huexs\GoogleReviews\Support\Clock;

class SyncLock {

	public const OPTION      = 'hgr_sync_lock';
	public const MAX_AGE_SEC = 20 * 60;

	public function __construct( private Clock $clock ) {}

	/** Intenta adquirir el lock. Devuelve false si hay otra sincronización activa. */
	public function acquire( string $runId ): bool {
		$payload = \wp_json_encode(
			array(
				'run_id' => $runId,
				'ts'     => $this->clock->timestamp(),
			)
		);

		// add_option no sobrescribe una opción existente: sirve como operación de adquisición.
		if ( add_option( self::OPTION, $payload, '', false ) ) {
			return true;
		}

		$current = $this->read();
		if ( null === $current || $this->isStale( $current ) ) {
			// Lock huérfano: lo recuperamos.
			update_option( self::OPTION, $payload, false );
			return true;
		}

		return false;
	}

	public function release( string $runId ): void {
		$current = $this->read();
		if ( null === $current || ( $current['run_id'] ?? '' ) === $runId ) {
			delete_option( self::OPTION );
		}
	}

	public function isLocked(): bool {
		$current = $this->read();
		return null !== $current && ! $this->isStale( $current );
	}

	public static function force_release(): void {
		delete_option( self::OPTION );
	}

	private function read(): ?array {
		$raw = get_option( self::OPTION, '' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	private function isStale( array $lock ): bool {
		return ( $this->clock->timestamp() - (int) ( $lock['ts'] ?? 0 ) ) > self::MAX_AGE_SEC;
	}
}
