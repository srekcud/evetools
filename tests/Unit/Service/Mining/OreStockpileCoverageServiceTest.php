<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mining;

use App\Enum\MiningLedgerSnapshotStatus;
use App\Service\Mining\OreStockpileCoverageService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(OreStockpileCoverageService::class)]
#[Group('issue-315')]
final class OreStockpileCoverageServiceTest extends TestCase
{
    use BuildsOreStockpileCoverage;

    protected function setUp(): void
    {
        $this->setUpOreStockpileCoverage();
        $this->reprocessingYieldOf($this->aria, 0.9);
        $this->stockpileDeficitsOf($this->aria, [self::TRITANIUM => 1_000, self::PYERITE => 500]);
    }

    public function testTenThousandVeldsparMinedByTheParticipantCoverSixteenPercentOfTheTritaniumDeficit(): void
    {
        $this->minedDuringOperation([
            'Brann Holt' => [self::VELDSPAR => 6_000],
            'Brann II' => [self::VELDSPAR => 4_000],
        ]);
        $this->reprocessingYieldOf($this->brann, 0.8);
        $this->stockpileDeficitsOf($this->brann, [self::TRITANIUM => 200_000, self::MORPHITE => 40]);

        $this->assertCoverage(
            $this->coverage('known', null, true, 0.8, [
                $this->mineral(self::TRITANIUM, 32_000.0, 200_000, true, 16.0, false),
            ]),
            $this->coverageService()->coverageOf($this->brann, $this->brannMember()),
        );
    }

    public function testTritaniumFromVeldsparAndScorditeIsSummedAndPyeriteCoversItsWholeDeficit(): void
    {
        $this->minedDuringOperation([
            'Brann Holt' => [self::VELDSPAR => 10_000],
            'Brann II' => [self::SCORDITE => 5_000],
        ]);
        $this->reprocessingYieldOf($this->brann, 0.8);
        $this->stockpileDeficitsOf($this->brann, [self::PYERITE => 3_000, self::TRITANIUM => 100_000]);

        $this->assertCoverage(
            $this->coverage('known', null, true, 0.8, [
                $this->mineral(self::TRITANIUM, 38_000.0, 100_000, true, 38.0, false),
                $this->mineral(self::PYERITE, 3_600.0, 3_000, true, 100.0, true),
            ]),
            $this->coverageService()->coverageOf($this->brann, $this->brannMember()),
        );
    }

    public function testPyeriteProducedWithoutAnyPyeriteTargetInTheStockpileIsListedWithoutDeficit(): void
    {
        $this->minedDuringOperation(['Brann II' => [self::SCORDITE => 5_000]]);
        $this->reprocessingYieldOf($this->brann, 0.8);
        $this->stockpileDeficitsOf($this->brann, [self::TRITANIUM => 30_000]);

        $this->assertCoverage(
            $this->coverage('known', null, true, 0.8, [
                $this->mineral(self::TRITANIUM, 6_000.0, 30_000, true, 20.0, false),
                $this->mineral(self::PYERITE, 3_600.0, 0, false, null, false),
            ]),
            $this->coverageService()->coverageOf($this->brann, $this->brannMember()),
        );
    }

    public function testShoppingListLineWithAZeroDeficitIsWithoutDeficitAndNeverDividedByZero(): void
    {
        $this->minedDuringOperation(['Brann II' => [self::SCORDITE => 5_000]]);
        $this->reprocessingYieldOf($this->brann, 0.8);
        $this->stockpileDeficitsOf($this->brann, [self::TRITANIUM => 7_500, self::PYERITE => 0]);

        $this->assertCoverage(
            $this->coverage('known', null, true, 0.8, [
                $this->mineral(self::TRITANIUM, 6_000.0, 7_500, true, 80.0, false),
                $this->mineral(self::PYERITE, 3_600.0, 0, false, null, false),
            ]),
            $this->coverageService()->coverageOf($this->brann, $this->brannMember()),
        );
    }

    public function testEmptyStockpileLeavesEveryProducedMineralWithoutDeficit(): void
    {
        $this->minedDuringOperation([
            'Brann Holt' => [self::VELDSPAR => 10_000],
            'Brann II' => [self::SCORDITE => 5_000],
        ]);
        $this->reprocessingYieldOf($this->brann, 0.8);

        $this->assertCoverage(
            $this->coverage('known', null, true, 0.8, [
                $this->mineral(self::TRITANIUM, 38_000.0, 0, false, null, false),
                $this->mineral(self::PYERITE, 3_600.0, 0, false, null, false),
            ]),
            $this->coverageService()->coverageOf($this->brann, $this->brannMember()),
        );
    }

