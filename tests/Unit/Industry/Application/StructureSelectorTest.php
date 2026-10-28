<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureChoice;
use App\Industry\Application\StructureChoiceStatus;
use App\Industry\Application\StructureRig;
use App\Industry\Application\StructureSelector;
use App\Industry\Application\StructureType;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\SecurityClass;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
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
#[CoversClass(StructureType::class)]
#[CoversClass(StructureRig::class)]
final class StructureSelectorTest extends TestCase
{
    private const int FAVORITE_MANUFACTURING_SYSTEM_ID = 30002187;
    private const int FAVORITE_REACTION_SYSTEM_ID = 30000142;
    private const int OTHER_SYSTEM_ID = 30004759;
    private const int SECOND_OTHER_SYSTEM_ID = 30002510;

    public function testAssignedStructureIsUsedEvenWhenAConfiguredStructureIsBetter(): void
    {
        $assigned = ProductionStructures::raitaru('assigned', SecurityClass::NullSec, self::OTHER_SYSTEM_ID);
        $selector = new StructureSelector(
            [$assigned, ProductionStructures::raitaru('better', SecurityClass::NullSec, self::OTHER_SYSTEM_ID, '2026-01-01 00:00:00', ProductionStructures::manufacturingRig(2.4, 'ammunition'))],
            FavoriteSystems::none(), InMemorySystemCostIndices::none(),
        );

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition', $assigned);

        $this->assertSame('assigned', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::Assigned, $choice->status);
        $this->assertEqualsWithDelta(0.99, $choice->materialMultiplier->value, 1e-12);
        $this->assertNull($choice->ignoredAssignedStructure);
    }

