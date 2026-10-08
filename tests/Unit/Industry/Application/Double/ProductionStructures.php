<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application\Double;

use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureRig;
use App\Industry\Application\StructureType;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\RigBonus;
use App\Industry\Domain\RigSecurityModifiers;
use App\Industry\Domain\SecurityClass;

/**
 * Production structures as the Application receives them: plain inputs, values of the SDE dogma attributes
 * (SDE of 2026-10-07, spec R4), rig → category targeting as in IndustryRigCategory. No entity.
 */
final class ProductionStructures
{
    /** strEngMatBonus of the Engineering Complexes (Raitaru / Azbel / Sotiyo). */
    public const float ENGINEERING_COMPLEX_MATERIAL_ROLE_BONUS = 0.99;

    public static function raitaru(
        string $id,
        SecurityClass $security = SecurityClass::NullSec,
        ?int $solarSystemId = null,
        string $configuredAt = '2026-01-01 00:00:00',
        StructureRig ...$rigs,
    ): ProductionStructure {
        return new ProductionStructure(
            $id,
            StructureType::EngineeringComplex,
            $security,
            $solarSystemId,
            new \DateTimeImmutable($configuredAt),
            new Multiplier(self::ENGINEERING_COMPLEX_MATERIAL_ROLE_BONUS),
            array_values($rigs),
        );
    }

    /** Refineries (Athanor / Tatara) have no material role bonus (spec R2, R4). */
    public static function tatara(
        string $id,
        SecurityClass $security = SecurityClass::NullSec,
        ?int $solarSystemId = null,
        string $configuredAt = '2026-01-01 00:00:00',
        StructureRig ...$rigs,
    ): ProductionStructure {
        return new ProductionStructure(
            $id,
            StructureType::Refinery,
            $security,
            $solarSystemId,
            new \DateTimeImmutable($configuredAt),
            Multiplier::one(),
            array_values($rigs),
        );
    }

    /** Manufacturing material rig: ×1.0 highsec / ×1.9 lowsec / ×2.1 nullsec. */
    public static function manufacturingRig(float $reductionPercent, string ...$targetedCategories): StructureRig
    {
        return new StructureRig(
            new RigBonus($reductionPercent, new RigSecurityModifiers(1.0, 1.9, 2.1)),
            array_values($targetedCategories),
        );
    }

    /** Reaction material rig: forbidden in highsec, ×1.0 lowsec / ×1.1 nullsec (#72). */
    public static function reactionRig(float $reductionPercent, string ...$targetedCategories): StructureRig
    {
        return new StructureRig(
            new RigBonus($reductionPercent, new RigSecurityModifiers(null, 1.0, 1.1)),
            array_values($targetedCategories),
        );
    }
}