    #[DataProvider('partsWithoutReprocessableOre')]
    public function testPartWithoutReprocessableOreHasNoMineral(array $minedByCharacterName): void
    {
        $this->minedDuringOperation($minedByCharacterName);
        $this->reprocessingYieldOf($this->brann, 0.8);
        $this->stockpileDeficitsOf($this->brann, [self::TRITANIUM => 200_000, self::PYERITE => 3_000]);

        $this->assertCoverage(
            $this->coverage('known', null, true, 0.8, []),
            $this->coverageService()->coverageOf($this->brann, $this->brannMember()),
        );
    }

    public static function partsWithoutReprocessableOre(): iterable
    {
        yield 'only Fullerite-C50 gas' => [['Brann Holt' => [self::FULLERITE_C50 => 2_000]]];
        yield 'nothing mined by Brann while Aria mined' => [['Aria Solberg' => [self::VELDSPAR => 90_000]]];
    }

    #[DataProvider('unknownReprocessingYields')]
    public function testWithoutKnownReprocessingYieldNoMineralHasAProducedQuantityNorACoverage(bool $hasProfile): void
    {
        $this->minedDuringOperation([
            'Brann Holt' => [self::VELDSPAR => 10_000],
            'Brann II' => [self::SCORDITE => 5_000],
        ]);
        if ($hasProfile) {
            $this->reprocessingYieldOf($this->brann, null);
        }
        $this->stockpileDeficitsOf($this->brann, [self::TRITANIUM => 100_000]);

        $this->assertCoverage(
            $this->coverage('known', null, false, null, [
                $this->mineral(self::TRITANIUM, null, 100_000, true, null, null),
                $this->mineral(self::PYERITE, null, 0, false, null, null),
            ]),
            $this->coverageService()->coverageOf($this->brann, $this->brannMember()),
        );
    }

    public static function unknownReprocessingYields(): iterable
    {
        yield 'no reprocessing profile' => [false];
        yield 'profile whose skills could not be read' => [true];
    }

