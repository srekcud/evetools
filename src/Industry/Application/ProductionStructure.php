<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\ScienceRig;
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
     * @param list<ScienceRig> $scienceRigs
     * @param list<TimeRig> $timeRigs
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
        public Multiplier $timeRoleBonus = new Multiplier(1.0),
        public array $scienceRigs = [],
        public Multiplier $reactionTimeRoleBonus = new Multiplier(1.0),
        public array $timeRigs = [],
    ) {
    }

    public function timeMultiplierFor(ActivityKind $activity): Multiplier
    {
        if (ActivityKind::Copying !== $activity) {
            throw new \InvalidArgumentException(\sprintf('The structure time multiplier is not modelled for %s.', $activity->name));
        }

        $multiplier = $this->timeRoleBonus;
        foreach ($this->scienceRigs as $scienceRig) {
            if ($scienceRig->activity === $activity && null !== $scienceRig->timeBonus) {
                $multiplier = $multiplier->times($scienceRig->timeBonus->multiplierIn($this->security));
            }
        }

        return $multiplier;
    }

    public function jobTimeMultiplierFor(ActivityKind $activity, ?string $productCategory): Multiplier
    {
        $multiplier = match ($activity) {
            ActivityKind::Manufacturing => $this->timeRoleBonus,
            ActivityKind::Reaction => $this->reactionTimeRoleBonus,
            default => throw new \InvalidArgumentException(\sprintf('The job time multiplier is only modelled for manufacturing and reaction, got %s.', $activity->name)),
        };
        if (null === $productCategory) {
            return $multiplier;
        }

        foreach ($this->timeRigs as $timeRig) {
            if ($timeRig->targets($productCategory)) {
                $multiplier = $multiplier->times($timeRig->timeBonus->multiplierIn($this->security));
            }
        }

        return $multiplier;
    }

    public function installCostMultiplierFor(ActivityKind $activity): Multiplier
    {
        $multiplier = $this->costRoleBonus;
        foreach ($this->scienceRigs as $scienceRig) {
            if ($scienceRig->activity === $activity && null !== $scienceRig->costBonus) {
                $multiplier = $multiplier->times($scienceRig->costBonus->multiplierIn($this->security));
            }
        }

        return $multiplier;
    }

    public function canRun(ActivityKind $activity): bool
    {
        return $this->type->canRun($activity);
    }

    public function isAdaptedTo(string $productCategory): bool
    {
        return array_any($this->rigs, static fn (StructureRig $rig): bool => $rig->targets($productCategory));
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
