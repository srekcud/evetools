<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Enum\MiningBoostShip;
use App\Enum\MiningCompressorKind;
use App\Service\MiningOperation\MiningCompressionAssessment;
use App\Service\MiningOperation\MiningCompressionAssessor;
use App\Service\MiningOperation\MiningTypeCompression;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningCompressionAssessor::class)]
#[CoversClass(MiningCompressionAssessment::class)]
#[CoversClass(MiningTypeCompression::class)]
#[Group('issue-311')]
final class MiningCompressionAssessorTest extends TestCase
{
    private const int SPODUMAIN = 19;
    private const int VELDSPAR = 1230;
    private const int BLUE_ICE = 16264;
    private const int MERCOXIT = 11396;
    private const int FULLERITE_C50 = 30375;
    private const int ZEOLITES = 45490;
    private const int BITUMENS = 45492;
    private const int TYPE_ABSENT_FROM_SDE = 999_999_311;

    private const array PORPOISE_MOUNTABLE = [MiningCompressorKind::Asteroid, MiningCompressorKind::Gas];
    private const array ORCA_MOUNTABLE = [MiningCompressorKind::Asteroid, MiningCompressorKind::Gas, MiningCompressorKind::Ice, MiningCompressorKind::Mercoxit, MiningCompressorKind::Moon];

    private MiningCompressionAssessor $assessor;

    protected function setUp(): void
    {
        $this->assessor = new MiningCompressionAssessor();
    }

    public function testSpodumainIsCompressibleOnSiteWithAnAsteroidCompressorMountedOnThePorpoise(): void
    {
        $assessment = $this->assessor->assess(MiningBoostShip::Porpoise, self::PORPOISE_MOUNTABLE, self::PORPOISE_MOUNTABLE, [self::SPODUMAIN => MiningCompressorKind::Asteroid]);

        self::assertSame([[[self::SPODUMAIN, true, 'on_site']], [], []], $this->summary($assessment));
    }

    public function testZeolitesHaveNoCompressorOnThePorpoiseAndLandInTheNoHullGap(): void
    {
        $assessment = $this->assessor->assess(MiningBoostShip::Porpoise, self::PORPOISE_MOUNTABLE, self::PORPOISE_MOUNTABLE, [self::ZEOLITES => MiningCompressorKind::Moon]);

        self::assertSame([[[self::ZEOLITES, false, 'no_hull_compressor']], ['moon'], []], $this->summary($assessment));
    }

    public function testBlueIceIsNotMountedOnTheOrcaWithOnlyAnAsteroidCompressor(): void
    {
        $assessment = $this->assessor->assess(MiningBoostShip::Orca, self::ORCA_MOUNTABLE, [MiningCompressorKind::Asteroid], [self::BLUE_ICE => MiningCompressorKind::Ice]);

        self::assertSame([[[self::BLUE_ICE, false, 'not_mounted']], [], ['ice']], $this->summary($assessment));
    }

    public function testMercoxitIsCompressibleOnSiteWhenTheOrcaMountsEveryCompressor(): void
    {
        $assessment = $this->assessor->assess(MiningBoostShip::Orca, self::ORCA_MOUNTABLE, self::ORCA_MOUNTABLE, [self::MERCOXIT => MiningCompressorKind::Mercoxit]);

        self::assertSame([[[self::MERCOXIT, true, 'on_site']], [], []], $this->summary($assessment));
    }

    public function testVeldsparIsNotMountedOnARorqualWithoutAnyMountedCompressor(): void
    {
        $assessment = $this->assessor->assess(MiningBoostShip::Rorqual, self::ORCA_MOUNTABLE, [], [self::VELDSPAR => MiningCompressorKind::Asteroid]);

        self::assertSame([[[self::VELDSPAR, false, 'not_mounted']], [], ['asteroid']], $this->summary($assessment));
    }

