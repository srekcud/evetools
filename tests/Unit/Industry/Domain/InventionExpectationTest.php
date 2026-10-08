<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Decryptor;
use App\Industry\Domain\InstallCost;
use App\Industry\Domain\InventionAttempt;
use App\Industry\Domain\InventionChance;
use App\Industry\Domain\InventionExpectation;
use App\Industry\Domain\InventionOutcome;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\Runs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Spec R8 (benchmark F8), R6 (invention install cost), R10 and #74 (missing probability).
 *
 * attempt cost                = datacores + decryptor + invention install cost (job cost base = 2 % × EIV)
 * cost per successful copy    = attempt cost / P                       (expectation, no ceil)
 * expected attempts for N runs = N / (P × runs of the T2 BPC)
 * cost for N runs             = expected attempts × attempt cost
 *
 * Example: Hobgoblin I Blueprint (typeId 2455) -> Hobgoblin II Blueprint, base probability 0.34, 10 base runs.
 * Invention install cost: §4.5 vector "invention, 1 attempt" = 34 000 ISK.
 */
#[CoversClass(InventionAttempt::class)]
#[CoversClass(InventionExpectation::class)]
#[CoversClass(MissingData::class)]
final class InventionExpectationTest extends TestCase
{
    private const int HOBGOBLIN_I_BLUEPRINT_TYPE_ID = 2455;
    private const float HOBGOBLIN_II_BASE_PROBABILITY = 0.34;
    private const int HOBGOBLIN_II_BASE_RUNS = 10;

    private const float DATACORES_COST_PER_ATTEMPT = 150000.0;
    private const float ATTAINMENT_DECRYPTOR_PRICE = 900000.0;

    private const float ISK_TOLERANCE = 1.0;
    private const float ATTEMPTS_DELTA = 1e-9;

    public function testAttemptCostAddsDatacoresDecryptorAndInventionInstallCost(): void
    {
        // 150 000 + 0 (no decryptor) + 34 000 = 184 000.
        $attemptCost = InventionAttempt::cost(
            Cost::known(new Isk(self::DATACORES_COST_PER_ATTEMPT)),
            Cost::known(new Isk(0.0)),
            self::inventionInstallCost(),
        );

        $this->assertKnownCost(184000.0, $attemptCost);
    }

    public function testAttemptCostIncludesTheDecryptorPrice(): void
    {
        // 150 000 + 900 000 + 34 000 = 1 084 000.
        $attemptCost = InventionAttempt::cost(
            Cost::known(new Isk(self::DATACORES_COST_PER_ATTEMPT)),
            Cost::known(new Isk(self::ATTAINMENT_DECRYPTOR_PRICE)),
            self::inventionInstallCost(),
        );

        $this->assertKnownCost(1084000.0, $attemptCost);
    }

    public function testCostPerSuccessfulCopyIsTheAttemptCostDividedByTheProbabilityWithoutRounding(): void
    {
        // P = 0.34 × (1 + 8/30 + 4/40) = 0.464666…; 184 000 / 0.464666… = 395 982.78.
        // Today InventionService pays ceil(1 / P) = 3 attempts, 552 000 ISK (benchmark F8).
        $expectation = InventionExpectation::of(
            self::HOBGOBLIN_I_BLUEPRINT_TYPE_ID,
            InventionChance::of(self::HOBGOBLIN_II_BASE_PROBABILITY, 4, 4, 4, null),
            InventionOutcome::of(new Runs(self::HOBGOBLIN_II_BASE_RUNS), null),
            Cost::known(new Isk(184000.0)),
        );

        $this->assertKnownCost(395982.78, $expectation->costPerSuccessfulCopy());
    }

