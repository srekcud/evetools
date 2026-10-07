<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\MaterialQuantity;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Runs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec R1 (materials of one job) and R2 (reactions), numbered examples of §4.1 and §4.6.
 *
 * qty(job, material) = max(runs, ceil(round(runs × base × (1 − ME/100) × material modifier, 2)))
 */
#[CoversClass(MaterialQuantity::class)]
final class MaterialQuantityTest extends TestCase
{
    #[DataProvider('manufacturingJobProvider')]
    public function testManufacturingJobQuantityIsRoundedUpOncePerJob(
        int $baseQuantityPerRun,
        int $runs,
        int $meLevel,
        float $materialModifier,
        int $expectedQuantity,
    ): void {
        $quantity = MaterialQuantity::forManufacturingJob(
            new Quantity($baseQuantityPerRun),
            new Runs($runs),
            new MaterialEfficiency($meLevel),
            new Multiplier($materialModifier),
        );

        $this->assertSame($expectedQuantity, $quantity->value);
    }

    /**
     * @return iterable<string, array{int, int, int, float, int}>
     */
    public static function manufacturingJobProvider(): iterable
    {
        // §4.1 Nitrogen Fuel Block, 25 runs, ME 10, NPC station.
        yield '4.1 Nitrogen Isotopes: 25 × 450 × 0.9 = 10 125' => [450, 25, 10, 1.0, 10125];
        yield '4.1 Coolant: 25 × 9 × 0.9 = 202.5, rounded up per job to 203' => [9, 25, 10, 1.0, 203];
        yield '4.1 Robotics: 25 × 1 × 0.9 = 22.5 -> 23, but at least one unit per run = 25' => [1, 25, 10, 1.0, 25];

        // §4.6 R1 + R13: the same blueprint as one job or split, each job recomputed with R1.
        yield '4.6 base 7, ME 10, 32 runs in one job: ceil(201.6) = 202' => [7, 32, 10, 1.0, 202];
        yield '4.6 base 7, ME 10, split job of 11 runs: ceil(69.3) = 70' => [7, 11, 10, 1.0, 70];
        yield '4.6 base 7, ME 10, split job of 10 runs: 63' => [7, 10, 10, 1.0, 63];

        // §4.6 floating noise: runs × base × mods = n + 1e-7 must give n, not n + 1.
        yield '4.6 floating noise 10.0000001 gives 10' => [10, 1, 0, 1.00000001, 10];

        yield 'ME 0 without bonus keeps the base quantity × runs' => [450, 25, 0, 1.0, 11250];
    }

    public function testStructureAndRigBonusesMultiplyInTheMaterialModifier(): void
    {
        // §4.2 Hail L root job: 10 runs, ME 2, Raitaru ×0.99 × T2 rig 2.4 % × 2.1 nullsec (×0.9496).
        // 10 × 3 000 × 0.98 × 0.99 × 0.9496 = 27 639.06 -> 27 640 Fernite Carbide.
        $materialModifier = (new Multiplier(0.99))->times(new Multiplier(0.9496));

        $quantity = MaterialQuantity::forManufacturingJob(
            new Quantity(3000),
            new Runs(10),
            new MaterialEfficiency(2),
            $materialModifier,
        );

        $this->assertSame(27640, $quantity->value);
    }

    #[DataProvider('reactionJobProvider')]
    public function testReactionJobQuantityIgnoresMaterialEfficiency(
        int $baseQuantityPerRun,
        int $runs,
        float $reactionRigModifier,
        int $expectedQuantity,
    ): void {
        // R2: a reaction job has no ME; its signature does not accept one.
        $quantity = MaterialQuantity::forReactionJob(
            new Quantity($baseQuantityPerRun),
            new Runs($runs),
            new Multiplier($reactionRigModifier),
        );

        $this->assertSame($expectedQuantity, $quantity->value);
    }

    /**
     * @return iterable<string, array{int, int, float, int}>
     */
    public static function reactionJobProvider(): iterable
    {
        // §4.3 Fernite Carbide, 10 runs, Tatara nullsec, T2 rig 2.4 % × 1.1 = ×0.9736.
        yield '4.3 Ceramic Powder: 10 × 100 × 0.9736 = 973.6 -> 974' => [100, 10, 0.9736, 974];
        yield '4.3 Hydrogen Fuel Block: 10 × 5 × 0.9736 = 48.68 -> 49' => [5, 10, 0.9736, 49];
        yield 'reaction without rig keeps base × runs' => [100, 10, 1.0, 1000];
        yield 'reaction keeps at least one unit per run' => [1, 10, 0.5, 10];
    }
}