    public function testWithoutBoostShipEveryTypeIsUndeterminedWhateverItsKindAndNoGapIsRaised(): void
    {
        $assessment = $this->assessor->assess(null, [], [], [self::VELDSPAR => MiningCompressorKind::Asteroid, self::TYPE_ABSENT_FROM_SDE => null]);

        self::assertSame(
            [[[self::VELDSPAR, null, 'no_boost_ship'], [self::TYPE_ABSENT_FROM_SDE, null, 'no_boost_ship']], [], []],
            $this->summary($assessment),
        );
    }

    public function testTypeAbsentFromTheSdeIsUnknownOreKindEvenWithEveryCompressorMountedOnTheOrca(): void
    {
        $assessment = $this->assessor->assess(MiningBoostShip::Orca, self::ORCA_MOUNTABLE, self::ORCA_MOUNTABLE, [self::TYPE_ABSENT_FROM_SDE => null]);

        self::assertSame([[[self::TYPE_ABSENT_FROM_SDE, null, 'unknown_ore_kind']], [], []], $this->summary($assessment));
    }

    public function testFulleriteC50IsCompressibleOnSiteWithOnlyAGasCompressorMountedOnThePorpoise(): void
    {
        $assessment = $this->assessor->assess(MiningBoostShip::Porpoise, self::PORPOISE_MOUNTABLE, [MiningCompressorKind::Gas], [self::FULLERITE_C50 => MiningCompressorKind::Gas]);

        self::assertSame([[[self::FULLERITE_C50, true, 'on_site']], [], []], $this->summary($assessment));
    }

    public function testSpodumainZeolitesAndBlueIceGiveThreeVerdictsAndTheMoonAndIceNoHullGaps(): void
    {
        $assessment = $this->assessor->assess(
            MiningBoostShip::Porpoise,
            self::PORPOISE_MOUNTABLE,
            self::PORPOISE_MOUNTABLE,
            [self::SPODUMAIN => MiningCompressorKind::Asteroid, self::ZEOLITES => MiningCompressorKind::Moon, self::BLUE_ICE => MiningCompressorKind::Ice],
        );

        self::assertSame(
            [[[self::SPODUMAIN, true, 'on_site'], [self::ZEOLITES, false, 'no_hull_compressor'], [self::BLUE_ICE, false, 'no_hull_compressor']], ['moon', 'ice'], []],
            $this->summary($assessment),
        );
    }

    public function testTwoTypesOfTheSameKindRaiseTheirGapOnlyOnce(): void
    {
        $assessment = $this->assessor->assess(MiningBoostShip::Porpoise, self::PORPOISE_MOUNTABLE, self::PORPOISE_MOUNTABLE, [self::ZEOLITES => MiningCompressorKind::Moon, self::BITUMENS => MiningCompressorKind::Moon]);

        self::assertSame(
            [[[self::ZEOLITES, false, 'no_hull_compressor'], [self::BITUMENS, false, 'no_hull_compressor']], ['moon'], []],
            $this->summary($assessment),
        );
    }

    public function testKindWithoutCompressorOnTheHullIsNoHullCompressorWhileAMountableUnmountedKindIsNotMounted(): void
    {
        $assessment = $this->assessor->assess(MiningBoostShip::Porpoise, self::PORPOISE_MOUNTABLE, [], [self::ZEOLITES => MiningCompressorKind::Moon, self::SPODUMAIN => MiningCompressorKind::Asteroid]);

        self::assertSame(
            [[[self::ZEOLITES, false, 'no_hull_compressor'], [self::SPODUMAIN, false, 'not_mounted']], ['moon'], ['asteroid']],
            $this->summary($assessment),
        );
    }

    private function summary(MiningCompressionAssessment $assessment): array
    {
        return [
            array_map(
                static fn (MiningTypeCompression $verdict): array => [$verdict->typeId, $verdict->compressible, $verdict->status->value],
                $assessment->verdicts,
            ),
            array_map(static fn (MiningCompressorKind $kind): string => $kind->value, $assessment->noHullKinds),
            array_map(static fn (MiningCompressorKind $kind): string => $kind->value, $assessment->notMountedKinds),
        ];
    }
}
