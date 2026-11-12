<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application\Double;

use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureRig;
use App\Industry\Application\StructureTotalCostComparator;
use App\Industry\Application\StructureType;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\RigBonus;
use App\Industry\Domain\RigSecurityModifiers;
use App\Industry\Domain\Runs;
use App\Industry\Domain\SecurityClass;

final class StructureTotalCostExample
{
    public const int PRODUCT_TYPE_ID = 12005;
    public const int MATERIAL_TYPE_ID = 11399;
    public const string PRODUCT_CATEGORY = 'advanced_medium_ship';

    public const int REFERENCE_RUNS = 10;
    public const int MATERIAL_BASE_QUANTITY_PER_RUN = 10000;
    public const float MATERIAL_UNIT_PRICE = 100.0;
    public const float MATERIAL_CUBIC_METERS = 0.01;
    public const float PRODUCT_CUBIC_METERS = 100.0;
    public const float COST_INDEX = 0.04;

    public const int WORKSHOP_SYSTEM_ID = 30002187;
    public const int THREE_JUMPS_SYSTEM_ID = 30004759;
    public const int SECOND_THREE_JUMPS_SYSTEM_ID = 30004760;
    public const int EIGHT_JUMPS_SYSTEM_ID = 30002510;
    public const int UNREACHABLE_SYSTEM_ID = 31000005;

    public const float WORKSHOP_FACILITY_TAX_RATE = 0.01;
    public const float RIGGED_FACILITY_TAX_RATE = 0.005;
    public const float RIG_REDUCTION_PERCENT = 10.0;

    public static function recipe(ActivityKind $activity = ActivityKind::Manufacturing): Recipe
    {
        return new Recipe(
            self::PRODUCT_TYPE_ID,
            $activity,
            new OutputPerRun(1),
            [new RecipeMaterial(self::MATERIAL_TYPE_ID, new Quantity(self::MATERIAL_BASE_QUANTITY_PER_RUN))],
            new Runs(300),
            3600,
        );
    }

    public static function workshopStructureWithoutRig(): ProductionStructure
    {
        return self::engineeringComplex('A', self::WORKSHOP_SYSTEM_ID, '2026-01-01 00:00:00', self::WORKSHOP_FACILITY_TAX_RATE);
    }

    public static function workshopStructureWithNeutralRig(): ProductionStructure
    {
        return self::engineeringComplex('A', self::WORKSHOP_SYSTEM_ID, '2026-01-01 00:00:00', self::WORKSHOP_FACILITY_TAX_RATE, self::manufacturingRig(0.0));
    }

    public static function riggedStructure(string $id, ?int $solarSystemId, string $configuredAt = '2026-01-01 00:00:00'): ProductionStructure
    {
        return self::engineeringComplex($id, $solarSystemId, $configuredAt, self::RIGGED_FACILITY_TAX_RATE, self::manufacturingRig(self::RIG_REDUCTION_PERCENT));
    }

    public static function unriggedStructure(string $id, int $solarSystemId, ?float $facilityTaxRate): ProductionStructure
    {
        return self::engineeringComplex($id, $solarSystemId, '2026-01-01 00:00:00', $facilityTaxRate);
    }

    public static function structureC(): ProductionStructure
    {
        return self::riggedStructure('C', self::THREE_JUMPS_SYSTEM_ID);
    }

    public static function structureD(): ProductionStructure
    {
        return self::riggedStructure('D', self::EIGHT_JUMPS_SYSTEM_ID);
    }

    public static function workshopRefinery(): ProductionStructure
    {
        return self::refinery('A-refinery', self::WORKSHOP_SYSTEM_ID, self::WORKSHOP_FACILITY_TAX_RATE, self::reactionRig(0.0));
    }

    public static function riggedRefinery(): ProductionStructure
    {
        return self::refinery('C-refinery', self::THREE_JUMPS_SYSTEM_ID, self::RIGGED_FACILITY_TAX_RATE, self::reactionRig(self::RIG_REDUCTION_PERCENT));
    }