    public function testExpectedAttemptsAndCostForWantedT2RunsUseTheRunsOfEachT2BlueprintCopy(): void
    {
        // 25 T2 runs / (0.464666… × 10 runs per BPC) = 5.3802 attempts; × 184 000 = 989 956.96.
        // Today ProfitMarginService counts a single success whatever the runs (#18).
        $expectation = InventionExpectation::of(
            self::HOBGOBLIN_I_BLUEPRINT_TYPE_ID,
            InventionChance::of(self::HOBGOBLIN_II_BASE_PROBABILITY, 4, 4, 4, null),
            InventionOutcome::of(new Runs(self::HOBGOBLIN_II_BASE_RUNS), null),
            Cost::known(new Isk(184000.0)),
        );

        $this->assertEqualsWithDelta(5.3802008608, $expectation->expectedAttempts(new Runs(25)), self::ATTEMPTS_DELTA);
        $this->assertKnownCost(989956.96, $expectation->costForRuns(new Runs(25)));
    }

    public function testDecryptorChangesTheProbabilityTheRunsPerCopyAndTheAttemptCost(): void
    {
        // Attainment: P = 0.464666… × 1.8 = 0.8364, 10 + 4 = 14 runs per BPC, attempt 1 084 000.
        // Per successful copy: 1 084 000 / 0.8364 = 1 296 030.61.
        // 25 T2 runs: 25 / (0.8364 × 14) = 2.1350003416 attempts, × 1 084 000 = 2 314 340.37.
        $attainment = new Decryptor(34202, new Multiplier(1.8), 4, -1, 4);
        $expectation = InventionExpectation::of(
            self::HOBGOBLIN_I_BLUEPRINT_TYPE_ID,
            InventionChance::of(self::HOBGOBLIN_II_BASE_PROBABILITY, 4, 4, 4, $attainment),
            InventionOutcome::of(new Runs(self::HOBGOBLIN_II_BASE_RUNS), $attainment),
            Cost::known(new Isk(1084000.0)),
        );

        $this->assertKnownCost(1296030.61, $expectation->costPerSuccessfulCopy());
        $this->assertEqualsWithDelta(2.1350003416, $expectation->expectedAttempts(new Runs(25)), self::ATTEMPTS_DELTA);
        $this->assertKnownCost(2314340.37, $expectation->costForRuns(new Runs(25)));
    }

    public function testCertainInventionCostsOneAttemptPerCopy(): void
    {
        // P capped at 1: one attempt per BPC, 25 T2 runs / 10 runs per BPC = 2.5 attempts.
        $optimizedAttainment = new Decryptor(34207, new Multiplier(1.9), 2, 1, -2);
        $expectation = InventionExpectation::of(
            self::HOBGOBLIN_I_BLUEPRINT_TYPE_ID,
            InventionChance::of(0.40, 5, 5, 5, $optimizedAttainment),
            InventionOutcome::of(new Runs(self::HOBGOBLIN_II_BASE_RUNS), null),
            Cost::known(new Isk(184000.0)),
        );

        $this->assertKnownCost(184000.0, $expectation->costPerSuccessfulCopy());
        $this->assertEqualsWithDelta(2.5, $expectation->expectedAttempts(new Runs(25)), self::ATTEMPTS_DELTA);
        $this->assertKnownCost(460000.0, $expectation->costForRuns(new Runs(25)));
    }

    public function testMissingProbabilityMakesTheCostsUnknownNeverZero(): void
    {
        // #74: 8 invention rows of the SDE have no probability. Today InventionService::getInventionData() used
        // getProbability() ?? 0.0, then divided by it (benchmark F8).
        $expectation = InventionExpectation::of(
            self::HOBGOBLIN_I_BLUEPRINT_TYPE_ID,
            null,
            InventionOutcome::of(new Runs(self::HOBGOBLIN_II_BASE_RUNS), null),
            Cost::known(new Isk(184000.0)),
        );

        $costPerSuccessfulCopy = $expectation->costPerSuccessfulCopy();
        $this->assertFalse($costPerSuccessfulCopy->isKnown());
        $this->assertEquals([MissingData::inventionProbability(self::HOBGOBLIN_I_BLUEPRINT_TYPE_ID)], $costPerSuccessfulCopy->missingData);

        $costForRuns = $expectation->costForRuns(new Runs(25));
        $this->assertFalse($costForRuns->isKnown());
        $this->assertEquals([MissingData::inventionProbability(self::HOBGOBLIN_I_BLUEPRINT_TYPE_ID)], $costForRuns->missingData);

        $this->assertNull($expectation->expectedAttempts(new Runs(25)));
    }

