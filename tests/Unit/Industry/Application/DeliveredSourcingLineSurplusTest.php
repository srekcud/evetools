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
use App\Service\Industry\Project\DeliveredSourcingPresenter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeliveredSourcingLine::class)]
#[Group('issue-1340')]
final class DeliveredSourcingLineSurplusTest extends TestCase
{
    private const int TYPE_ID = 11540;

    public function testTheSavingIsScaledByTheShareOfTheRowInTheUniverseDemand(): void
    {
        $line = $this->line(quantity: 50, demand: 100, produced: 125, buildTotal: 1000.0, jitaTotal: 950.0, recommended: SourcingChoice::Jita);

        self::assertEqualsWithDelta(150.0, $line->savingIfSurplusReused()?->amount, 0.001);
        self::assertSame(75.0, DeliveredSourcingPresenter::rowOf($line, null)['savingIfSurplusReused']);
    }

    public function testThereIsNoSavingWhenTheReusedBuildCostEqualsThePurchase(): void
    {
        $line = $this->line(quantity: 100, demand: 100, produced: 125, buildTotal: 1000.0, jitaTotal: 800.0, recommended: SourcingChoice::Jita);

        self::assertNull($line->savingIfSurplusReused());
        self::assertNull(DeliveredSourcingPresenter::rowOf($line, null)['savingIfSurplusReused']);
    }

    public function testTheSavingIsAgainstTheRecommendedOptionEvenWhenItIsTheCapitalMarket(): void
    {
        $line = $this->line(quantity: 100, demand: 100, produced: 120, buildTotal: 1000.0, jitaTotal: 950.0, recommended: SourcingChoice::Structure, structureTotal: 900.0);

        self::assertEqualsWithDelta(66.67, $line->savingIfSurplusReused()?->amount, 0.01);
    }

    public function testThereIsNoSavingWithoutARecommendedOption(): void
    {
        $line = $this->line(quantity: 100, demand: 100, produced: 120, buildTotal: 1000.0, jitaTotal: 950.0, recommended: null);

        self::assertNull($line->savingIfSurplusReused());
    }

    public function testAnUnknownBuildCostGivesNeitherTheReusedPerUnitCostNorTheSaving(): void
    {
        $line = $this->line(quantity: 100, demand: 100, produced: 120, buildTotal: null, jitaTotal: 950.0, recommended: SourcingChoice::Jita);
        $row = DeliveredSourcingPresenter::rowOf($line, null);

        self::assertNull($line->savingIfSurplusReused());
        self::assertNull($row['options']['build']['perUnitIfSurplusReused']);
    }

    public function testATieBetweenBuildingAndTheJitaPriceKeepsTheChoiceUnchangedByTheImport(): void
    {
        $line = $this->line(quantity: 100, demand: 100, produced: 100, buildTotal: 1000.0, jitaTotal: 1100.0, recommended: SourcingChoice::Build, jitaPrice: 1000.0);

        self::assertFalse($line->choiceChangesWithoutImport());
    }

    private function line(
        int $quantity,
        int $demand,
        int $produced,
        ?float $buildTotal,
        float $jitaTotal,
        ?SourcingChoice $recommended,
        ?float $structureTotal = null,
        ?float $jitaPrice = null,
    ): DeliveredSourcingLine {
        $build = new DeliveredSourcingOption(
            SourcingChoice::Build,
            null === $buildTotal ? Cost::unknown(MissingData::marketPrice(self::TYPE_ID)) : Cost::known(new Isk($buildTotal)),
            Cost::known(new Isk(0.0)),
            Cost::known(new Isk(0.0)),
            Cost::known(new Isk(0.0)),
            null !== $buildTotal,
        );
        $jita = new DeliveredSourcingOption(
            SourcingChoice::Jita,
            Cost::known(new Isk($jitaTotal)),
            Cost::known(new Isk($jitaPrice ?? $jitaTotal)),
            Cost::known(new Isk(0.0)),
            Cost::known(new Isk($jitaTotal - ($jitaPrice ?? $jitaTotal))),
            true,
        );
        $options = [SourcingChoice::Build->value => $build, SourcingChoice::Jita->value => $jita];
        if (null !== $structureTotal) {
            $options[SourcingChoice::Structure->value] = new DeliveredSourcingOption(
                SourcingChoice::Structure,
                Cost::known(new Isk($structureTotal)),
                Cost::known(new Isk($structureTotal)),
                Cost::known(new Isk(0.0)),
                Cost::known(new Isk(0.0)),
                true,
                1000,
                '1030000000001',
            );
        }

        return new DeliveredSourcingLine(
            self::TYPE_ID,
            null,
            null,
            new Quantity($quantity),
            new Quantity($demand),
            0,
            new Quantity(0),
            [],
            $options,
            $recommended,
            $recommended,
            false,
            new Quantity($produced),
            null,
        );
    }
}
