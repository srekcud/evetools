<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureChoice;
use App\Industry\Application\StructureChoiceStatus;
use App\Industry\Application\StructureSelector;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\SecurityClass;
use App\Tests\Unit\Industry\Application\Double\ProductionStructures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Structure of a job (spec R4, D3, D3b):
 * - the structure assigned to the step;
 * - otherwise the best production structure for (activity, category of the job PRODUCT), "best" being the largest
 *   material reduction, among the structures whose type can run the activity (Engineering Complex for manufacturing,
 *   copying and invention, refinery for reactions);
 * - the favorite system of the activity filters first; equal bonus: the most recently configured structure;
 * - favorite system without a suitable structure: the best of all, with a warning (#92: the type is checked);
 * - no suitable structure: neutral multiplier, "not configured".
 *
 * Material multipliers: Engineering Complex role ×0.99, rigs from RigBonus (verified with the Domain classes):
 * T2 2.4 % nullsec 0.99 × 0.9496 = 0.940104; T1 2.0 % lowsec 0.99 × 0.962 = 0.95238; T1 2.0 % nullsec 0.99 × 0.958 = 0.94842;
 * reaction T2 2.4 % nullsec 0.9736.
 */
#[CoversClass(StructureSelector::class)]
#[CoversClass(StructureChoice::class)]
#[CoversClass(ProductionStructure::class)]
final class StructureSelectorTest extends TestCase
{
    private const int FAVORITE_MANUFACTURING_SYSTEM_ID = 30002187;
    private const int FAVORITE_REACTION_SYSTEM_ID = 30000142;
    private const int OTHER_SYSTEM_ID = 30004759;

    public function testAssignedStructureIsUsedEvenWhenAConfiguredStructureIsBetter(): void
    {
        $assigned = ProductionStructures::raitaru('assigned', SecurityClass::NullSec, self::OTHER_SYSTEM_ID);
        $selector = new StructureSelector(
            [$assigned, ProductionStructures::raitaru('better', SecurityClass::NullSec, self::OTHER_SYSTEM_ID, '2026-01-01 00:00:00', ProductionStructures::manufacturingRig(2.4, 'ammunition'))],
            FavoriteSystems::none(),
        );

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition', $assigned);

        $this->assertSame('assigned', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::Assigned, $choice->status);
        $this->assertEqualsWithDelta(0.99, $choice->materialMultiplier->value, 1e-12);
    }

    public function testBestStructureHasTheLargestMaterialReductionForTheProductCategory(): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaru('without-rig', SecurityClass::NullSec),
            ProductionStructures::raitaru('ammunition-t2-nullsec', SecurityClass::NullSec, null, '2026-01-01 00:00:00', ProductionStructures::manufacturingRig(2.4, 'ammunition')),
            ProductionStructures::raitaru('ammunition-t1-lowsec', SecurityClass::LowSec, null, '2026-01-01 00:00:00', ProductionStructures::manufacturingRig(2.0, 'ammunition')),
        ], FavoriteSystems::none());

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition');

        $this->assertSame('ammunition-t2-nullsec', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestConfigured, $choice->status);
        $this->assertEqualsWithDelta(0.940104, $choice->materialMultiplier->value, 1e-12);
    }

    public function testBonusFollowsTheCategoryOfTheJobProductNotTheCategoryOfItsMaterials(): void
    {
        // #6: a capital ship job consumes capital components, but only the "capital ship" rig applies to it.
        $selector = new StructureSelector([
            ProductionStructures::raitaru('capital-component-rig', SecurityClass::NullSec, null, '2026-01-01 00:00:00', ProductionStructures::manufacturingRig(2.4, 'basic_capital_component')),
            ProductionStructures::raitaru('capital-ship-rig', SecurityClass::NullSec, null, '2026-01-01 00:00:00', ProductionStructures::manufacturingRig(2.0, 'capital_ship')),
        ], FavoriteSystems::none());

        $choice = $selector->select(ActivityKind::Manufacturing, 'capital_ship');

        $this->assertSame('capital-ship-rig', $choice->structure?->id);
        $this->assertEqualsWithDelta(0.94842, $choice->materialMultiplier->value, 1e-12);
    }

    public function testProductWithoutRigCategoryOnlyGetsTheRoleBonus(): void
    {
        // e.g. Deployables: no IndustryRigCategory for their group. Equal bonus, so the most recent structure wins.
        $selector = new StructureSelector([
            ProductionStructures::raitaru('with-ammunition-rig', SecurityClass::NullSec, null, '2026-01-01 00:00:00', ProductionStructures::manufacturingRig(2.4, 'ammunition')),
            ProductionStructures::raitaru('without-rig', SecurityClass::NullSec, null, '2026-06-01 00:00:00'),
        ], FavoriteSystems::none());

        $choice = $selector->select(ActivityKind::Manufacturing, null);

        $this->assertSame('without-rig', $choice->structure?->id);
        $this->assertEqualsWithDelta(0.99, $choice->materialMultiplier->value, 1e-12);
    }

    public function testReactionUsesTheReactionRigOfARefineryWithoutRoleBonus(): void
    {
        $selector = new StructureSelector([
            ProductionStructures::tatara('tatara-nullsec', SecurityClass::NullSec, null, '2026-01-01 00:00:00', ProductionStructures::reactionRig(2.4, 'composite_reaction')),
        ], FavoriteSystems::none());

        $choice = $selector->select(ActivityKind::Reaction, 'composite_reaction');

        $this->assertSame('tatara-nullsec', $choice->structure?->id);
        $this->assertEqualsWithDelta(0.9736, $choice->materialMultiplier->value, 1e-12);
    }

    public function testEngineeringComplexIsNeverChosenForAReactionEvenWithAStrongerReduction(): void
    {
        // The Raitaru role bonus (×0.99) would beat a refinery without matching rig (×1) on the bonus alone.
        $selector = new StructureSelector([
            ProductionStructures::raitaru('raitaru', SecurityClass::NullSec, null, '2026-06-01 00:00:00'),
            ProductionStructures::tatara('tatara-without-matching-rig', SecurityClass::NullSec, null, '2026-01-01 00:00:00', ProductionStructures::reactionRig(2.4, 'biochemical_reaction')),
        ], FavoriteSystems::none());

        $choice = $selector->select(ActivityKind::Reaction, 'composite_reaction');

        $this->assertSame('tatara-without-matching-rig', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestConfigured, $choice->status);
        $this->assertEqualsWithDelta(1.0, $choice->materialMultiplier->value, 1e-12);
    }

    #[DataProvider('engineeringComplexActivityProvider')]
    public function testRefineryIsNeverChosenForAnEngineeringComplexActivity(ActivityKind $activity): void
    {
        $selector = new StructureSelector([
            ProductionStructures::tatara('tatara', SecurityClass::NullSec, null, '2026-01-01 00:00:00', ProductionStructures::reactionRig(2.4, 'composite_reaction')),
        ], FavoriteSystems::none());

        $choice = $selector->select($activity, 'ammunition');

        $this->assertNull($choice->structure);
        $this->assertSame(StructureChoiceStatus::NotConfigured, $choice->status);
        $this->assertEqualsWithDelta(1.0, $choice->materialMultiplier->value, 1e-12);
    }

    /**
     * @return iterable<string, array{ActivityKind}>
     */
    public static function engineeringComplexActivityProvider(): iterable
    {
        yield 'manufacturing' => [ActivityKind::Manufacturing];
        yield 'copying' => [ActivityKind::Copying];
        yield 'invention' => [ActivityKind::Invention];
    }

    public function testNoConfiguredStructureGivesANeutralMultiplierAndTheNotConfiguredStatus(): void
    {
        $selector = new StructureSelector([], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, self::FAVORITE_REACTION_SYSTEM_ID));

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition');

        $this->assertNull($choice->structure);
        $this->assertSame(StructureChoiceStatus::NotConfigured, $choice->status);
        $this->assertEqualsWithDelta(1.0, $choice->materialMultiplier->value, 1e-12);
    }

    public function testFavoriteSystemFiltersBeforeTheBonus(): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaru('elsewhere-with-rig', SecurityClass::NullSec, self::OTHER_SYSTEM_ID, '2026-06-01 00:00:00', ProductionStructures::manufacturingRig(2.4, 'ammunition')),
            ProductionStructures::raitaru('favorite-without-rig', SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-01-01 00:00:00'),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null));

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition');

        $this->assertSame('favorite-without-rig', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestInFavoriteSystem, $choice->status);
        $this->assertEqualsWithDelta(0.99, $choice->materialMultiplier->value, 1e-12);
    }

    public function testBestStructureOfTheFavoriteSystemIsChosen(): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaru('favorite-without-rig', SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-06-01 00:00:00'),
            ProductionStructures::raitaru('favorite-with-rig', SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-01-01 00:00:00', ProductionStructures::manufacturingRig(2.4, 'ammunition')),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null));

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition');

        $this->assertSame('favorite-with-rig', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestInFavoriteSystem, $choice->status);
        $this->assertEqualsWithDelta(0.940104, $choice->materialMultiplier->value, 1e-12);
    }

    public function testReactionFiltersOnTheFavoriteReactionSystemNotTheManufacturingOne(): void
    {
        $selector = new StructureSelector([
            ProductionStructures::tatara('in-manufacturing-favorite', SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-06-01 00:00:00', ProductionStructures::reactionRig(2.4, 'composite_reaction')),
            ProductionStructures::tatara('in-reaction-favorite', SecurityClass::NullSec, self::FAVORITE_REACTION_SYSTEM_ID, '2026-01-01 00:00:00'),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, self::FAVORITE_REACTION_SYSTEM_ID));

        $choice = $selector->select(ActivityKind::Reaction, 'composite_reaction');

        $this->assertSame('in-reaction-favorite', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestInFavoriteSystem, $choice->status);
        $this->assertEqualsWithDelta(1.0, $choice->materialMultiplier->value, 1e-12);
    }

    #[DataProvider('engineeringComplexActivityProvider')]
    public function testEngineeringComplexActivitiesFilterOnTheFavoriteManufacturingSystem(ActivityKind $activity): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaru('elsewhere', SecurityClass::NullSec, self::OTHER_SYSTEM_ID, '2026-06-01 00:00:00'),
            ProductionStructures::raitaru('in-manufacturing-favorite', SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-01-01 00:00:00'),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, self::OTHER_SYSTEM_ID));

        $choice = $selector->select($activity, null);

        $this->assertSame('in-manufacturing-favorite', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestInFavoriteSystem, $choice->status);
    }

    public function testFavoriteReactionSystemWithOnlyAnEngineeringComplexFallsBackToTheBestRefineryWithAWarning(): void
    {
        // #92: today the favorite system is filtered on the system alone, so the Raitaru would be kept for a reaction.
        $selector = new StructureSelector([
            ProductionStructures::raitaru('raitaru-in-favorite', SecurityClass::NullSec, self::FAVORITE_REACTION_SYSTEM_ID, '2026-06-01 00:00:00'),
            ProductionStructures::tatara('tatara-elsewhere', SecurityClass::NullSec, self::OTHER_SYSTEM_ID, '2026-01-01 00:00:00', ProductionStructures::reactionRig(2.4, 'composite_reaction')),
        ], new FavoriteSystems(null, self::FAVORITE_REACTION_SYSTEM_ID));

        $choice = $selector->select(ActivityKind::Reaction, 'composite_reaction');

        $this->assertSame('tatara-elsewhere', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestOutsideFavoriteSystem, $choice->status);
        $this->assertEqualsWithDelta(0.9736, $choice->materialMultiplier->value, 1e-12);
    }

    public function testFavoriteManufacturingSystemWithOnlyARefineryFallsBackToTheBestEngineeringComplexWithAWarning(): void
    {
        $selector = new StructureSelector([
            ProductionStructures::tatara('tatara-in-favorite', SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-06-01 00:00:00'),
            ProductionStructures::raitaru('raitaru-without-rig', SecurityClass::NullSec, self::OTHER_SYSTEM_ID, '2026-03-01 00:00:00'),
            ProductionStructures::raitaru('raitaru-with-rig', SecurityClass::LowSec, self::OTHER_SYSTEM_ID, '2026-01-01 00:00:00', ProductionStructures::manufacturingRig(2.0, 'ammunition')),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null));

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition');

        $this->assertSame('raitaru-with-rig', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestOutsideFavoriteSystem, $choice->status);
        $this->assertEqualsWithDelta(0.95238, $choice->materialMultiplier->value, 1e-12);
    }

    public function testStructureWithUnknownSystemIsNotInTheFavoriteSystem(): void
    {
        // Only imported structures know their system (glossary, "Système favori").
        $selector = new StructureSelector([
            ProductionStructures::raitaru('system-unknown', SecurityClass::NullSec, null, '2026-01-01 00:00:00'),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null));

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition');

        $this->assertSame('system-unknown', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestOutsideFavoriteSystem, $choice->status);
    }

    public function testEqualBonusPicksTheMostRecentlyConfiguredStructure(): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaru('configured-in-january', SecurityClass::NullSec, null, '2026-01-01 00:00:00'),
            ProductionStructures::raitaru('configured-in-june', SecurityClass::NullSec, null, '2026-06-01 00:00:00'),
            ProductionStructures::raitaru('configured-in-march', SecurityClass::NullSec, null, '2026-03-01 00:00:00'),
        ], FavoriteSystems::none());

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition');

        $this->assertSame('configured-in-june', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestConfigured, $choice->status);
    }

    public function testEqualBonusPrefersTheFavoriteSystemOverAMoreRecentStructure(): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaru('favorite-january', SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-01-01 00:00:00'),
            ProductionStructures::raitaru('elsewhere-september', SecurityClass::NullSec, self::OTHER_SYSTEM_ID, '2026-09-01 00:00:00'),
            ProductionStructures::raitaru('favorite-june', SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-06-01 00:00:00'),
            ProductionStructures::raitaru('favorite-march', SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-03-01 00:00:00'),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null));

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition');

        $this->assertSame('favorite-june', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestInFavoriteSystem, $choice->status);
    }
}
