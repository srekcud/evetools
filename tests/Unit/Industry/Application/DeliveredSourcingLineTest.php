<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\DeliveredSourcingLine;
use App\Industry\Application\DeliveredSourcingOption;
use App\Industry\Application\SourcingChoice;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Quantity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeliveredSourcingLine::class)]
final class DeliveredSourcingLineTest extends TestCase
{
    private const int ARMOR_PLATE = 11540;

    public function testThePerUnitCostOfAnOptionIsItsTotalOverTheUniverseDemand(): void
    {
        $line = $this->line(
            [$this->option(SourcingChoice::Jita, 3000.0)],
            quantity: 3,
            demand: 8,
        );

        self::assertSame(375.0, $line->perUnitOf(SourcingChoice::Jita)?->amount()->amount);
    }

    public function testThereIsNoPerUnitCostForAnOptionTheLineDoesNotHave(): void
    {
        $line = $this->line([$this->option(SourcingChoice::Jita, 3000.0)], quantity: 3, demand: 8);

        self::assertNull($line->perUnitOf(SourcingChoice::Structure));
        self::assertNull($line->totalOf(SourcingChoice::Structure));
    }

    public function testTheTotalOfAnOptionIsItsPerUnitCostTimesTheQuantityOfTheLine(): void
    {
        $line = $this->line([$this->option(SourcingChoice::Jita, 3000.0)], quantity: 3, demand: 8);

        self::assertSame(1125.0, $line->totalOf(SourcingChoice::Jita)?->amount()->amount);
    }

    public function testTheChosenTotalIsTheTotalOfTheChosenOption(): void
    {
        $line = $this->line(
            [$this->option(SourcingChoice::Build, 2000.0), $this->option(SourcingChoice::Jita, 3000.0)],
            chosen: SourcingChoice::Jita,
            quantity: 3,
            demand: 8,
        );

        self::assertSame(1125.0, $line->chosenTotal()?->amount()->amount);
    }

    public function testThereIsNoChosenTotalWithoutAChosenOption(): void
    {
        $line = $this->line([$this->option(SourcingChoice::Jita, 3000.0)], chosen: null, quantity: 3, demand: 8);

        self::assertNull($line->chosenTotal());
    }

    public function testTheReusedPerUnitCostSplitsTheBuildTotalOverEverythingTheBuildProduces(): void
    {
        $line = $this->line([$this->option(SourcingChoice::Build, 3000.0)], quantity: 3, demand: 100, produced: 125);

        self::assertSame(24.0, $line->perUnitIfSurplusReused()?->amount()->amount);
    }

    public function testThereIsNoReusedPerUnitCostWhenTheBuildProducesExactlyTheDemand(): void
    {
        $line = $this->line([$this->option(SourcingChoice::Build, 3000.0)], quantity: 3, demand: 100, produced: 100);

        self::assertNull($line->perUnitIfSurplusReused());
    }

    public function testThereIsNoReusedPerUnitCostWhenTheBuildProducesLessThanTheDemand(): void
    {
        $line = $this->line([$this->option(SourcingChoice::Build, 3000.0)], quantity: 3, demand: 100, produced: 80);

        self::assertNull($line->perUnitIfSurplusReused());
    }

    public function testThereIsNoReusedPerUnitCostWhenTheProducedQuantityIsUnknown(): void
    {
        $line = $this->line([$this->option(SourcingChoice::Build, 3000.0)], quantity: 3, demand: 100, produced: null);

        self::assertNull($line->perUnitIfSurplusReused());
    }

    public function testThereIsNoReusedPerUnitCostWithoutABuildOption(): void
    {
        $line = $this->line([$this->option(SourcingChoice::Jita, 3000.0)], quantity: 3, demand: 100, produced: 125);

        self::assertNull($line->perUnitIfSurplusReused());
    }

    public function testThereIsNoSavingWhenTheBuildProducesExactlyTheDemand(): void
    {
        $line = $this->line(
            [$this->option(SourcingChoice::Build, 800.0), $this->option(SourcingChoice::Jita, 1000.0)],
            recommended: SourcingChoice::Jita,
            quantity: 3,
            demand: 100,
            produced: 100,
        );

        self::assertNull($line->savingIfSurplusReused());
    }

    public function testThereIsNoSavingWhenTheProducedQuantityIsUnknown(): void
    {
        $line = $this->line(
            [$this->option(SourcingChoice::Build, 800.0), $this->option(SourcingChoice::Jita, 1000.0)],
            recommended: SourcingChoice::Jita,
            quantity: 3,
            demand: 100,
            produced: null,
        );

        self::assertNull($line->savingIfSurplusReused());
    }

