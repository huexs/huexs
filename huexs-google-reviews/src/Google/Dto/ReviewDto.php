<?php
/**
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Google\Dto;

final class ReviewDto {

	public function __construct(
		public readonly string $googleReviewId,
		public readonly ?string $reviewerName,
		public readonly ?string $reviewerPhotoUrl,
		public readonly int $starRating,          // 1–5
		public readonly ?string $comment,
		public readonly ?string $createTime,      // UTC "Y-m-d H:i:s"
		public readonly ?string $updateTime,
		public readonly ?string $replyComment,
		public readonly ?string $replyUpdateTime
	) {}
}
