<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\Decryptor;
use App\Industry\Domain\InventionOutcome;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\Runs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec R8 (benchmark F8): the T2 BPC obtained by a successful invention.
 *
 * runs = base runs + decryptor runs modifier ; ME = 2 + decryptor ME modifier ; TE = 4 + decryptor TE modifier
 *
 * Base runs from the SDE invention product quantity: Hobgoblin II Blueprint 10, Wolf Blueprint 1.
 * Decryptor modifiers from the dev SDE dogma attributes (inventionPropabilityMultiplier, inventionMaxRunModifier,
 * inventionMEModifier, inventionTEModifier), identical to InventionService::DECRYPTORS after #70.
 */
#[CoversClass(InventionOutcome::class)]
#[CoversClass(Decryptor::class)]
final class InventionOutcomeTest extends TestCase
{
    private const int HOBGOBLIN_II_BASE_RUNS = 10;
    private const int WOLF_BASE_RUNS = 1;

    public function testWithoutDecryptorTheT2BlueprintCopyHasTheBaseRunsMe2AndTe4(): void
    {
        $outcome = InventionOutcome::of(new Runs(self::HOBGOBLIN_II_BASE_RUNS), null);

        $this->assertSame(10, $outcome->runs->value);
        $this->assertSame(2, $outcome->materialEfficiency->value);
        $this->assertSame(4, $outcome->timeEfficiency->value);
    }

    #[DataProvider('decryptorProvider')]
    public function testDecryptorShiftsTheRunsMeAndTeOfTheT2BlueprintCopy(
        Decryptor $decryptor,
        int $expectedRuns,
        int $expectedMaterialEfficiency,
        int $expectedTimeEfficiency,
    ): void {
        $outcome = InventionOutcome::of(new Runs(self::HOBGOBLIN_II_BASE_RUNS), $decryptor);

        $this->assertSame($expectedRuns, $outcome->runs->value, 'runs');
        $this->assertSame($expectedMaterialEfficiency, $outcome->materialEfficiency->value, 'ME');
        $this->assertSame($expectedTimeEfficiency, $outcome->timeEfficiency->value, 'TE');
    }

    /**
     * Hobgoblin II Blueprint, 10 base runs.
     *
     * @return iterable<string, array{Decryptor, int, int, int}>
     */
    public static function decryptorProvider(): iterable
    {
        yield 'Accelerant (34201): +1 run, ME +2, TE +10' => [new Decryptor(34201, new Multiplier(1.2), 1, 2, 10), 11, 4, 14];
        yield 'Attainment (34202): +4 runs, ME −1, TE +4' => [new Decryptor(34202, new Multiplier(1.8), 4, -1, 4), 14, 1, 8];
        yield 'Augmentation (34203): +9 runs, ME −2, TE +2' => [new Decryptor(34203, new Multiplier(0.6), 9, -2, 2), 19, 0, 6];
        yield 'Parity (34204): +3 runs, ME +1, TE −2' => [new Decryptor(34204, new Multiplier(1.5), 3, 1, -2), 13, 3, 2];
        yield 'Process (34205): +0 run, ME +3, TE +6' => [new Decryptor(34205, new Multiplier(1.1), 0, 3, 6), 10, 5, 10];
        yield 'Symmetry (34206): +2 runs, ME +1, TE +8' => [new Decryptor(34206, new Multiplier(1.0), 2, 1, 8), 12, 3, 12];
        yield 'Optimized Attainment (34207): +2 runs, ME +1, TE −2' => [new Decryptor(34207, new Multiplier(1.9), 2, 1, -2), 12, 3, 2];
        yield 'Optimized Augmentation (34208): +7 runs, ME +2, TE 0' => [new Decryptor(34208, new Multiplier(0.9), 7, 2, 0), 17, 4, 4];
    }

    public function testDecryptorRunsModifierAppliesToAShipBlueprintWithOneBaseRun(): void
    {
        // Wolf Blueprint: 1 base run + Augmentation 9 = 10 runs, ME 0, TE 6.
        $augmentation = new Decryptor(34203, new Multiplier(0.6), 9, -2, 2);

        $outcome = InventionOutcome::of(new Runs(self::WOLF_BASE_RUNS), $augmentation);

        $this->assertSame(10, $outcome->runs->value);
        $this->assertSame(0, $outcome->materialEfficiency->value);
        $this->assertSame(6, $outcome->timeEfficiency->value);
    }

    public function testDecryptorKeepsItsSdeIdentity(): void
    {
        $parity = new Decryptor(34204, new Multiplier(1.5), 3, 1, -2);

        $this->assertSame(34204, $parity->typeId);
        $this->assertSame(1.5, $parity->probabilityMultiplier->value);
        $this->assertSame(3, $parity->runsModifier);
        $this->assertSame(1, $parity->materialEfficiencyModifier);
        $this->assertSame(-2, $parity->timeEfficiencyModifier);
    }
}
