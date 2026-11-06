<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureChoice;
use App\Industry\Application\StructureChoiceStatus;
use App\Industry\Application\StructureJobBasis;
use App\Industry\Application\StructureSelectionPolicy;
use App\Industry\Application\StructureSelector;
use App\Industry\Application\StructureTotalCostComparator;
use App\Industry\Application\StructureType;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Isk;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Industry\Domain\SecurityClass;
use App\Tests\Unit\Industry\Application\Double\InMemoryAdjustedPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryItemVolumes;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Application\Double\MarketPricesByQuantity;
use App\Tests\Unit\Industry\Application\Double\StructureTotalCostExample as Example;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StructureSelector::class)]
final class StructureSelectorTotalCostBoundariesTest extends TestCase
{
    private const int BASE_QUANTITY_PER_RUN = 128;
    private const int QUANTITY_AT_FULL_MULTIPLIER = 128;
    private const int QUANTITY_AT_HALF_MULTIPLIER = 64;
    private const int QUANTITY_AT_QUARTER_MULTIPLIER = 32;
    private const float COST_OF_ONE_ISK = 1.0;
    private const float COST_OF_HALF_AN_ISK = 0.5;

    public function testStructureCostingExactlyTheToleranceMoreThanTheCheapestIsStillComparedWithItByPreference(): void
    {
        $choice = self::select(
            [
                self::structure('reference', Example::WORKSHOP_SYSTEM_ID, 1.0, '2026-01-01 00:00:00'),
                self::structure('cheapest', Example::THREE_JUMPS_SYSTEM_ID, 0.25, '2026-01-01 00:00:00'),
                self::structure('within-tolerance-more-recent', Example::SECOND_THREE_JUMPS_SYSTEM_ID, 0.5, '2026-06-01 00:00:00'),
            ],
            new StructureSelectionPolicy(true, new Isk(0.0)),
            [
                self::QUANTITY_AT_FULL_MULTIPLIER => self::COST_OF_ONE_ISK / self::QUANTITY_AT_FULL_MULTIPLIER,
                self::QUANTITY_AT_QUARTER_MULTIPLIER => 0.0,
                self::QUANTITY_AT_HALF_MULTIPLIER => self::tolerance() / self::QUANTITY_AT_HALF_MULTIPLIER,
            ],
        );

        $this->assertSame('within-tolerance-more-recent', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestByTotalCost, $choice->status);
        $this->assertSame(0.5, $choice->materialMultiplier->value);
    }

    public function testSavingEqualToTheToleranceDoesNotLeaveTheReference(): void
    {
        $choice = self::select(
            [
                self::structure('reference', Example::WORKSHOP_SYSTEM_ID, 0.5, '2026-01-01 00:00:00'),
                self::structure('cheaper-more-recent', Example::WORKSHOP_SYSTEM_ID, 1.0, '2026-06-01 00:00:00'),
            ],
            new StructureSelectionPolicy(true, new Isk(0.0)),
            [
                self::QUANTITY_AT_HALF_MULTIPLIER => self::tolerance() / self::QUANTITY_AT_HALF_MULTIPLIER,
                self::QUANTITY_AT_FULL_MULTIPLIER => 0.0,
            ],
        );

        $this->assertSame('reference', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestInFavoriteSystem, $choice->status);
        $this->assertSame(0.5, $choice->materialMultiplier->value);
    }

    public function testSavingWhoseSumWithTheToleranceEqualsTheMinimumSavingIsEnoughToLeaveTheReference(): void
    {
        $choice = self::select(
            [
                self::structure('reference', Example::WORKSHOP_SYSTEM_ID, 1.0, '2026-01-01 00:00:00'),
                self::structure('cheaper', Example::THREE_JUMPS_SYSTEM_ID, 0.5, '2026-01-01 00:00:00'),
            ],
            new StructureSelectionPolicy(true, new Isk(self::COST_OF_HALF_AN_ISK + self::tolerance())),
            [
                self::QUANTITY_AT_FULL_MULTIPLIER => self::COST_OF_HALF_AN_ISK / self::QUANTITY_AT_FULL_MULTIPLIER,
                self::QUANTITY_AT_HALF_MULTIPLIER => 0.0,
            ],
        );

        $this->assertSame('cheaper', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestByTotalCost, $choice->status);
        $this->assertSame(0.5, $choice->materialMultiplier->value);
    }

    public function testFavoriteSystemStructureNotAdaptedToTheProductStaysTheReferenceOverAnAdaptedOneOutside(): void
    {
        $selector = self::selectorWith(
            [Example::workshopStructureWithoutRig(), Example::structureD()],
            new StructureSelectionPolicy(true, new Isk(0.0)),
            Example::comparator(),
        );

        $choice = $selector->select(ActivityKind::Manufacturing, Example::PRODUCT_CATEGORY, null, self::exampleBasis());

        $this->assertSame('A', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestInFavoriteSystem, $choice->status);
        $this->assertSame(1.0, $choice->materialMultiplier->value);
    }

