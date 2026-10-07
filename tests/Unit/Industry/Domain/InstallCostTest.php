<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Cost;
use App\Industry\Domain\InstallCost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Multiplier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec R6 (D11a), vectors of §4.5 (tests/Fixtures/Golden/install-cost-vectors.json). Rates are fractions, tolerance 1 ISK.
 *
 * base         = EIV (manufacturing, reaction) or 2 % × EIV (copying, invention: job cost base)
 * system cost  = base × cost index × structure cost modifier
 * facility tax = base × facility tax rate
 * SCC          = base × 4 %
 * alpha        = base × 0.25 % (alpha clone only)
 * total        = system cost + facility tax + SCC + alpha
 */
#[CoversClass(InstallCost::class)]
final class InstallCostTest extends TestCase
{
    private const string GOLDEN_DIRECTORY = __DIR__.'/../../../Fixtures/Golden';

    private const float ISK_TOLERANCE = 1.0;

    /**
     * @param array{systemCost: int, facilityTax: int, sccSurcharge: int, alphaTax: int, total: int} $expected
     */
    #[DataProvider('installCostVectorProvider')]
    public function testInstallCostMatchesGoldenVector(
        ActivityKind $activity,
        float $estimatedItemValue,
        float $systemCostIndex,
        float $structureCostModifier,
        float $facilityTaxRate,
        bool $alphaClone,
        array $expected,
    ): void {
        $installCost = InstallCost::forJob(
            $activity,
            Cost::known(new Isk($estimatedItemValue)),
            $systemCostIndex,
            new Multiplier($structureCostModifier),
            $facilityTaxRate,
            $alphaClone,
        );

        $this->assertKnownCost($expected['systemCost'], $installCost->systemCost, 'system cost');
        $this->assertKnownCost($expected['facilityTax'], $installCost->facilityTax, 'facility tax');
        $this->assertKnownCost($expected['sccSurcharge'], $installCost->sccSurcharge, 'SCC surcharge');
        $this->assertKnownCost($expected['alphaTax'], $installCost->alphaCloneTax, 'alpha clone tax');
        $this->assertKnownCost($expected['total'], $installCost->total, 'total');
    }

    /**
     * @return iterable<string, array{ActivityKind, float, float, float, float, bool, array<string, int>}>
     */
    public static function installCostVectorProvider(): iterable
    {
        foreach (self::installCostVectors() as $vector) {
            // Copying waits for D11a (see testCopyingInstallCostWithSccSurcharge); absent data has its own tests.
            if ('copying' === $vector['activity'] || null === ($vector['systemCostIndex'] ?? null)) {
                continue;
            }

            yield '4.5 '.$vector['name'] => [
                self::activityKind($vector['activity']),
                self::estimatedItemValueOf($vector),
                (float) $vector['systemCostIndex'],
                (float) $vector['structureCostModifier'],
                (float) $vector['facilityTax'],
                $vector['alpha'],
                $vector['expected'],
            ];
        }
    }

    public function testStructureCostBonusAppliesToTheCostIndexTermOnly(): void
    {
        // §4.5 Raitaru ×0.97: only 50 000 -> 48 500; the facility tax and the SCC stay on the full EIV.
        $installCost = InstallCost::forJob(
            ActivityKind::Manufacturing,
            Cost::known(new Isk(1000000.0)),
            0.05,
            new Multiplier(0.97),
            0.10,
            false,
        );

        $this->assertKnownCost(48500, $installCost->systemCost, 'system cost');
        $this->assertKnownCost(100000, $installCost->facilityTax, 'facility tax');
        $this->assertKnownCost(40000, $installCost->sccSurcharge, 'SCC surcharge');
    }

    public function testCopyingInstallCostIsBasedOnTwoPercentOfTheCopiedBlueprintEiv(): void
    {
        // §4.5 copying, 10 runs: base = 2 % × 5 000 000 × 10 = 1 000 000. Confirmed by EVE Ref, independent of D11a.
        $vector = self::installCostVector('copying-ten-runs');

        $installCost = InstallCost::forJob(
            ActivityKind::Copying,
            Cost::known(new Isk(self::estimatedItemValueOf($vector))),
            (float) $vector['systemCostIndex'],
            new Multiplier((float) $vector['structureCostModifier']),
            (float) $vector['facilityTax'],
            $vector['alpha'],
        );

        $this->assertKnownCost($vector['expected']['systemCost'], $installCost->systemCost, 'system cost');
        $this->assertKnownCost($vector['expected']['facilityTax'], $installCost->facilityTax, 'facility tax');
        $this->assertKnownCost($vector['expected']['alphaTax'], $installCost->alphaCloneTax, 'alpha clone tax');
    }

