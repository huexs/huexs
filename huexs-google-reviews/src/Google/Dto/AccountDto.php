<?php
/**
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Google\Dto;

final class AccountDto {

	public function __construct(
		public readonly string $name,        // p. ej. "accounts/1234567890"
		public readonly string $accountName, // nombre visible
		public readonly string $type
	) {}
}