    public function testThereIsNoSavingWithoutABuildOption(): void
    {
        $line = $this->line(
            [$this->option(SourcingChoice::Jita, 1000.0)],
            recommended: SourcingChoice::Jita,
            quantity: 3,
            demand: 100,
            produced: 125,
        );

        self::assertNull($line->savingIfSurplusReused());
    }

    public function testThereIsNoSavingWhenTheRecommendedOptionIsToBuild(): void
    {
        $line = $this->line(
            [$this->option(SourcingChoice::Build, 800.0), $this->option(SourcingChoice::Jita, 1000.0)],
            recommended: SourcingChoice::Build,
            quantity: 3,
            demand: 100,
            produced: 125,
        );

        self::assertNull($line->savingIfSurplusReused());
    }

    public function testThereIsNoSavingWhenThePurchaseCostIsUnknown(): void
    {
        $line = $this->line(
            [$this->option(SourcingChoice::Build, 800.0), $this->option(SourcingChoice::Jita, null)],
            recommended: SourcingChoice::Jita,
            quantity: 3,
            demand: 100,
            produced: 125,
        );

        self::assertNull($line->savingIfSurplusReused());
    }

    public function testTheChoiceChangesWithoutTheImportWhenTheJitaPriceAloneFavoursBuying(): void
    {
        $line = $this->line([
            $this->option(SourcingChoice::Build, 1000.0),
            $this->option(SourcingChoice::Jita, 1500.0, 900.0),
        ]);

        self::assertTrue($line->choiceChangesWithoutImport());
    }

    public function testTheChoiceChangesWithoutTheImportWhenOnlyTheImportIsUnknown(): void
    {
        $line = $this->line([
            $this->option(SourcingChoice::Build, 1000.0),
            $this->option(SourcingChoice::Jita, null, 900.0),
        ]);

        self::assertTrue($line->choiceChangesWithoutImport());
    }

    public function testTheChoiceStaysTheSameWithoutTheImportWhenBuildingWinsBothWays(): void
    {
        $line = $this->line([
            $this->option(SourcingChoice::Build, 1000.0),
            $this->option(SourcingChoice::Jita, 1500.0, 1200.0),
        ]);

        self::assertFalse($line->choiceChangesWithoutImport());
    }

    public function testTheChoiceStaysTheSameWithoutTheImportWhenBuyingWinsBothWays(): void
    {
        $line = $this->line([
            $this->option(SourcingChoice::Build, 1000.0),
            $this->option(SourcingChoice::Jita, 700.0, 600.0),
        ]);

        self::assertFalse($line->choiceChangesWithoutImport());
    }

    public function testTheChoiceStaysTheSameWithoutTheImportWhenTheBuildCostIsUnknown(): void
    {
        $line = $this->line([
            $this->option(SourcingChoice::Build, null),
            $this->option(SourcingChoice::Jita, 1500.0, 900.0),
        ]);

        self::assertFalse($line->choiceChangesWithoutImport());
    }

    public function testTheChoiceStaysTheSameWithoutTheImportWhenTheLineCannotBeBuilt(): void
    {
        $line = $this->line([$this->option(SourcingChoice::Jita, 1500.0, 900.0)]);

        self::assertFalse($line->choiceChangesWithoutImport());
    }

    public function testTheChoiceStaysTheSameWithoutTheImportWhenTheLineHasNoJitaOption(): void
    {
        $line = $this->line([$this->option(SourcingChoice::Build, 1000.0)]);

        self::assertFalse($line->choiceChangesWithoutImport());
    }

    private function option(SourcingChoice $kind, ?float $total, ?float $price = null): DeliveredSourcingOption
    {
        $zero = Cost::known(new Isk(0.0));

        return new DeliveredSourcingOption(
            $kind,
            null === $total ? Cost::unknown(MissingData::marketPrice(self::ARMOR_PLATE)) : Cost::known(new Isk($total)),
            Cost::known(new Isk($price ?? $total ?? 0.0)),
            $zero,
            $zero,
            null !== $total,
        );
    }

    /**
     * @param list<DeliveredSourcingOption> $options
     */
    private function line(
        array $options,
        ?SourcingChoice $recommended = null,
        ?SourcingChoice $chosen = null,
        int $quantity = 1,
        int $demand = 1,
        ?int $produced = null,
    ): DeliveredSourcingLine {
        $optionsByKind = [];
        foreach ($options as $option) {
            $optionsByKind[$option->kind->value] = $option;
        }

        return new DeliveredSourcingLine(
            self::ARMOR_PLATE,
            null,
            null,
            new Quantity($quantity),
            new Quantity($demand),
            0,
            new Quantity(0),
            [],
            $optionsByKind,
            $recommended,
            $chosen,
            false,
            null === $produced ? null : new Quantity($produced),
            null,
        );
    }
}
