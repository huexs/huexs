<?php
/**
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Google\Dto;

final class LocationDto {

	public function __construct(
		public readonly string $accountName,  // "accounts/123"
		public readonly string $locationName, // "locations/456"
		public readonly string $title,
		public readonly ?string $storeCode = null
	) {}

	/** ID numérico normalizado ("456"). */
	public function locationId(): string {
		$parts = explode( '/', $this->locationName );
		return end( $parts ) ?: $this->locationName;
	}
}