    public function testMissingProbabilityNamesTheBlueprintItIsInventedFrom(): void
    {
        $missingProbability = MissingData::inventionProbability(self::HOBGOBLIN_I_BLUEPRINT_TYPE_ID);

        $this->assertSame('invention_probability', $missingProbability->reason);
        $this->assertSame(2455, $missingProbability->typeId);
        $this->assertNotEquals(MissingData::adjustedPrice(2455), $missingProbability);
    }

    public function testUnknownAttemptCostKeepsTheExpectedAttemptsKnown(): void
    {
        // R10: quantities never depend on a price. A missing cost index makes the costs unknown, not the attempts.
        $attemptCost = InventionAttempt::cost(
            Cost::known(new Isk(self::DATACORES_COST_PER_ATTEMPT)),
            Cost::known(new Isk(0.0)),
            InstallCost::forJob(ActivityKind::Invention, Cost::known(new Isk(10000000.0)), null, Multiplier::one(), 0.05, false),
        );
        $expectation = InventionExpectation::of(
            self::HOBGOBLIN_I_BLUEPRINT_TYPE_ID,
            InventionChance::of(self::HOBGOBLIN_II_BASE_PROBABILITY, 4, 4, 4, null),
            InventionOutcome::of(new Runs(self::HOBGOBLIN_II_BASE_RUNS), null),
            $attemptCost,
        );

        $this->assertFalse($attemptCost->isKnown());
        $this->assertFalse($expectation->costPerSuccessfulCopy()->isKnown());
        $this->assertEquals([MissingData::costIndex()], $expectation->costPerSuccessfulCopy()->missingData);
        $this->assertEquals([MissingData::costIndex()], $expectation->costForRuns(new Runs(25))->missingData);
        $this->assertEqualsWithDelta(5.3802008608, $expectation->expectedAttempts(new Runs(25)), self::ATTEMPTS_DELTA);
    }

    public function testMissingProbabilityAndUnknownAttemptCostAreBothListed(): void
    {
        $expectation = InventionExpectation::of(
            self::HOBGOBLIN_I_BLUEPRINT_TYPE_ID,
            null,
            InventionOutcome::of(new Runs(self::HOBGOBLIN_II_BASE_RUNS), null),
            Cost::unknown(MissingData::costIndex()),
        );

        $this->assertEqualsCanonicalizing(
            [MissingData::inventionProbability(self::HOBGOBLIN_I_BLUEPRINT_TYPE_ID), MissingData::costIndex()],
            $expectation->costPerSuccessfulCopy()->missingData,
        );
        $this->assertEqualsCanonicalizing(
            [MissingData::inventionProbability(self::HOBGOBLIN_I_BLUEPRINT_TYPE_ID), MissingData::costIndex()],
            $expectation->costForRuns(new Runs(25))->missingData,
        );
    }

    /**
     * §4.5 "invention, 1 attempt": job cost base 2 % × 10 000 000 = 200 000, index 0.08, tax 5 %, SCC 4 % -> 34 000.
     */
    private static function inventionInstallCost(): InstallCost
    {
        return InstallCost::forJob(ActivityKind::Invention, Cost::known(new Isk(10000000.0)), 0.08, Multiplier::one(), 0.05, false);
    }

    private function assertKnownCost(float $expectedAmount, Cost $cost): void
    {
        $this->assertTrue($cost->isKnown(), 'cost should be known');
        $this->assertEqualsWithDelta($expectedAmount, $cost->amount()->amount, self::ISK_TOLERANCE);
    }
}