    public function testCopyingInstallCostWithSccSurcharge(): void
    {
        $this->markTestIncomplete('D11a : SCC sur copie en attente de vérification en jeu');

        // §4.5 copying, 10 runs: SCC 4 % of the job cost base = 40 000, total 60 000. Remove the line above once D11a is confirmed.
        $vector = self::installCostVector('copying-ten-runs');

        $installCost = InstallCost::forJob(
            ActivityKind::Copying,
            Cost::known(new Isk(self::estimatedItemValueOf($vector))),
            (float) $vector['systemCostIndex'],
            new Multiplier((float) $vector['structureCostModifier']),
            (float) $vector['facilityTax'],
            $vector['alpha'],
        );

        $this->assertKnownCost($vector['expected']['sccSurcharge'], $installCost->sccSurcharge, 'SCC surcharge');
        $this->assertKnownCost($vector['expected']['total'], $installCost->total, 'total');
    }

    public function testMissingCostIndexMakesTheSystemCostAndTheTotalUnknown(): void
    {
        // §4.5 vector "missing-cost-index": today evetools returns 0. The parts that do not need the index stay known.
        $vector = self::installCostVector('missing-cost-index');

        $installCost = InstallCost::forJob(
            self::activityKind($vector['activity']),
            Cost::known(new Isk((float) $vector['eiv'])),
            $vector['systemCostIndex'],
            Multiplier::one(),
            (float) $vector['facilityTax'],
            false,
        );

        $this->assertFalse($installCost->systemCost->isKnown());
        $this->assertEquals([MissingData::costIndex()], $installCost->systemCost->missingData);
        $this->assertKnownCost(100000, $installCost->facilityTax, 'facility tax');
        $this->assertKnownCost(40000, $installCost->sccSurcharge, 'SCC surcharge');
        $this->assertKnownCost(0, $installCost->alphaCloneTax, 'alpha clone tax');
        $this->assertFalse($installCost->total->isKnown());
        $this->assertEquals([MissingData::costIndex()], $installCost->total->missingData);
    }

    public function testUnknownEivMakesEveryPartUnknownAndListsTheTypeIdOnce(): void
    {
        $unknownEiv = Cost::unknown(MissingData::adjustedPrice(35));

        $installCost = InstallCost::forJob(ActivityKind::Manufacturing, $unknownEiv, 0.05, Multiplier::one(), 0.10, false);

        foreach (['systemCost', 'facilityTax', 'sccSurcharge', 'alphaCloneTax'] as $part) {
            $this->assertFalse($installCost->{$part}->isKnown(), $part);
            $this->assertEquals([MissingData::adjustedPrice(35)], $installCost->{$part}->missingData, $part);
        }
        $this->assertFalse($installCost->total->isKnown());
        $this->assertEquals([MissingData::adjustedPrice(35)], $installCost->total->missingData);
    }

    public function testUnknownEivAndMissingCostIndexAreBothListed(): void
    {
        $unknownEiv = Cost::unknown(MissingData::adjustedPrice(35));

        $installCost = InstallCost::forJob(ActivityKind::Manufacturing, $unknownEiv, null, Multiplier::one(), 0.10, false);

        $this->assertEqualsCanonicalizing(
            [MissingData::adjustedPrice(35), MissingData::costIndex()],
            $installCost->total->missingData,
        );
    }

    public function testZeroCostIndexIsAKnownIndexNotAMissingOne(): void
    {
        $installCost = InstallCost::forJob(
            ActivityKind::Manufacturing,
            Cost::known(new Isk(1000000.0)),
            0.0,
            Multiplier::one(),
            0.10,
            false,
        );

        $this->assertKnownCost(0, $installCost->systemCost, 'system cost');
        $this->assertKnownCost(140000, $installCost->total, 'total');
    }

    public function testAlphaCloneTaxIsAQuarterPercentOfTheBase(): void
    {
        // Invention base = 2 % × 10 000 000 = 200 000, alpha = 0.25 % × 200 000 = 500.
        $installCost = InstallCost::forJob(
            ActivityKind::Invention,
            Cost::known(new Isk(10000000.0)),
            0.08,
            Multiplier::one(),
            0.05,
            true,
        );

        $this->assertKnownCost(500, $installCost->alphaCloneTax, 'alpha clone tax');
        $this->assertKnownCost(34500, $installCost->total, 'total');
    }

