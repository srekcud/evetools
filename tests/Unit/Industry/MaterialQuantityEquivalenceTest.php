<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry;

use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\MaterialQuantity;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\RigBonus;
use App\Industry\Domain\RigSecurityModifiers;
use App\Industry\Domain\Runs;
use App\Industry\Domain\SecurityClass;
use App\Service\Industry\MaterialQuantityFormula;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MaterialQuantityFormula::class)]
#[CoversClass(MaterialQuantity::class)]
final class MaterialQuantityEquivalenceTest extends TestCase
{
    private const array BASE_QUANTITIES_PER_RUN = [1, 3, 7, 10, 13, 100, 997, 4000];
    private const array RUNS = [1, 2, 10, 32, 100, 1000];
    private const array ME_LEVELS = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
    private const array STRUCTURE_BONUS_TOTAL_PERCENTS = [3.61, 5.16, 5.97, 5.99, 6.94];
    private const array OVERRIDE_BASE_QUANTITIES_PER_RUN = [1, 3, 13, 100, 997, 4000];
    private const array OVERRIDE_RUNS = [1, 10, 100, 1000];

    private const array STRUCTURE_BONUSES = [
        'no structure bonus' => ['percent' => 0.0, 'multiplier' => 1.0],
        'structure bonus 1 %' => ['percent' => 1.0, 'multiplier' => 0.99],
        'structure bonus 2 %' => ['percent' => 2.0, 'multiplier' => 0.98],
    ];

    private const array RIGS = [
        'no rig' => [],
        'rig 2.4 % x 1.1' => [[2.4, 1.1]],
        'rig 2.0 % x 2.1' => [[2.0, 2.1]],
        'rig 2.4 % x 2.1' => [[2.4, 2.1]],
    ];

    private const int MINIMUM_CASE_ME_LEVEL = 10;
    private const string MINIMUM_CASE_STRUCTURE = 'structure bonus 2 %';
    private const string MINIMUM_CASE_RIG = 'rig 2.4 % x 2.1';
    private const array MINIMUM_CASE_BASE_QUANTITIES_PER_RUN = [1, 2, 3, 4, 5, 6, 7];

    #[DataProvider('manufacturingJobProvider')]
    #[DataProvider('roundingBoundaryProvider')]
    #[DataProvider('minimumOneUnitPerRunProvider')]
    public function testManufacturingJobQuantityIsTheSameInBothFormulas(
        int $baseQuantityPerRun,
        int $runs,
        int $meLevel,
        float $structureBonusPercent,
        float $structureMultiplier,
        float $rigBonusPercent,
        array $rigs,
    ): void {
        $legacyQuantity = MaterialQuantityFormula::forJob($baseQuantityPerRun, $runs, $meLevel, $structureBonusPercent, $rigBonusPercent);

        $coreQuantity = self::coreManufacturingQuantity($baseQuantityPerRun, $runs, $meLevel, self::coreMaterialModifier($structureMultiplier, $rigs));

        $this->assertSame($legacyQuantity, $coreQuantity);
    }

    #[DataProvider('structureBonusTotalProvider')]
    public function testStructureBonusTotalWithoutRigGivesTheSameQuantityInBothFormulas(
        int $baseQuantityPerRun,
        int $runs,
        int $meLevel,
        float $structureBonusTotalPercent,
    ): void {
        $legacyQuantity = MaterialQuantityFormula::forJob($baseQuantityPerRun, $runs, $meLevel, $structureBonusTotalPercent, 0.0);

        $coreQuantity = self::coreManufacturingQuantity($baseQuantityPerRun, $runs, $meLevel, new Multiplier(1 - $structureBonusTotalPercent / 100));

        $this->assertSame($legacyQuantity, $coreQuantity);
    }

