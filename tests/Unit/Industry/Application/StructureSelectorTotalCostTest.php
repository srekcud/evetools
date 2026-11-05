<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\StructureChoice;
use App\Industry\Application\StructureChoiceStatus;
use App\Industry\Application\StructureJobBasis;
use App\Industry\Application\StructureSelectionPolicy;
use App\Industry\Application\StructureSelector;
use App\Industry\Application\StructureTotalCostComparator;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Isk;
use App\Industry\Domain\Runs;
use App\Tests\Unit\Industry\Application\Double\InMemoryItemVolumes;
use App\Tests\Unit\Industry\Application\Double\InMemoryJumpDistances;
use App\Tests\Unit\Industry\Application\Double\InMemoryMarketPrices;
use App\Tests\Unit\Industry\Application\Double\StructureTotalCostExample as Example;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(StructureSelector::class)]
#[CoversClass(StructureSelectionPolicy::class)]
#[CoversClass(StructureJobBasis::class)]
final class StructureSelectorTotalCostTest extends TestCase
{
    private const float MULTIPLIER_TOLERANCE = 1e-12;

    public function testStructureCheaperByMoreThanTheMinimumSavingIsChosenByTotalCost(): void
    {
        $choice = $this->manufacturingChoice(
            [Example::workshopStructureWithNeutralRig(), Example::structureC()],
            self::acceptingOutsideFavoriteSystem(100_000.0),
        );

        $this->assertSame('C', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestByTotalCost, $choice->status);
        $this->assertEqualsWithDelta(0.9, $choice->materialMultiplier->value, self::MULTIPLIER_TOLERANCE);
    }

    public function testSavingBelowTheMinimumSavingKeepsTheStructureOfTheFavoriteSystem(): void
    {
        $choice = $this->manufacturingChoice(
            [Example::workshopStructureWithNeutralRig(), Example::structureC()],
            self::acceptingOutsideFavoriteSystem(500_000.0),
        );

        $this->assertSame('A', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestInFavoriteSystem, $choice->status);
        $this->assertEqualsWithDelta(1.0, $choice->materialMultiplier->value, self::MULTIPLIER_TOLERANCE);
    }

    public function testSavingEqualToTheMinimumSavingIsEnoughToChooseTheCheaperStructure(): void
    {
        $choice = $this->manufacturingChoice(
            [Example::workshopStructureWithNeutralRig(), Example::structureC()],
            self::acceptingOutsideFavoriteSystem(150_000.0),
        );

        $this->assertSame('C', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestByTotalCost, $choice->status);
    }

    public function testStructureMadeMoreExpensiveByItsTransportIsNotChosenEvenWithoutMinimumSaving(): void
    {
        $choice = $this->manufacturingChoice(
            [Example::workshopStructureWithNeutralRig(), Example::structureD()],
            self::acceptingOutsideFavoriteSystem(0.0),
        );

        $this->assertSame('A', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestInFavoriteSystem, $choice->status);
    }

    public function testCheaperStructureOfTheFavoriteSystemIsChosenByTotalCostOverTheAdaptedOne(): void
    {
        $choice = $this->manufacturingChoice(
            [Example::workshopStructureWithNeutralRig(), Example::unriggedStructure('A-low-tax', Example::WORKSHOP_SYSTEM_ID, 0.001)],
            self::acceptingOutsideFavoriteSystem(50_000.0),
        );

        $this->assertSame('A-low-tax', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestByTotalCost, $choice->status);
    }

    public function testEqualTotalCostsAreDecidedByTheMostRecentlyConfiguredStructure(): void
    {
        $choice = $this->manufacturingChoice(
            [
                Example::workshopStructureWithNeutralRig(),
                Example::riggedStructure('C-january', Example::THREE_JUMPS_SYSTEM_ID, '2026-01-01 00:00:00'),
                Example::riggedStructure('C-june', Example::SECOND_THREE_JUMPS_SYSTEM_ID, '2026-06-01 00:00:00'),
            ],
            self::acceptingOutsideFavoriteSystem(100_000.0),
        );

        $this->assertSame('C-june', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestByTotalCost, $choice->status);
    }

    public function testInactivePolicyKeepsTheCurrentChoiceWithoutComputingAnyTotalCost(): void
    {
        $jumpDistances = Example::jumpDistances();
        $itemVolumes = Example::itemVolumes();

        $choice = $this->manufacturingChoice(
            [Example::workshopStructureWithNeutralRig(), Example::structureC()],
            StructureSelectionPolicy::favoriteSystemOnly(),
            Example::comparator(jumpDistances: $jumpDistances, itemVolumes: $itemVolumes),
        );

        $this->assertSame('A', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestInFavoriteSystem, $choice->status);
        $this->assertSame([], $jumpDistances->requestedRoutes);
        $this->assertSame([], $itemVolumes->requestedTypeIds);
    }

