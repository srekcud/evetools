<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MoonChunk;

use App\Service\MoonChunk\MoonChunkVolumeEstimator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MoonChunkVolumeEstimator::class)]
#[Group('issue-308')]
final class MoonChunkVolumeEstimatorTest extends TestCase
{
    private MoonChunkVolumeEstimator $estimator;

    protected function setUp(): void
    {
        $this->estimator = new MoonChunkVolumeEstimator();
    }

    public function testTheExtractionRateIsTwentyFiveThousandCubicMetersPerHour(): void
    {
        self::assertSame(25_000, MoonChunkVolumeEstimator::EXTRACTION_RATE_M3_PER_HOUR);
    }

    public function testAnExtractionFromMondayEveningToThursdayEveningYieldsSeventyFourHoursOfVolume(): void
    {
        $volumeM3 = $this->estimator->volumeM3(
            new \DateTimeImmutable('2026-11-02T18:00:00+00:00'),
            new \DateTimeImmutable('2026-11-05T20:00:00+00:00'),
        );

        self::assertSame(1_850_000.0, $volumeM3);
    }

    public function testAnExtractionOfNinetyMinutesYieldsOneAndAHalfHoursOfVolume(): void
    {
        $volumeM3 = $this->estimator->volumeM3(
            new \DateTimeImmutable('2026-11-05T18:00:00+00:00'),
            new \DateTimeImmutable('2026-11-05T19:30:00+00:00'),
        );

        self::assertSame(37_500.0, $volumeM3);
    }

    public function testAnExtractionWithoutDurationOrArrivingBeforeItsStartHasNoVolume(): void
    {
        $start = new \DateTimeImmutable('2026-11-05T18:00:00+00:00');

        self::assertSame(
            [0.0, 0.0],
            [
                $this->estimator->volumeM3($start, $start),
                $this->estimator->volumeM3($start, new \DateTimeImmutable('2026-11-05T17:00:00+00:00')),
            ],
        );
    }
}