    #[DataProvider('runsProvider')]
    public function testOneUnitPerRunIsTheMinimumInBothFormulas(int $runs): void
    {
        $structureBonus = self::STRUCTURE_BONUSES[self::MINIMUM_CASE_STRUCTURE];
        $rigs = self::RIGS[self::MINIMUM_CASE_RIG];

        $legacyQuantity = MaterialQuantityFormula::forJob(1, $runs, self::MINIMUM_CASE_ME_LEVEL, $structureBonus['percent'], self::legacyRigBonusPercent($rigs));
        $coreQuantity = self::coreManufacturingQuantity(1, $runs, self::MINIMUM_CASE_ME_LEVEL, self::coreMaterialModifier($structureBonus['multiplier'], $rigs));

        $this->assertSame($runs, $legacyQuantity);
        $this->assertSame($runs, $coreQuantity);
    }

    #[DataProvider('reactionJobProvider')]
    public function testReactionJobQuantityIsTheSameInBothFormulas(int $baseQuantityPerRun, int $runs, float $rigBonusPercent, array $rigs): void
    {
        $legacyQuantity = MaterialQuantityFormula::forJob($baseQuantityPerRun, $runs, 0, 0.0, $rigBonusPercent);

        $coreQuantity = self::coreReactionQuantity($baseQuantityPerRun, $runs, $rigs);

        $this->assertSame($legacyQuantity, $coreQuantity);
    }

    #[DataProvider('multipleRigsManufacturingProvider')]
    #[Group('issue-141')]
    public function testMultipleRigsAreCurrentlyAddedByLegacyButMultipliedByCoreIssue141(
        int $baseQuantityPerRun,
        int $runs,
        int $meLevel,
        string $structureLabel,
        array $rigs,
        float $expectedLegacyRigBonusPercent,
        int $expectedLegacyQuantity,
        int $expectedCoreQuantity,
    ): void {
        $structureBonus = self::STRUCTURE_BONUSES[$structureLabel];
        $legacyRigBonusPercent = self::legacyRigBonusPercent($rigs);

        $legacyQuantity = MaterialQuantityFormula::forJob($baseQuantityPerRun, $runs, $meLevel, $structureBonus['percent'], $legacyRigBonusPercent);
        $coreQuantity = self::coreManufacturingQuantity($baseQuantityPerRun, $runs, $meLevel, self::coreMaterialModifier($structureBonus['multiplier'], $rigs));

        $this->assertSame($expectedLegacyRigBonusPercent, $legacyRigBonusPercent);
        $this->assertSame($expectedLegacyQuantity, $legacyQuantity);
        $this->assertSame($expectedCoreQuantity, $coreQuantity);
    }

    #[DataProvider('multipleRigsReactionProvider')]
    #[Group('issue-141')]
    public function testMultipleReactionRigsAreCurrentlyAddedByLegacyButMultipliedByCoreIssue141(
        int $baseQuantityPerRun,
        int $runs,
        array $rigs,
        float $expectedLegacyRigBonusPercent,
        int $expectedLegacyQuantity,
        int $expectedCoreQuantity,
    ): void {
        $legacyRigBonusPercent = self::legacyRigBonusPercent($rigs);

        $legacyQuantity = MaterialQuantityFormula::forJob($baseQuantityPerRun, $runs, 0, 0.0, $legacyRigBonusPercent);
        $coreQuantity = self::coreReactionQuantity($baseQuantityPerRun, $runs, $rigs);

        $this->assertSame($expectedLegacyRigBonusPercent, $legacyRigBonusPercent);
        $this->assertSame($expectedLegacyQuantity, $legacyQuantity);
        $this->assertSame($expectedCoreQuantity, $coreQuantity);
    }

    #[DataProvider('reactionWithMeProvider')]
    #[Group('issue-141')]
    public function testLegacyFormulaCurrentlyAppliesTheMeToAReactionIssue141(
        int $baseQuantityPerRun,
        int $runs,
        array $rigs,
        int $expectedLegacyQuantity,
        int $expectedCoreQuantity,
    ): void {
        $legacyQuantity = MaterialQuantityFormula::forJob($baseQuantityPerRun, $runs, 10, 0.0, self::legacyRigBonusPercent($rigs));
        $coreQuantity = self::coreReactionQuantity($baseQuantityPerRun, $runs, $rigs);

        $this->assertSame($expectedLegacyQuantity, $legacyQuantity);
        $this->assertSame($expectedCoreQuantity, $coreQuantity);
    }

