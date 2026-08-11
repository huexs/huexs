<?php
/**
 * Página de resultados de reviews.list.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Google\Dto;

final class ReviewsPage {

	/**
	 * @param ReviewDto[] $reviews
	 */
	public function __construct(
		public readonly array $reviews,
		public readonly ?string $nextPageToken,
		public readonly ?float $averageRating,
		public readonly ?int $totalReviewCount
	) {}
}