    public static function marketPrices(): InMemoryMarketPrices
    {
        return new InMemoryMarketPrices([self::MATERIAL_TYPE_ID => self::MATERIAL_UNIT_PRICE]);
    }

    public static function adjustedPrices(): InMemoryAdjustedPrices
    {
        return new InMemoryAdjustedPrices([self::MATERIAL_TYPE_ID => self::MATERIAL_UNIT_PRICE]);
    }

    public static function costIndices(): InMemorySystemCostIndices
    {
        $indexOfEveryActivity = [];
        foreach (ActivityKind::cases() as $activity) {
            $indexOfEveryActivity[$activity->name] = self::COST_INDEX;
        }

        return new InMemorySystemCostIndices([
            self::WORKSHOP_SYSTEM_ID => $indexOfEveryActivity,
            self::THREE_JUMPS_SYSTEM_ID => $indexOfEveryActivity,
            self::SECOND_THREE_JUMPS_SYSTEM_ID => $indexOfEveryActivity,
            self::EIGHT_JUMPS_SYSTEM_ID => $indexOfEveryActivity,
            self::UNREACHABLE_SYSTEM_ID => $indexOfEveryActivity,
        ]);
    }

    public static function jumpDistances(): InMemoryJumpDistances
    {
        return new InMemoryJumpDistances([
            self::WORKSHOP_SYSTEM_ID => [
                self::THREE_JUMPS_SYSTEM_ID => 3,
                self::SECOND_THREE_JUMPS_SYSTEM_ID => 3,
                self::EIGHT_JUMPS_SYSTEM_ID => 8,
            ],
        ]);
    }

    public static function itemVolumes(): InMemoryItemVolumes
    {
        return new InMemoryItemVolumes([
            self::MATERIAL_TYPE_ID => self::MATERIAL_CUBIC_METERS,
            self::PRODUCT_TYPE_ID => self::PRODUCT_CUBIC_METERS,
        ]);
    }

    public static function comparator(
        ?InMemoryMarketPrices $marketPrices = null,
        ?InMemorySystemCostIndices $costIndices = null,
        ?InMemoryJumpDistances $jumpDistances = null,
        ?InMemoryItemVolumes $itemVolumes = null,
    ): StructureTotalCostComparator {
        return new StructureTotalCostComparator(
            $marketPrices ?? self::marketPrices(),
            self::adjustedPrices(),
            $costIndices ?? self::costIndices(),
            $jumpDistances ?? self::jumpDistances(),
            $itemVolumes ?? self::itemVolumes(),
        );
    }

    private static function engineeringComplex(string $id, ?int $solarSystemId, string $configuredAt, ?float $facilityTaxRate, StructureRig ...$rigs): ProductionStructure
    {
        return new ProductionStructure(
            $id,
            StructureType::EngineeringComplex,
            SecurityClass::HighSec,
            $solarSystemId,
            new \DateTimeImmutable($configuredAt),
            Multiplier::one(),
            array_values($rigs),
            Multiplier::one(),
            $facilityTaxRate,
        );
    }

    private static function refinery(string $id, int $solarSystemId, ?float $facilityTaxRate, StructureRig $rig): ProductionStructure
    {
        return new ProductionStructure(
            $id,
            StructureType::Refinery,
            SecurityClass::LowSec,
            $solarSystemId,
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            Multiplier::one(),
            [$rig],
            Multiplier::one(),
            $facilityTaxRate,
        );
    }

    private static function manufacturingRig(float $reductionPercent): StructureRig
    {
        return new StructureRig(new RigBonus($reductionPercent, new RigSecurityModifiers(1.0, 1.9, 2.1)), [self::PRODUCT_CATEGORY]);
    }

    private static function reactionRig(float $reductionPercent): StructureRig
    {
        return new StructureRig(new RigBonus($reductionPercent, new RigSecurityModifiers(null, 1.0, 1.1)), [self::PRODUCT_CATEGORY]);
    }
}