    public function testManufacturingCrossedPointsCoverEveryCombination(): void
    {
        $points = iterator_to_array(self::manufacturingJobProvider());

        $this->assertCount(6336, $points);
        $this->assertSame(self::BASE_QUANTITIES_PER_RUN, self::distinctColumn($points, 0));
        $this->assertSame(self::RUNS, self::distinctColumn($points, 1));
        $this->assertSame(self::ME_LEVELS, self::distinctColumn($points, 2));
        $this->assertSame([0.0, 1.0, 2.0], self::distinctColumn($points, 3));
        $this->assertSame([1.0, 0.99, 0.98], self::distinctColumn($points, 4));
        $this->assertSame([0.0, 2.64, 4.2, 5.04], self::distinctColumn($points, 5));
        $this->assertSame(
            [997, 1000, 7, 1.0, 0.99, 5.04, [[2.4, 2.1]]],
            $points['base 997, 1000 runs, ME 7, structure bonus 1 %, rig 2.4 % x 2.1'],
        );
        $this->assertSame(
            [3, 32, 0, 2.0, 0.98, 2.64, [[2.4, 1.1]]],
            $points['base 3, 32 runs, ME 0, structure bonus 2 %, rig 2.4 % x 1.1'],
        );
    }

    public function testOtherCrossedPointsCoverEveryCombination(): void
    {
        $structureBonusTotalPoints = iterator_to_array(self::structureBonusTotalProvider());
        $reactionPoints = iterator_to_array(self::reactionJobProvider());
        $minimumPoints = iterator_to_array(self::minimumOneUnitPerRunProvider());

        $this->assertCount(1320, $structureBonusTotalPoints);
        $this->assertSame(self::OVERRIDE_BASE_QUANTITIES_PER_RUN, self::distinctColumn($structureBonusTotalPoints, 0));
        $this->assertSame(self::OVERRIDE_RUNS, self::distinctColumn($structureBonusTotalPoints, 1));
        $this->assertSame(self::ME_LEVELS, self::distinctColumn($structureBonusTotalPoints, 2));
        $this->assertSame(self::STRUCTURE_BONUS_TOTAL_PERCENTS, self::distinctColumn($structureBonusTotalPoints, 3));

        $this->assertCount(192, $reactionPoints);
        $this->assertSame(self::BASE_QUANTITIES_PER_RUN, self::distinctColumn($reactionPoints, 0));
        $this->assertSame(self::RUNS, self::distinctColumn($reactionPoints, 1));
        $this->assertSame([0.0, 2.64, 4.2, 5.04], self::distinctColumn($reactionPoints, 2));

        $this->assertCount(42, $minimumPoints);
        $this->assertSame(self::MINIMUM_CASE_BASE_QUANTITIES_PER_RUN, self::distinctColumn($minimumPoints, 0));
        $this->assertSame([10], self::distinctColumn($minimumPoints, 2));
        $this->assertSame([2.0], self::distinctColumn($minimumPoints, 3));
        $this->assertSame([5.04], self::distinctColumn($minimumPoints, 5));
    }

    public static function manufacturingJobProvider(): iterable
    {
        foreach (self::BASE_QUANTITIES_PER_RUN as $baseQuantityPerRun) {
            foreach (self::RUNS as $runs) {
                foreach (self::ME_LEVELS as $meLevel) {
                    foreach (self::STRUCTURE_BONUSES as $structureLabel => $structureBonus) {
                        foreach (self::RIGS as $rigLabel => $rigs) {
                            yield \sprintf('base %d, %d runs, ME %d, %s, %s', $baseQuantityPerRun, $runs, $meLevel, $structureLabel, $rigLabel)
                                => self::manufacturingPoint($baseQuantityPerRun, $runs, $meLevel, $structureLabel, $rigLabel);
                        }
                    }
                }
            }
        }
    }

