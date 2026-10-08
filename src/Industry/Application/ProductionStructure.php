<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\SecurityClass;

/**
 * A production structure configured by the user. The solar system is only known for imported structures.
 * The cost role bonus (strEngCostBonus) applies to the cost index term of the install cost only (spec R6); the facility
 * tax rate is a fraction set by the owner.
 */
final readonly class ProductionStructure
{
    /**
     * @param list<StructureRig> $rigs
     */
    public function __construct(
        public string $id,
        public StructureType $type,
        public SecurityClass $security,
        public ?int $solarSystemId,
        public \DateTimeImmutable $configuredAt,
        public Multiplier $manufacturingMaterialRoleBonus,
        public array $rigs,
        public Multiplier $costRoleBonus,
        public float $facilityTaxRate,
    ) {
    }

    public function canRun(ActivityKind $activity): bool
    {
        return $this->type->canRun($activity);
    }

    /**
     * mod_matériaux = mod_structure × Π mod_rig of the rigs targeting the category of the job product (spec R2, R4).
     * The role bonus only applies to manufacturing; a product without category only gets the role bonus.
     */
    public function materialMultiplierFor(ActivityKind $activity, ?string $productCategory): Multiplier
    {
        $multiplier = ActivityKind::Manufacturing === $activity ? $this->manufacturingMaterialRoleBonus : Multiplier::one();
        if (null === $productCategory) {
            return $multiplier;
        }

        foreach ($this->rigs as $rig) {
            if ($rig->targets($productCategory)) {
                $multiplier = $multiplier->times($rig->materialBonus->multiplierIn($this->security));
            }
        }

        return $multiplier;
    }
}
