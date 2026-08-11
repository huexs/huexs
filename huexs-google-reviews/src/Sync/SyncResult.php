<?php
/**
 * Resultado agregado de una ejecución de sincronización.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Sync;

final class SyncResult {

	public const STATUS_SUCCESS = 'success';
	public const STATUS_PARTIAL = 'partial';
	public const STATUS_FAILED  = 'failed';
	public const STATUS_SKIPPED = 'skipped';

	public int $seen     = 0;
	public int $inserted = 0;
	public int $updated  = 0;
	public int $deleted  = 0;

	public int $locationsOk     = 0;
	public int $locationsFailed = 0;

	/** @var array<int, array{location_id:?int, code:string, message:string}> */
	public array $errors = array();

	public function __construct( public string $status = self::STATUS_SUCCESS ) {}

	public function addError( ?int $locationId, string $code, string $message ): void {
		$this->errors[] = array(
			'location_id' => $locationId,
			'code'        => $code,
			'message'     => $message,
		);
	}

	public function resolveStatus(): string {
		if ( self::STATUS_SKIPPED === $this->status ) {
			return $this->status;
		}
		if ( $this->locationsFailed > 0 && 0 === $this->locationsOk ) {
			$this->status = self::STATUS_FAILED;
		} elseif ( $this->locationsFailed > 0 ) {
			$this->status = self::STATUS_PARTIAL;
		} else {
			$this->status = self::STATUS_SUCCESS;
		}
		return $this->status;
	}

	public function firstErrorCode(): ?string {
		return $this->errors[0]['code'] ?? null;
	}

	public function errorSummary(): ?string {
		if ( ! $this->errors ) {
			return null;
		}
		$parts = array();
		foreach ( $this->errors as $error ) {
			$prefix  = null !== $error['location_id'] ? '[ubicación ' . $error['location_id'] . '] ' : '';
			$parts[] = $prefix . $error['code'] . ': ' . $error['message'];
		}
		return implode( ' | ', $parts );
	}
}