    public static function roundingBoundaryProvider(): iterable
    {
        yield 'boundary 49.005: base 5, 10 runs, ME 1' => self::manufacturingPoint(5, 10, 1, 'structure bonus 1 %', 'no rig');
        yield 'boundary 404.005: base 85, 5 runs, ME 3' => self::manufacturingPoint(85, 5, 3, 'structure bonus 2 %', 'no rig');
        yield 'boundary 1138.005: base 121, 10 runs, ME 5' => self::manufacturingPoint(121, 10, 5, 'structure bonus 1 %', 'no rig');
        yield 'boundary 796.005: base 171, 5 runs, ME 5' => self::manufacturingPoint(171, 5, 5, 'structure bonus 2 %', 'no rig');
        yield 'boundary 742.995: base 79, 10 runs, ME 5' => self::manufacturingPoint(79, 10, 5, 'structure bonus 1 %', 'no rig');
        yield 'boundary 4504.995: base 5, 1000 runs, ME 5' => self::manufacturingPoint(5, 1000, 5, 'structure bonus 1 %', 'rig 2.0 % x 2.1');
        yield 'boundary 10914.015: base 125, 100 runs, ME 7' => self::manufacturingPoint(125, 100, 7, 'structure bonus 2 %', 'rig 2.0 % x 2.1');
        yield 'boundary 193.995: base 45, 5 runs, ME 10' => self::manufacturingPoint(45, 5, 10, 'no structure bonus', 'rig 2.0 % x 2.1');
    }

    public static function minimumOneUnitPerRunProvider(): iterable
    {
        foreach (self::MINIMUM_CASE_BASE_QUANTITIES_PER_RUN as $baseQuantityPerRun) {
            foreach (self::RUNS as $runs) {
                yield \sprintf('minimum case: base %d, %d runs', $baseQuantityPerRun, $runs)
                    => self::manufacturingPoint($baseQuantityPerRun, $runs, self::MINIMUM_CASE_ME_LEVEL, self::MINIMUM_CASE_STRUCTURE, self::MINIMUM_CASE_RIG);
            }
        }
    }

    public static function structureBonusTotalProvider(): iterable
    {
        foreach (self::OVERRIDE_BASE_QUANTITIES_PER_RUN as $baseQuantityPerRun) {
            foreach (self::OVERRIDE_RUNS as $runs) {
                foreach (self::ME_LEVELS as $meLevel) {
                    foreach (self::STRUCTURE_BONUS_TOTAL_PERCENTS as $structureBonusTotalPercent) {
                        yield \sprintf('base %d, %d runs, ME %d, structure bonus total %.2F %%', $baseQuantityPerRun, $runs, $meLevel, $structureBonusTotalPercent)
                            => [$baseQuantityPerRun, $runs, $meLevel, $structureBonusTotalPercent];
                    }
                }
            }
        }
    }

    public static function runsProvider(): iterable
    {
        foreach (self::RUNS as $runs) {
            yield \sprintf('%d runs', $runs) => [$runs];
        }
    }

    public static function reactionJobProvider(): iterable
    {
        foreach (self::BASE_QUANTITIES_PER_RUN as $baseQuantityPerRun) {
            foreach (self::RUNS as $runs) {
                foreach (self::RIGS as $rigLabel => $rigs) {
                    yield \sprintf('reaction base %d, %d runs, %s', $baseQuantityPerRun, $runs, $rigLabel)
                        => [$baseQuantityPerRun, $runs, self::legacyRigBonusPercent($rigs), $rigs];
                }
            }
        }
    }

    public static function multipleRigsManufacturingProvider(): iterable
    {
        yield '2 rigs 2.4 % x 2.1, base 7, 10 runs, ME 0' => [7, 10, 0, 'no structure bonus', [[2.4, 2.1], [2.4, 2.1]], 10.08, 63, 64];
        yield '2 rigs 2.4 % x 2.1, base 100, 10 runs, ME 10' => [100, 10, 10, 'structure bonus 1 %', [[2.4, 2.1], [2.4, 2.1]], 10.08, 802, 804];
        yield '2 rigs 2.4 % x 2.1, base 4000, 1000 runs, ME 10' => [4000, 1000, 10, 'structure bonus 1 %', [[2.4, 2.1], [2.4, 2.1]], 10.08, 3204749, 3213802];
        yield '3 rigs 2.4 % x 2.1, base 13, 10 runs, ME 10' => [13, 10, 10, 'structure bonus 1 %', [[2.4, 2.1], [2.4, 2.1], [2.4, 2.1]], 15.12, 99, 100];
        yield '3 rigs 2.4 % x 2.1, base 100, 1000 runs, ME 0' => [100, 1000, 0, 'no structure bonus', [[2.4, 2.1], [2.4, 2.1], [2.4, 2.1]], 15.12, 84880, 85630];
        yield 'rigs 2.0 % and 2.4 % x 2.1, base 100, 10 runs, ME 0' => [100, 10, 0, 'no structure bonus', [[2.0, 2.1], [2.4, 2.1]], 9.24, 908, 910];
        yield 'rigs 2.0 % and 2.4 % x 2.1, base 997, 32 runs, ME 7' => [997, 32, 7, 'structure bonus 1 %', [[2.0, 2.1], [2.4, 2.1]], 9.24, 26660, 26723];
    }