    public function testPartStillMeasuredHasNoCoverageAndIsNeverZero(): void
    {
        $this->operation->start(new \DateTimeImmutable(self::STARTED_AT), $this->s1());
        $this->takenStartSnapshot('Brann Holt', []);
        $this->takenStartSnapshot('Brann II', []);
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));
        $this->takenEndSnapshot('Brann Holt', $this->entriesOf([self::VELDSPAR => 10_000]));
        $this->waitingEndSnapshot('Brann II', '2026-11-05T21:39:50+00:00');
        $this->reprocessingYieldOf($this->brann, 0.8);
        $this->stockpileDeficitsOf($this->brann, [self::TRITANIUM => 200_000]);

        $this->assertCoverage(
            $this->coverage('measuring', null, true, 0.8, []),
            $this->coverageService()->coverageOf($this->brann, $this->brannMember()),
        );
    }

    public function testPartWithAnUnknownCharacterKeepsItsReasonAndHasNoCoverage(): void
    {
        $this->operation->start(new \DateTimeImmutable(self::STARTED_AT), $this->s1());
        $this->takenStartSnapshot('Brann Holt', []);
        $this->failedStartSnapshot('Brann II', MiningLedgerSnapshotStatus::ReconnectRequired);
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));
        $this->takenEndSnapshot('Brann Holt', $this->entriesOf([self::VELDSPAR => 10_000]));
        $this->reprocessingYieldOf($this->brann, 0.8);
        $this->stockpileDeficitsOf($this->brann, [self::TRITANIUM => 200_000]);

        $this->assertCoverage(
            $this->coverage('unknown', 'reconnect_required', true, 0.8, []),
            $this->coverageService()->coverageOf($this->brann, $this->brannMember()),
        );
    }

    public function testScorditeCorrectedToZeroBringsNoMineral(): void
    {
        $this->minedDuringOperation([
            'Brann Holt' => [self::VELDSPAR => 10_000],
            'Brann II' => [self::SCORDITE => 5_000],
        ]);
        $this->enrolmentOf('Brann II')->correct(self::SCORDITE, 5_000, 0, $this->aria, new \DateTimeImmutable('2026-11-05T21:50:00+00:00'));
        $this->reprocessingYieldOf($this->brann, 0.8);
        $this->stockpileDeficitsOf($this->brann, [self::TRITANIUM => 64_000, self::PYERITE => 3_000]);

        $this->assertCoverage(
            $this->coverage('known', null, true, 0.8, [
                $this->mineral(self::TRITANIUM, 32_000.0, 64_000, true, 50.0, false),
            ]),
            $this->coverageService()->coverageOf($this->brann, $this->brannMember()),
        );
    }

    #[DataProvider('unusableOmberPortionSizes')]
    public function testOreWithoutUsablePortionSizeMakesEachOfItsMineralsUnknownEvenWhenAnotherOreProducesIt(?int $omberPortionSize): void
    {
        $this->portionSizes[self::OMBER] = $omberPortionSize;
        $this->minedDuringOperation([
            'Brann Holt' => [self::VELDSPAR => 10_000, self::OMBER => 2_500],
            'Brann II' => [self::SCORDITE => 5_000],
        ]);
        $this->reprocessingYieldOf($this->brann, 0.8);
        $this->stockpileDeficitsOf($this->brann, [self::TRITANIUM => 100_000, self::PYERITE => 3_000, self::ISOGEN => 1_200]);

        $this->assertCoverage(
            $this->coverage('known', null, true, 0.8, [
                $this->mineral(self::TRITANIUM, 38_000.0, 100_000, true, 38.0, false),
                $this->mineral(self::PYERITE, null, 3_000, true, null, null, 'portion_size_unknown'),
                $this->mineral(self::ISOGEN, null, 1_200, true, null, null, 'portion_size_unknown'),
            ]),
            $this->coverageService()->coverageOf($this->brann, $this->brannMember()),
        );
    }

    public static function unusableOmberPortionSizes(): iterable
    {
        yield 'portion size missing from the SDE' => [null];
        yield 'portion size of zero' => [0];
    }

    public function testCoverageOfADeficitSmallerThanTheProducedQuantityIsCappedAtOneHundredPercent(): void
    {
        $this->minedDuringOperation(['Brann Holt' => [self::VELDSPAR => 10_000]]);
        $this->reprocessingYieldOf($this->brann, 0.8);
        $this->stockpileDeficitsOf($this->brann, [self::TRITANIUM => 20_000]);

        $this->assertCoverage(
            $this->coverage('known', null, true, 0.8, [
                $this->mineral(self::TRITANIUM, 32_000.0, 20_000, true, 100.0, true),
            ]),
            $this->coverageService()->coverageOf($this->brann, $this->brannMember()),
        );
    }

    public function testProducedQuantitiesStayUnroundedUseThePortionSizeOfTheirOwnOreAndAreSortedByMineral(): void
    {
        $this->minedDuringOperation([
            'Brann Holt' => [self::PYROXERES => 7_501],
            'Brann II' => [self::VELDSPAR => 333],
        ]);
        $this->reprocessingYieldOf($this->brann, 0.723);
        $this->stockpileDeficitsOf($this->brann, [self::MEXALLON => 2_603, self::PYERITE => 4_000, self::TRITANIUM => 1_700]);

        $this->assertCoverage(
            $this->coverage('known', null, true, 0.723, [
                $this->mineral(self::TRITANIUM, 963.036, 1_700, true, 56.65, false),
                $this->mineral(self::PYERITE, 1_279.880628, 4_000, true, 32.0, false),
                $this->mineral(self::MEXALLON, 2_603.14704, 2_603, true, 100.0, true),
            ]),
            $this->coverageService()->coverageOf($this->brann, $this->brannMember()),
        );
    }

    public function testCoverageRoundedUpToOneHundredPercentDoesNotCoverTheWholeDeficitUnlessTheProducedQuantityReachesIt(): void
    {
        $this->minedDuringOperation([
            'Brann Holt' => [self::VELDSPAR => 1_000_000],
            'Brann II' => [self::SCORDITE => 5_000],
        ]);
        $this->reprocessingYieldOf($this->brann, 0.8);
        $this->stockpileDeficitsOf($this->brann, [self::TRITANIUM => 3_206_100, self::PYERITE => 3_600]);

        $this->assertCoverage(
            $this->coverage('known', null, true, 0.8, [
                $this->mineral(self::TRITANIUM, 3_206_000.0, 3_206_100, true, 100.0, false),
                $this->mineral(self::PYERITE, 3_600.0, 3_600, true, 100.0, true),
            ]),
            $this->coverageService()->coverageOf($this->brann, $this->brannMember()),
        );
    }
}