    public function testBestStructureWithoutAssignedStructureHasNoIgnoredAssignedStructure(): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaru('better', SecurityClass::NullSec, self::OTHER_SYSTEM_ID, '2026-01-01 00:00:00', ProductionStructures::manufacturingRig(2.4, 'ammunition')),
        ], FavoriteSystems::none(), InMemorySystemCostIndices::none());

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition');

        $this->assertSame('better', $choice->structure?->id);
        $this->assertNull($choice->ignoredAssignedStructure);
    }

    public function testRefineryAssignedToManufacturingIsIgnoredForTheBestEngineeringComplexWithAWarning(): void
    {
        $tataraAssignedToManufacturing = ProductionStructures::tatara('tatara-assigned', SecurityClass::NullSec, self::OTHER_SYSTEM_ID, '2026-09-01 00:00:00');
        $selector = new StructureSelector([
            $tataraAssignedToManufacturing,
            ProductionStructures::raitaru('without-rig', SecurityClass::NullSec, self::OTHER_SYSTEM_ID, '2026-06-01 00:00:00'),
            ProductionStructures::raitaru('better', SecurityClass::NullSec, self::OTHER_SYSTEM_ID, '2026-01-01 00:00:00', ProductionStructures::manufacturingRig(2.4, 'ammunition')),
        ], FavoriteSystems::none(), InMemorySystemCostIndices::none());

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition', $tataraAssignedToManufacturing);

        $this->assertSame('better', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestConfigured, $choice->status);
        $this->assertEqualsWithDelta(0.940104, $choice->materialMultiplier->value, 1e-12);
        $this->assertSame($tataraAssignedToManufacturing, $choice->ignoredAssignedStructure);
    }

    public function testEngineeringComplexAssignedToAReactionIsIgnoredForTheBestRefineryOfTheFavoriteSystemWithAWarning(): void
    {
        $raitaruAssignedToAReaction = ProductionStructures::raitaru('raitaru-assigned', SecurityClass::NullSec, self::FAVORITE_REACTION_SYSTEM_ID, '2026-09-01 00:00:00');
        $selector = new StructureSelector([
            $raitaruAssignedToAReaction,
            ProductionStructures::tatara('tatara-elsewhere', SecurityClass::NullSec, self::OTHER_SYSTEM_ID, '2026-06-01 00:00:00', ProductionStructures::reactionRig(2.4, 'composite_reaction')),
            ProductionStructures::tatara('tatara-in-favorite', SecurityClass::LowSec, self::FAVORITE_REACTION_SYSTEM_ID, '2026-01-01 00:00:00', ProductionStructures::reactionRig(2.0, 'composite_reaction')),
        ], new FavoriteSystems(null, self::FAVORITE_REACTION_SYSTEM_ID), InMemorySystemCostIndices::none());

        $choice = $selector->select(ActivityKind::Reaction, 'composite_reaction', $raitaruAssignedToAReaction);

        $this->assertSame('tatara-in-favorite', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestInFavoriteSystem, $choice->status);
        $this->assertEqualsWithDelta(0.98, $choice->materialMultiplier->value, 1e-12);
        $this->assertSame($raitaruAssignedToAReaction, $choice->ignoredAssignedStructure);
    }

    public function testIgnoredAssignedStructureKeepsTheWarningOfABestStructureOutsideTheFavoriteSystem(): void
    {
        $tataraAssignedToManufacturing = ProductionStructures::tatara('tatara-in-favorite', SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-09-01 00:00:00');
        $selector = new StructureSelector([
            $tataraAssignedToManufacturing,
            ProductionStructures::raitaru('raitaru-elsewhere', SecurityClass::LowSec, self::OTHER_SYSTEM_ID, '2026-01-01 00:00:00', ProductionStructures::manufacturingRig(2.0, 'ammunition')),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null), InMemorySystemCostIndices::none());

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition', $tataraAssignedToManufacturing);

        $this->assertSame('raitaru-elsewhere', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestOutsideFavoriteSystem, $choice->status);
        $this->assertEqualsWithDelta(0.95238, $choice->materialMultiplier->value, 1e-12);
        $this->assertSame($tataraAssignedToManufacturing, $choice->ignoredAssignedStructure);
    }

    public function testIgnoredAssignedStructureWithoutAnySuitableStructureGivesNotConfiguredWithTheWarning(): void
    {
        $tataraAssignedToManufacturing = ProductionStructures::tatara('tatara-assigned', SecurityClass::NullSec, self::OTHER_SYSTEM_ID);
        $selector = new StructureSelector([$tataraAssignedToManufacturing], FavoriteSystems::none(), InMemorySystemCostIndices::none());

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition', $tataraAssignedToManufacturing);

        $this->assertNull($choice->structure);
        $this->assertSame(StructureChoiceStatus::NotConfigured, $choice->status);
        $this->assertEqualsWithDelta(1.0, $choice->materialMultiplier->value, 1e-12);
        $this->assertSame($tataraAssignedToManufacturing, $choice->ignoredAssignedStructure);
    }

    public function testBestStructureHasTheLargestMaterialReductionForTheProductCategory(): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaru('without-rig', SecurityClass::NullSec),
            ProductionStructures::raitaru('ammunition-t2-nullsec', SecurityClass::NullSec, null, '2026-01-01 00:00:00', ProductionStructures::manufacturingRig(2.4, 'ammunition')),
            ProductionStructures::raitaru('ammunition-t1-lowsec', SecurityClass::LowSec, null, '2026-01-01 00:00:00', ProductionStructures::manufacturingRig(2.0, 'ammunition')),
        ], FavoriteSystems::none(), InMemorySystemCostIndices::none());

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
        ], FavoriteSystems::none(), InMemorySystemCostIndices::none());

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
        ], FavoriteSystems::none(), InMemorySystemCostIndices::none());

        $choice = $selector->select(ActivityKind::Manufacturing, null);

        $this->assertSame('without-rig', $choice->structure?->id);
        $this->assertEqualsWithDelta(0.99, $choice->materialMultiplier->value, 1e-12);
    }

    public function testReactionUsesTheReactionRigOfARefineryWithoutRoleBonus(): void
    {
        $selector = new StructureSelector([
            ProductionStructures::tatara('tatara-nullsec', SecurityClass::NullSec, null, '2026-01-01 00:00:00', ProductionStructures::reactionRig(2.4, 'composite_reaction')),
        ], FavoriteSystems::none(), InMemorySystemCostIndices::none());

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
        ], FavoriteSystems::none(), InMemorySystemCostIndices::none());

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
        ], FavoriteSystems::none(), InMemorySystemCostIndices::none());

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
        $selector = new StructureSelector([], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, self::FAVORITE_REACTION_SYSTEM_ID), InMemorySystemCostIndices::none());

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition');

        $this->assertNull($choice->structure);
        $this->assertSame(StructureChoiceStatus::NotConfigured, $choice->status);
        $this->assertEqualsWithDelta(1.0, $choice->materialMultiplier->value, 1e-12);
    }

    public function testFavoriteSystemWithoutSuitableStructureFallsBackToTheBestStructureElsewhereWithAWarning(): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaru('elsewhere-with-rig', SecurityClass::NullSec, self::OTHER_SYSTEM_ID, '2026-06-01 00:00:00', ProductionStructures::manufacturingRig(2.4, 'ammunition')),
            ProductionStructures::raitaru('favorite-without-rig', SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-01-01 00:00:00'),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null), InMemorySystemCostIndices::none());

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition');

        $this->assertSame('elsewhere-with-rig', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestOutsideFavoriteSystem, $choice->status);
        $this->assertEqualsWithDelta(0.940104, $choice->materialMultiplier->value, 1e-12);
    }

    public function testBestStructureOfTheFavoriteSystemIsChosen(): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaru('favorite-without-rig', SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-06-01 00:00:00'),
            ProductionStructures::raitaru('favorite-with-rig', SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-01-01 00:00:00', ProductionStructures::manufacturingRig(2.4, 'ammunition')),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null), InMemorySystemCostIndices::none());

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition');

        $this->assertSame('favorite-with-rig', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestInFavoriteSystem, $choice->status);
        $this->assertEqualsWithDelta(0.940104, $choice->materialMultiplier->value, 1e-12);
    }

    public function testFavoriteReactionSystemWithoutSuitableRefineryFallsBackToTheBestRefineryWithAWarning(): void
    {
        $selector = new StructureSelector([
            ProductionStructures::tatara('in-manufacturing-favorite', SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-06-01 00:00:00', ProductionStructures::reactionRig(2.4, 'composite_reaction')),
            ProductionStructures::tatara('in-reaction-favorite', SecurityClass::NullSec, self::FAVORITE_REACTION_SYSTEM_ID, '2026-01-01 00:00:00'),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, self::FAVORITE_REACTION_SYSTEM_ID), InMemorySystemCostIndices::none());

        $choice = $selector->select(ActivityKind::Reaction, 'composite_reaction');

        $this->assertSame('in-manufacturing-favorite', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestOutsideFavoriteSystem, $choice->status);
        $this->assertEqualsWithDelta(0.9736, $choice->materialMultiplier->value, 1e-12);
    }

    public function testReactionKeepsTheSuitableRefineryOfTheFavoriteReactionSystemOverABetterOneInTheFavoriteManufacturingSystem(): void
    {
        $selector = new StructureSelector([
            ProductionStructures::tatara('in-manufacturing-favorite', SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-06-01 00:00:00', ProductionStructures::reactionRig(2.4, 'composite_reaction')),
            ProductionStructures::tatara('in-reaction-favorite', SecurityClass::LowSec, self::FAVORITE_REACTION_SYSTEM_ID, '2026-01-01 00:00:00', ProductionStructures::reactionRig(2.0, 'composite_reaction')),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, self::FAVORITE_REACTION_SYSTEM_ID), InMemorySystemCostIndices::none());

        $choice = $selector->select(ActivityKind::Reaction, 'composite_reaction');

        $this->assertSame('in-reaction-favorite', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestInFavoriteSystem, $choice->status);
        $this->assertEqualsWithDelta(0.98, $choice->materialMultiplier->value, 1e-12);
    }

    #[DataProvider('copyingAndInventionProvider')]
    public function testCopyingAndInventionPreferTheFavoriteManufacturingSystemWhenInstallRatesDifferByLessThanTheTolerance(ActivityKind $activity): void
    {
        $selector = new StructureSelector([
            ProductionStructures::sotiyoConfiguredWithFacilityTax('elsewhere-september', self::OTHER_SYSTEM_ID, '2026-09-01 00:00:00', 0.0209999995),
            ProductionStructures::raitaruConfiguredWithFacilityTax('in-manufacturing-favorite-january', self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-01-01 00:00:00', 0.02),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, self::OTHER_SYSTEM_ID), self::installCostIndices([
            self::FAVORITE_MANUFACTURING_SYSTEM_ID => 0.05,
            self::OTHER_SYSTEM_ID => 0.05,
        ]));

        $choice = $selector->select($activity, null);

        $this->assertSame('in-manufacturing-favorite-january', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestInFavoriteSystem, $choice->status);
        $this->assertEqualsWithDelta(1.0, $choice->materialMultiplier->value, 1e-12);
    }

    public function testFavoriteReactionSystemWithOnlyAnEngineeringComplexFallsBackToTheBestRefineryWithAWarning(): void
    {
        // #92: today the favorite system is filtered on the system alone, so the Raitaru would be kept for a reaction.
        $selector = new StructureSelector([
            ProductionStructures::raitaru('raitaru-in-favorite', SecurityClass::NullSec, self::FAVORITE_REACTION_SYSTEM_ID, '2026-06-01 00:00:00'),
            ProductionStructures::tatara('tatara-elsewhere', SecurityClass::NullSec, self::OTHER_SYSTEM_ID, '2026-01-01 00:00:00', ProductionStructures::reactionRig(2.4, 'composite_reaction')),
        ], new FavoriteSystems(null, self::FAVORITE_REACTION_SYSTEM_ID), InMemorySystemCostIndices::none());

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
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null), InMemorySystemCostIndices::none());

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
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null), InMemorySystemCostIndices::none());

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
        ], FavoriteSystems::none(), InMemorySystemCostIndices::none());

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition');

        $this->assertSame('configured-in-june', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestConfigured, $choice->status);
    }

    /*
     * The two cases below list the best structure first: a comparator ignoring one of the criteria would keep the
     * second one.
     */
    public function testEqualBonusPicksTheMostRecentlyConfiguredStructureListedFirst(): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaru('configured-in-june', SecurityClass::NullSec, null, '2026-06-01 00:00:00'),
            ProductionStructures::raitaru('configured-in-january', SecurityClass::NullSec, null, '2026-01-01 00:00:00'),
        ], FavoriteSystems::none(), InMemorySystemCostIndices::none());

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition');

        $this->assertSame('configured-in-june', $choice->structure?->id);
        $this->assertEqualsWithDelta(0.99, $choice->materialMultiplier->value, 1e-12);
    }

    public function testLargerReductionWinsOverAMoreRecentStructureListedSecond(): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaru('january-with-rig', SecurityClass::NullSec, null, '2026-01-01 00:00:00', ProductionStructures::manufacturingRig(2.4, 'ammunition')),
            ProductionStructures::raitaru('june-without-rig', SecurityClass::NullSec, null, '2026-06-01 00:00:00'),
        ], FavoriteSystems::none(), InMemorySystemCostIndices::none());

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition');

        $this->assertSame('january-with-rig', $choice->structure?->id);
        $this->assertEqualsWithDelta(0.940104, $choice->materialMultiplier->value, 1e-12);
    }

    public function testEqualBonusWithoutSuitableStructurePrefersTheMostRecentStructureOfTheFavoriteSystemWithAWarning(): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaru('favorite-january', SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-01-01 00:00:00'),
            ProductionStructures::raitaru('elsewhere-september', SecurityClass::NullSec, self::OTHER_SYSTEM_ID, '2026-09-01 00:00:00'),
            ProductionStructures::raitaru('favorite-june', SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-06-01 00:00:00'),
            ProductionStructures::raitaru('favorite-march', SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-03-01 00:00:00'),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null), InMemorySystemCostIndices::none());

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition');

        $this->assertSame('favorite-june', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestOutsideFavoriteSystem, $choice->status);
        $this->assertEqualsWithDelta(0.99, $choice->materialMultiplier->value, 1e-12);
    }

    public function testSuitableStructureOfTheFavoriteSystemWinsOverABetterStructureElsewhere(): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaru('elsewhere-t2-nullsec', SecurityClass::NullSec, self::OTHER_SYSTEM_ID, '2026-06-01 00:00:00', ProductionStructures::manufacturingRig(2.4, 'ammunition')),
            ProductionStructures::raitaru('favorite-t1-lowsec', SecurityClass::LowSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-01-01 00:00:00', ProductionStructures::manufacturingRig(2.0, 'ammunition')),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null), InMemorySystemCostIndices::none());

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition');

        $this->assertSame('favorite-t1-lowsec', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestInFavoriteSystem, $choice->status);
        $this->assertEqualsWithDelta(0.95238, $choice->materialMultiplier->value, 1e-12);
        $this->assertNull($choice->ignoredAssignedStructure);
    }

    public function testRigOfAnotherCategoryDoesNotMakeTheFavoriteStructureSuitable(): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaru('favorite-capital-ship-rig', SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-06-01 00:00:00', ProductionStructures::manufacturingRig(2.4, 'capital_ship')),
            ProductionStructures::raitaru('elsewhere-ammunition-rig', SecurityClass::NullSec, self::OTHER_SYSTEM_ID, '2026-01-01 00:00:00', ProductionStructures::manufacturingRig(2.4, 'ammunition')),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null), InMemorySystemCostIndices::none());

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition');

        $this->assertSame('elsewhere-ammunition-rig', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestOutsideFavoriteSystem, $choice->status);
        $this->assertEqualsWithDelta(0.940104, $choice->materialMultiplier->value, 1e-12);
    }

    public function testEqualBonusWithoutSuitableStructurePrefersTheFavoriteSystemOverAMoreRecentStructureWithAWarning(): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaru('elsewhere-september', SecurityClass::NullSec, self::OTHER_SYSTEM_ID, '2026-09-01 00:00:00'),
            ProductionStructures::raitaru('favorite-january', SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-01-01 00:00:00'),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null), InMemorySystemCostIndices::none());

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition');

        $this->assertSame('favorite-january', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestOutsideFavoriteSystem, $choice->status);
        $this->assertEqualsWithDelta(0.99, $choice->materialMultiplier->value, 1e-12);
    }

    public function testProductWithoutRigCategoryPrefersTheFavoriteSystemAtEqualBonusWithoutWarning(): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaru('elsewhere-september', SecurityClass::NullSec, self::OTHER_SYSTEM_ID, '2026-09-01 00:00:00'),
            ProductionStructures::raitaru('favorite-january', SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-01-01 00:00:00'),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null), InMemorySystemCostIndices::none());

        $choice = $selector->select(ActivityKind::Manufacturing, null);

        $this->assertSame('favorite-january', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestInFavoriteSystem, $choice->status);
        $this->assertEqualsWithDelta(0.99, $choice->materialMultiplier->value, 1e-12);
    }

    public function testProductWithoutRigCategoryOutsideTheFavoriteSystemGetsTheMostRecentStructureWithoutWarning(): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaru('elsewhere-january', SecurityClass::NullSec, self::OTHER_SYSTEM_ID, '2026-01-01 00:00:00'),
            ProductionStructures::raitaru('elsewhere-september', SecurityClass::NullSec, self::OTHER_SYSTEM_ID, '2026-09-01 00:00:00'),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null), InMemorySystemCostIndices::none());

        $choice = $selector->select(ActivityKind::Manufacturing, null);

        $this->assertSame('elsewhere-september', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestConfigured, $choice->status);
        $this->assertEqualsWithDelta(0.99, $choice->materialMultiplier->value, 1e-12);
    }

    public function testIgnoredAssignedStructureAndFavoriteSystemWithoutSuitableStructureGiveBothWarnings(): void
    {
        $tataraAssignedToManufacturing = ProductionStructures::tatara('tatara-assigned', SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-09-01 00:00:00');
        $selector = new StructureSelector([
            $tataraAssignedToManufacturing,
            ProductionStructures::raitaru('favorite-without-rig', SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-06-01 00:00:00'),
            ProductionStructures::raitaru('elsewhere-t1-lowsec', SecurityClass::LowSec, self::OTHER_SYSTEM_ID, '2026-01-01 00:00:00', ProductionStructures::manufacturingRig(2.0, 'ammunition')),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null), InMemorySystemCostIndices::none());

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition', $tataraAssignedToManufacturing);

        $this->assertSame('elsewhere-t1-lowsec', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestOutsideFavoriteSystem, $choice->status);
        $this->assertEqualsWithDelta(0.95238, $choice->materialMultiplier->value, 1e-12);
        $this->assertSame($tataraAssignedToManufacturing, $choice->ignoredAssignedStructure);
    }

    public function testEqualBonusAndConfigurationDatePicksTheSmallestIdWhateverTheReadingOrder(): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaru('bbb', SecurityClass::NullSec, self::OTHER_SYSTEM_ID, '2026-06-01 00:00:00'),
            ProductionStructures::raitaru('aaa', SecurityClass::NullSec, self::OTHER_SYSTEM_ID, '2026-06-01 00:00:00'),
        ], FavoriteSystems::none(), InMemorySystemCostIndices::none());

        $choice = $selector->select(ActivityKind::Manufacturing, 'ammunition');

        $this->assertSame('aaa', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestConfigured, $choice->status);
    }

    #[DataProvider('copyingAndInventionProvider')]
    public function testCopyingAndInventionPickTheLowestInstallRateOverTheMostRecentStructure(ActivityKind $activity): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaruConfiguredWithFacilityTax('raitaru-tax-10-september', self::OTHER_SYSTEM_ID, '2026-09-01 00:00:00', 0.10),
            ProductionStructures::sotiyoConfiguredWithFacilityTax('sotiyo-tax-0-january', self::OTHER_SYSTEM_ID, '2026-01-01 00:00:00', 0.0),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null), self::installCostIndices([self::OTHER_SYSTEM_ID => 0.05]));

        $choice = $selector->select($activity, null);

        $this->assertSame('sotiyo-tax-0-january', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestConfigured, $choice->status);
        $this->assertEqualsWithDelta(1.0, $choice->materialMultiplier->value, 1e-12);
    }

    #[DataProvider('copyingAndInventionProvider')]
    public function testCostRoleBonusDecidesBetweenStructuresOfEqualFacilityTax(ActivityKind $activity): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaruConfiguredWithFacilityTax('raitaru-tax-5-june', self::OTHER_SYSTEM_ID, '2026-06-01 00:00:00', 0.05),
            ProductionStructures::sotiyoConfiguredWithFacilityTax('sotiyo-tax-5-january', self::OTHER_SYSTEM_ID, '2026-01-01 00:00:00', 0.05),
        ], FavoriteSystems::none(), self::installCostIndices([self::OTHER_SYSTEM_ID => 0.05]));

        $choice = $selector->select($activity, null);

        $this->assertSame('sotiyo-tax-5-january', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestConfigured, $choice->status);
    }

    #[DataProvider('copyingAndInventionProvider')]
    public function testCopyingAndInventionReadTheCostIndexOfTheActivityInTheSystemOfEachStructure(ActivityKind $activity): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaruConfiguredWithFacilityTax('first-system-tax-0-september', self::OTHER_SYSTEM_ID, '2026-09-01 00:00:00', 0.0),
            ProductionStructures::raitaruConfiguredWithFacilityTax('second-system-tax-5-january', self::SECOND_OTHER_SYSTEM_ID, '2026-01-01 00:00:00', 0.05),
        ], FavoriteSystems::none(), new InMemorySystemCostIndices([
            self::OTHER_SYSTEM_ID => [
                ActivityKind::Manufacturing->name => 0.01,
                ActivityKind::Copying->name => 0.10,
                ActivityKind::Invention->name => 0.12,
            ],
            self::SECOND_OTHER_SYSTEM_ID => [
                ActivityKind::Manufacturing->name => 0.09,
                ActivityKind::Copying->name => 0.02,
                ActivityKind::Invention->name => 0.03,
            ],
        ]));

        $choice = $selector->select($activity, null);

        $this->assertSame('second-system-tax-5-january', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestConfigured, $choice->status);
    }

    #[DataProvider('copyingAndInventionProvider')]
    public function testCopyingAndInventionAreNotFilteredByTheFavoriteManufacturingSystem(ActivityKind $activity): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaruConfiguredWithFacilityTax('favorite-raitaru-tax-10-september', self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-09-01 00:00:00', 0.10),
            ProductionStructures::sotiyoConfiguredWithFacilityTax('elsewhere-sotiyo-tax-0-january', self::OTHER_SYSTEM_ID, '2026-01-01 00:00:00', 0.0),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null), self::installCostIndices([
            self::FAVORITE_MANUFACTURING_SYSTEM_ID => 0.04,
            self::OTHER_SYSTEM_ID => 0.06,
        ]));

        $choice = $selector->select($activity, null);

        $this->assertSame('elsewhere-sotiyo-tax-0-january', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestConfigured, $choice->status);
    }

    #[DataProvider('copyingAndInventionProvider')]
    public function testInstallRateLowerByMoreThanTheToleranceWinsOverTheFavoriteManufacturingSystem(ActivityKind $activity): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaruConfiguredWithFacilityTax('favorite-january', self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-01-01 00:00:00', 0.02),
            ProductionStructures::sotiyoConfiguredWithFacilityTax('elsewhere-september', self::OTHER_SYSTEM_ID, '2026-09-01 00:00:00', 0.020999998),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null), self::installCostIndices([
            self::FAVORITE_MANUFACTURING_SYSTEM_ID => 0.05,
            self::OTHER_SYSTEM_ID => 0.05,
        ]));

        $choice = $selector->select($activity, null);

        $this->assertSame('elsewhere-september', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestConfigured, $choice->status);
    }

    #[DataProvider('installRateGapAroundTheToleranceProvider')]
    public function testFavoriteManufacturingSystemIsPreferredOnlyWhenItsInstallRateGapIsStrictlyBelowTheTolerance(
        ActivityKind $activity,
        float $favoriteFacilityTaxRate,
        string $expectedStructureId,
        StructureChoiceStatus $expectedStatus,
    ): void {
        $selector = new StructureSelector([
            ProductionStructures::raitaruConfiguredWithFacilityTax('favorite-january', self::FAVORITE_MANUFACTURING_SYSTEM_ID, '2026-01-01 00:00:00', $favoriteFacilityTaxRate),
            ProductionStructures::sotiyoConfiguredWithFacilityTax('untaxed-elsewhere-september', self::OTHER_SYSTEM_ID, '2026-09-01 00:00:00', 0.0),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, self::FAVORITE_REACTION_SYSTEM_ID), self::installCostIndices([
            self::FAVORITE_MANUFACTURING_SYSTEM_ID => 0.0,
            self::OTHER_SYSTEM_ID => 0.0,
        ]));

        $choice = $selector->select($activity, null);

        $this->assertSame($expectedStructureId, $choice->structure?->id);
        $this->assertSame($expectedStatus, $choice->status);
    }

    public static function installRateGapAroundTheToleranceProvider(): iterable
    {
        foreach (self::copyingAndInventionProvider() as $activityName => [$activity]) {
            yield $activityName.', gap just below the tolerance' => [$activity, 9.999999999999999E-10, 'favorite-january', StructureChoiceStatus::BestInFavoriteSystem];
            yield $activityName.', gap exactly the tolerance' => [$activity, 1.0E-9, 'untaxed-elsewhere-september', StructureChoiceStatus::BestConfigured];
            yield $activityName.', gap just above the tolerance' => [$activity, 1.0000000000000003E-9, 'untaxed-elsewhere-september', StructureChoiceStatus::BestConfigured];
        }
    }

    #[DataProvider('copyingAndInventionProvider')]
    public function testEqualInstallRateOutsideTheFavoriteSystemPicksTheMostRecentStructureWithoutWarning(ActivityKind $activity): void
    {
        $selector = new StructureSelector([
            ProductionStructures::sotiyoConfiguredWithFacilityTax('sotiyo-january', self::OTHER_SYSTEM_ID, '2026-01-01 00:00:00', 0.0209999995),
            ProductionStructures::raitaruConfiguredWithFacilityTax('raitaru-september', self::OTHER_SYSTEM_ID, '2026-09-01 00:00:00', 0.02),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null), self::installCostIndices([self::OTHER_SYSTEM_ID => 0.05]));

        $choice = $selector->select($activity, null);

        $this->assertSame('raitaru-september', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestConfigured, $choice->status);
    }

    #[DataProvider('copyingAndInventionProvider')]
    public function testEqualInstallRateAndConfigurationDatePicksTheSmallestIdWhateverTheReadingOrder(ActivityKind $activity): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaruConfiguredWithFacilityTax('bbb', self::OTHER_SYSTEM_ID, '2026-06-01 00:00:00', 0.02),
            ProductionStructures::raitaruConfiguredWithFacilityTax('aaa', self::OTHER_SYSTEM_ID, '2026-06-01 00:00:00', 0.02),
        ], FavoriteSystems::none(), self::installCostIndices([self::OTHER_SYSTEM_ID => 0.05]));

        $choice = $selector->select($activity, null);

        $this->assertSame('aaa', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestConfigured, $choice->status);
    }

    #[DataProvider('copyingAndInventionProvider')]
    public function testStructureWithoutKnownSystemTakesTheCostIndexOfTheFavoriteManufacturingSystem(ActivityKind $activity): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaruConfiguredWithFacilityTax('system-unknown-january', null, '2026-01-01 00:00:00', 0.0),
            ProductionStructures::sotiyoConfiguredWithFacilityTax('sotiyo-september', self::OTHER_SYSTEM_ID, '2026-09-01 00:00:00', 0.0),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null), self::installCostIndices([
            self::FAVORITE_MANUFACTURING_SYSTEM_ID => 0.01,
            self::OTHER_SYSTEM_ID => 0.05,
        ]));

        $choice = $selector->select($activity, null);

        $this->assertSame('system-unknown-january', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestConfigured, $choice->status);
    }

    #[DataProvider('copyingAndInventionProvider')]
    public function testUnknownCostIndexRanksAfterEveryKnownInstallRate(ActivityKind $activity): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaruConfiguredWithFacilityTax('without-cost-index-september', self::SECOND_OTHER_SYSTEM_ID, '2026-09-01 00:00:00', 0.0),
            ProductionStructures::raitaruConfiguredWithFacilityTax('tax-10-january', self::OTHER_SYSTEM_ID, '2026-01-01 00:00:00', 0.10),
        ], FavoriteSystems::none(), self::installCostIndices([self::OTHER_SYSTEM_ID => 0.05]));

        $choice = $selector->select($activity, null);

        $this->assertSame('tax-10-january', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestConfigured, $choice->status);
    }

    #[DataProvider('copyingAndInventionProvider')]
    public function testStructureWithoutKnownSystemAndNoFavoriteSystemRanksAfterEveryKnownInstallRate(ActivityKind $activity): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaruConfiguredWithFacilityTax('system-unknown-september', null, '2026-09-01 00:00:00', 0.0),
            ProductionStructures::raitaruConfiguredWithFacilityTax('tax-10-january', self::OTHER_SYSTEM_ID, '2026-01-01 00:00:00', 0.10),
        ], FavoriteSystems::none(), self::installCostIndices([self::OTHER_SYSTEM_ID => 0.05]));

        $choice = $selector->select($activity, null);

        $this->assertSame('tax-10-january', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestConfigured, $choice->status);
    }

    #[DataProvider('copyingAndInventionProvider')]
    public function testAllCostIndicesUnknownFallBackToTheMostRecentStructureThenTheSmallestId(ActivityKind $activity): void
    {
        $selector = new StructureSelector([
            ProductionStructures::raitaruConfiguredWithFacilityTax('bbb', self::OTHER_SYSTEM_ID, '2026-09-01 00:00:00', 0.0),
            ProductionStructures::sotiyoConfiguredWithFacilityTax('ccc', self::SECOND_OTHER_SYSTEM_ID, '2026-01-01 00:00:00', 0.0),
            ProductionStructures::raitaruConfiguredWithFacilityTax('aaa', self::SECOND_OTHER_SYSTEM_ID, '2026-09-01 00:00:00', 0.05),
        ], new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null), InMemorySystemCostIndices::none());

        $choice = $selector->select($activity, null);

        $this->assertSame('aaa', $choice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestConfigured, $choice->status);
    }

    public static function copyingAndInventionProvider(): iterable
    {
        yield 'copying' => [ActivityKind::Copying];
        yield 'invention' => [ActivityKind::Invention];
    }

    private static function installCostIndices(array $costIndexBySolarSystemId): InMemorySystemCostIndices
    {
        return new InMemorySystemCostIndices(array_map(
            static fn (float $costIndex): array => [ActivityKind::Copying->name => $costIndex, ActivityKind::Invention->name => $costIndex],
            $costIndexBySolarSystemId,
        ));
    }
}