    public static function multipleRigsReactionProvider(): iterable
    {
        yield '2 rigs 2.4 % x 1.1, base 4000, 10 runs' => [4000, 10, [[2.4, 1.1], [2.4, 1.1]], 5.28, 37888, 37916];
        yield '2 rigs 2.4 % x 1.1, base 7, 1000 runs' => [7, 1000, [[2.4, 1.1], [2.4, 1.1]], 5.28, 6631, 6636];
        yield '3 rigs 2.4 % x 1.1, base 997, 32 runs' => [997, 32, [[2.4, 1.1], [2.4, 1.1], [2.4, 1.1]], 7.92, 29378, 29444];
    }

    public static function reactionWithMeProvider(): iterable
    {
        yield 'base 100, 10 runs, rig 2.4 % x 1.1' => [100, 10, [[2.4, 1.1]], 877, 974];
        yield 'base 13, 32 runs, no rig' => [13, 32, [], 375, 416];
        yield 'base 997, 2 runs, rig 2.4 % x 1.1' => [997, 2, [[2.4, 1.1]], 1748, 1942];
        yield 'base 4000, 1000 runs, rig 2.4 % x 1.1' => [4000, 1000, [[2.4, 1.1]], 3504960, 3894400];
    }

    private static function manufacturingPoint(int $baseQuantityPerRun, int $runs, int $meLevel, string $structureLabel, string $rigLabel): array
    {
        $structureBonus = self::STRUCTURE_BONUSES[$structureLabel];
        $rigs = self::RIGS[$rigLabel];

        return [$baseQuantityPerRun, $runs, $meLevel, $structureBonus['percent'], $structureBonus['multiplier'], self::legacyRigBonusPercent($rigs), $rigs];
    }

    private static function legacyRigBonusPercent(array $rigs): float
    {
        $rigBonusPercent = 0.0;
        foreach ($rigs as [$reductionPercent, $securityModifier]) {
            $rigBonusPercent += $reductionPercent * $securityModifier;
        }

        return round($rigBonusPercent, 2);
    }

    private static function coreMaterialModifier(float $structureMultiplier, array $rigs): Multiplier
    {
        $materialModifier = new Multiplier($structureMultiplier);
        foreach ($rigs as [$reductionPercent, $securityModifier]) {
            $rigBonus = new RigBonus($reductionPercent, new RigSecurityModifiers(null, null, $securityModifier));
            $materialModifier = $materialModifier->times($rigBonus->multiplierIn(SecurityClass::NullSec));
        }

        return $materialModifier;
    }

    private static function coreManufacturingQuantity(int $baseQuantityPerRun, int $runs, int $meLevel, Multiplier $materialModifier): int
    {
        return MaterialQuantity::forManufacturingJob(
            new Quantity($baseQuantityPerRun),
            new Runs($runs),
            new MaterialEfficiency($meLevel),
            $materialModifier,
        )->value;
    }

    private static function coreReactionQuantity(int $baseQuantityPerRun, int $runs, array $rigs): int
    {
        return MaterialQuantity::forReactionJob(
            new Quantity($baseQuantityPerRun),
            new Runs($runs),
            self::coreMaterialModifier(1.0, $rigs),
        )->value;
    }

    private static function distinctColumn(array $points, int $column): array
    {
        return array_values(array_unique(array_column($points, $column), \SORT_REGULAR));
    }
}