    #[DataProvider('invalidRateProvider')]
    public function testRefusesANegativeOrNonFiniteRate(float $systemCostIndex, float $facilityTaxRate): void
    {
        $this->expectException(\InvalidArgumentException::class);

        InstallCost::forJob(
            ActivityKind::Manufacturing,
            Cost::known(new Isk(1000000.0)),
            $systemCostIndex,
            Multiplier::one(),
            $facilityTaxRate,
            false,
        );
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function invalidRateProvider(): iterable
    {
        yield 'negative cost index' => [-0.05, 0.10];
        yield 'negative facility tax' => [0.05, -0.10];
        yield 'NaN cost index' => [\NAN, 0.10];
        yield 'infinite facility tax' => [0.05, \INF];
    }

    /**
     * The rates are checked before any computation: an unknown EIV or a zero EIV, where no ISK amount
     * would expose the invalid rate, must not let it through either.
     */
    #[DataProvider('invalidRateWithoutComputableAmountProvider')]
    public function testRefusesAnInvalidRateEvenWhenTheEivHidesIt(
        Cost $estimatedItemValue,
        float $systemCostIndex,
        float $facilityTaxRate,
    ): void {
        $this->expectException(\InvalidArgumentException::class);

        InstallCost::forJob(
            ActivityKind::Manufacturing,
            $estimatedItemValue,
            $systemCostIndex,
            Multiplier::one(),
            $facilityTaxRate,
            false,
        );
    }

    /**
     * @return iterable<string, array{Cost, float, float}>
     */
    public static function invalidRateWithoutComputableAmountProvider(): iterable
    {
        $estimatedItemValues = [
            'unknown EIV' => Cost::unknown(MissingData::adjustedPrice(35)),
            'zero EIV' => Cost::known(new Isk(0.0)),
        ];
        $invalidRates = [
            'negative cost index' => [-0.05, 0.10],
            'NaN cost index' => [\NAN, 0.10],
            'infinite cost index' => [\INF, 0.10],
            'negative facility tax' => [0.05, -0.10],
            'NaN facility tax' => [0.05, \NAN],
            'infinite facility tax' => [0.05, \INF],
        ];

        foreach ($estimatedItemValues as $eivName => $estimatedItemValue) {
            foreach ($invalidRates as $rateName => [$systemCostIndex, $facilityTaxRate]) {
                yield "{$rateName}, {$eivName}" => [$estimatedItemValue, $systemCostIndex, $facilityTaxRate];
            }
        }
    }

    private function assertKnownCost(int|float $expectedAmount, Cost $cost, string $part): void
    {
        $this->assertTrue($cost->isKnown(), "{$part} should be known");
        $this->assertEqualsWithDelta((float) $expectedAmount, $cost->amount()->amount, self::ISK_TOLERANCE, $part);
    }

    /**
     * EIV over the runs of the job; for copying and invention, the manufacturing EIV of the copied or invented blueprint.
     *
     * @param array<string, mixed> $vector
     */
    private static function estimatedItemValueOf(array $vector): float
    {
        return match ($vector['activity']) {
            'invention' => (float) ($vector['eivOfInventedBlueprintManufacturingMaterialsPerRun'] * $vector['inventionRuns']),
            'copying' => (float) ($vector['eivOfBlueprintManufacturingMaterialsPerRun'] * $vector['copyRunsTotal']),
            default => (float) $vector['eiv'],
        };
    }

    private static function activityKind(string $activity): ActivityKind
    {
        return match ($activity) {
            'manufacturing' => ActivityKind::Manufacturing,
            'reaction' => ActivityKind::Reaction,
            'copying' => ActivityKind::Copying,
            'invention' => ActivityKind::Invention,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function installCostVector(string $name): array
    {
        foreach (self::installCostVectors() as $vector) {
            if ($name === $vector['name']) {
                return $vector;
            }
        }

        self::fail("Install cost vector {$name} not found");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function installCostVectors(): array
    {
        $json = file_get_contents(self::GOLDEN_DIRECTORY.'/install-cost-vectors.json');
        self::assertIsString($json, 'Golden fixture install-cost-vectors.json is missing');

        return json_decode($json, true, 512, \JSON_THROW_ON_ERROR)['vectors'];
    }
}
