<?php

declare(strict_types=1);

namespace Huexs\GoogleReviews\Tests\Unit;

use Huexs\GoogleReviews\Frontend\RatingCalculator;
use PHPUnit\Framework\TestCase;

final class RatingCalculatorTest extends TestCase {

	public function testWeightedAverageIsNotMeanOfMeans(): void {
		$result = RatingCalculator::weighted(
			array(
				array( 'average' => 4.0, 'count' => 100 ),
				array( 'average' => 5.0, 'count' => 10 ),
			)
		);

		// Media simple sería 4.5; la ponderada es (400+50)/110 = 4.09.
		self::assertSame( 4.09, $result['average'] );
		self::assertSame( 110, $result['count'] );
	}

	public function testSingleLocation(): void {
		$result = RatingCalculator::weighted( array( array( 'average' => 4.7, 'count' => 23 ) ) );
		self::assertSame( 4.7, $result['average'] );
		self::assertSame( 23, $result['count'] );
	}

	public function testIgnoresLocationsWithoutData(): void {
		$result = RatingCalculator::weighted(
			array(
				array( 'average' => null, 'count' => 50 ),
				array( 'average' => 4.0, 'count' => 0 ),
				array( 'average' => 3.0, 'count' => 10 ),
			)
		);

		self::assertSame( 3.0, $result['average'] );
		self::assertSame( 10, $result['count'] );
	}

	public function testEmptyInputReturnsNull(): void {
		$result = RatingCalculator::weighted( array() );
		self::assertNull( $result['average'] );
		self::assertSame( 0, $result['count'] );
	}
}