    public function testStructureWithoutVolumeForAMaterialIsNotComparableAndTheFavoriteSystemChoiceStays(): void
    {
        $choice = $this->manufacturingChoice(
            [Example::workshopStructureWithNeutralRig(), Example::structureC()],
            self::acceptingOutsideFavoriteSystem(100_000.0),
            Example::comparator(itemVolumes: new InMemoryItemVolumes([Example::PRODUCT_TYPE_ID => Example::PRODUCT_CUBIC_METERS])),
        );

        $this->assertSame('A', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestInFavoriteSystem, $choice->status);
    }

    public function testStructureWithoutRouteFromTheFavoriteSystemIsNotComparable(): void
    {
        $choice = $this->manufacturingChoice(
            [Example::workshopStructureWithNeutralRig(), Example::structureC()],
            self::acceptingOutsideFavoriteSystem(100_000.0),
            Example::comparator(jumpDistances: new InMemoryJumpDistances([])),
        );

        $this->assertSame('A', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestInFavoriteSystem, $choice->status);
    }

    public function testMissingMarketPriceLeavesTheReferenceUnknownAndKeepsItWithoutError(): void
    {
        $choice = $this->manufacturingChoice(
            [Example::workshopStructureWithNeutralRig(), Example::structureC()],
            self::acceptingOutsideFavoriteSystem(100_000.0),
            Example::comparator(marketPrices: new InMemoryMarketPrices([])),
        );

        $this->assertSame('A', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestInFavoriteSystem, $choice->status);
    }

    public function testAssignedStructureIsKeptWithItsStatusUnderAnActivePolicy(): void
    {
        $structureC = Example::structureC();
        $selector = self::selector([Example::workshopStructureWithNeutralRig(), $structureC], self::acceptingOutsideFavoriteSystem(100_000.0));

        $choice = $selector->select(ActivityKind::Manufacturing, Example::PRODUCT_CATEGORY, $structureC, self::basis());

        $this->assertSame('C', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::Assigned, $choice->status);
    }

    public function testWithoutFavoriteSystemTheBestMaterialMultiplierIsKeptEvenWhenItsTransportCostsMore(): void
    {
        $selector = self::selector(
            [Example::workshopStructureWithNeutralRig(), Example::structureD()],
            self::acceptingOutsideFavoriteSystem(0.0),
            favoriteSystems: FavoriteSystems::none(),
        );

        $choice = $selector->select(ActivityKind::Manufacturing, Example::PRODUCT_CATEGORY, null, self::basis());

        $this->assertSame('D', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestConfigured, $choice->status);
    }

    #[DataProvider('copyingAndInventionProvider')]
    public function testCopyingAndInventionKeepTheLowestInstallRateUnderAnActivePolicy(ActivityKind $activity): void
    {
        $jumpDistances = Example::jumpDistances();
        $selector = self::selector(
            [Example::workshopStructureWithNeutralRig(), Example::structureD()],
            self::acceptingOutsideFavoriteSystem(0.0),
            Example::comparator(jumpDistances: $jumpDistances),
        );

        $choice = $selector->select($activity, null, null, new StructureJobBasis(Example::recipe($activity), new Runs(Example::REFERENCE_RUNS)));

        $this->assertSame('D', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestConfigured, $choice->status);
        $this->assertSame([], $jumpDistances->requestedRoutes);
    }

    public static function copyingAndInventionProvider(): iterable
    {
        yield 'copying' => [ActivityKind::Copying];
        yield 'invention' => [ActivityKind::Invention];
    }

    private function manufacturingChoice(array $structures, StructureSelectionPolicy $policy, ?StructureTotalCostComparator $comparator = null): StructureChoice
    {
        return self::selector($structures, $policy, $comparator)
            ->select(ActivityKind::Manufacturing, Example::PRODUCT_CATEGORY, null, self::basis());
    }

    private static function selector(
        array $structures,
        StructureSelectionPolicy $policy,
        ?StructureTotalCostComparator $comparator = null,
        ?FavoriteSystems $favoriteSystems = null,
    ): StructureSelector {
        return new StructureSelector(
            $structures,
            $favoriteSystems ?? new FavoriteSystems(Example::WORKSHOP_SYSTEM_ID, null),
            Example::costIndices(),
            $comparator ?? Example::comparator(),
            $policy,
        );
    }

    private static function basis(): StructureJobBasis
    {
        return new StructureJobBasis(Example::recipe(), new Runs(Example::REFERENCE_RUNS));
    }

    private static function acceptingOutsideFavoriteSystem(float $minimumSaving): StructureSelectionPolicy
    {
        return new StructureSelectionPolicy(true, new Isk($minimumSaving));
    }
}