    public function testWithoutStructureInTheFavoriteSystemTheBestMaterialMultiplierOfAllIsTheReference(): void
    {
        $selector = self::selectorWith(
            [Example::structureC()],
            new StructureSelectionPolicy(true, new Isk(0.0)),
            Example::comparator(),
        );

        $choice = $selector->select(ActivityKind::Manufacturing, Example::PRODUCT_CATEGORY, null, self::exampleBasis());

        $this->assertSame('C', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestOutsideFavoriteSystem, $choice->status);
        $this->assertEqualsWithDelta(0.9, $choice->materialMultiplier->value, 1e-12);
    }

    public function testActivePolicyWithoutJobBasisKeepsTheCurrentChoiceWithoutComputingAnyTotalCost(): void
    {
        $jumpDistances = Example::jumpDistances();
        $selector = self::selectorWith(
            [Example::workshopStructureWithNeutralRig(), Example::structureC()],
            new StructureSelectionPolicy(true, new Isk(0.0)),
            Example::comparator(jumpDistances: $jumpDistances),
        );

        $choice = $selector->select(ActivityKind::Manufacturing, Example::PRODUCT_CATEGORY);

        $this->assertSame('A', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestInFavoriteSystem, $choice->status);
        $this->assertSame([], $jumpDistances->requestedRoutes);
    }

    public function testReferenceWithUnknownTotalCostStaysEvenWhenAnotherStructureHasAKnownLowerCost(): void
    {
        $selector = self::selectorWith(
            [Example::workshopStructureWithNeutralRig(), Example::structureC()],
            new StructureSelectionPolicy(true, new Isk(0.0)),
            Example::comparator(costIndices: new InMemorySystemCostIndices([
                Example::THREE_JUMPS_SYSTEM_ID => [ActivityKind::Manufacturing->name => Example::COST_INDEX],
            ])),
        );

        $choice = $selector->select(ActivityKind::Manufacturing, Example::PRODUCT_CATEGORY, null, self::exampleBasis());

        $this->assertSame('A', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestInFavoriteSystem, $choice->status);
    }

    public function testPolicyAcceptingStructuresOutsideTheFavoriteSystemNeedsATotalCostComparator(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('needs a total cost comparator');

        new StructureSelector(
            [Example::workshopStructureWithNeutralRig()],
            new FavoriteSystems(Example::WORKSHOP_SYSTEM_ID, null),
            Example::costIndices(),
            null,
            new StructureSelectionPolicy(true, new Isk(0.0)),
        );
    }

    private static function tolerance(): float
    {
        return (new \ReflectionClassConstant(StructureSelector::class, 'TOTAL_COST_TOLERANCE_ISK'))->getValue();
    }

    private static function structure(string $id, int $solarSystemId, float $materialRoleBonus, string $configuredAt): ProductionStructure
    {
        return new ProductionStructure(
            $id,
            StructureType::EngineeringComplex,
            SecurityClass::HighSec,
            $solarSystemId,
            new \DateTimeImmutable($configuredAt),
            new Multiplier($materialRoleBonus),
            [],
            Multiplier::one(),
            0.0,
        );
    }

    private static function select(array $structures, StructureSelectionPolicy $policy, array $unitPriceByQuantity): StructureChoice
    {
        $comparator = new StructureTotalCostComparator(
            new MarketPricesByQuantity($unitPriceByQuantity),
            new InMemoryAdjustedPrices([Example::MATERIAL_TYPE_ID => 0.0]),
            Example::costIndices(),
            Example::jumpDistances(),
            new InMemoryItemVolumes([Example::MATERIAL_TYPE_ID => 0.0, Example::PRODUCT_TYPE_ID => 0.0]),
        );
        $recipe = new Recipe(
            Example::PRODUCT_TYPE_ID,
            ActivityKind::Manufacturing,
            new OutputPerRun(1),
            [new RecipeMaterial(Example::MATERIAL_TYPE_ID, new Quantity(self::BASE_QUANTITY_PER_RUN))],
            new Runs(300),
            3600,
        );

        return self::selectorWith($structures, $policy, $comparator)
            ->select(ActivityKind::Manufacturing, Example::PRODUCT_CATEGORY, null, new StructureJobBasis($recipe, new Runs(1)));
    }

    private static function selectorWith(array $structures, StructureSelectionPolicy $policy, StructureTotalCostComparator $comparator): StructureSelector
    {
        return new StructureSelector(
            $structures,
            new FavoriteSystems(Example::WORKSHOP_SYSTEM_ID, null),
            Example::costIndices(),
            $comparator,
            $policy,
        );
    }

    private static function exampleBasis(): StructureJobBasis
    {
        return new StructureJobBasis(Example::recipe(), new Runs(Example::REFERENCE_RUNS));
    }
}
