<?php
/**
 * Cálculo de valoración combinada: media ponderada por número de reseñas, nunca media de medias.
 *
 * @package Huexs\GoogleReviews
 */

namespace Huexs\GoogleReviews\Frontend;

final class RatingCalculator {

	/**
	 * @param array<int, array{average: ?float, count: int}> $locations
	 * @return array{average: ?float, count: int}
	 */
	public static function weighted( array $locations ): array {
		$weightedSum = 0.0;
		$totalCount  = 0;

		foreach ( $locations as $location ) {
			$average = $location['average'] ?? null;
			$count   = (int) ( $location['count'] ?? 0 );
			if ( null === $average || $count <= 0 ) {
				continue;
			}
			$weightedSum += (float) $average * $count;
			$totalCount  += $count;
		}

		if ( 0 === $totalCount ) {
			return array(
				'average' => null,
				'count'   => 0,
			);
		}

		return array(
			'average' => round( $weightedSum / $totalCount, 2 ),
			'count'   => $totalCount,
		);
	}
}
