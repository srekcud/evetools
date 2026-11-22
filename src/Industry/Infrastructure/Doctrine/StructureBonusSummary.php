<?php

declare(strict_types=1);

namespace App\Industry\Infrastructure\Doctrine;

use App\Constant\StructureRigTargets;
use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\InvType;
use App\Industry\Application\StructureBonuses;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\SecurityClass;
use App\Industry\Infrastructure\Sde\SdeRigBonusReader;
use App\Industry\Infrastructure\Sde\SdeStructureBonusReader;
use App\Repository\Sde\InvTypeRepository;

final readonly class StructureBonusSummary
{
    private const int PERCENT = 100;

    private const int DECIMALS = 2;

    public function __construct(
        private InvTypeRepository $invTypeRepository,
        private SdeStructureBonusReader $structureBonusReader,
        private SdeRigBonusReader $rigBonusReader,
    ) {
    }

    /**
     * @param array<string> $rigNames
     */
    public function of(string $structureType, string $securityType, array $rigNames): StructureBonuses
    {
        $security = ConfiguredProductionStructures::securityClassOf($securityType);
        $structureTypeId = ConfiguredProductionStructures::STRUCTURE_TYPE_IDS[$structureType] ?? null;
        $distinctRigNames = array_values(array_unique($rigNames));
        $rigTypes = $this->invTypeRepository->findByNames($distinctRigNames);
        $recognisedRigNames = array_filter($distinctRigNames, static fn (string $rigName): bool => \array_key_exists($rigName, $rigTypes));
        $hullToSpecify = \array_key_exists($structureType, IndustryStructureConfig::HULLS_OF_LEGACY_TYPE);

        return new StructureBonuses(
            $this->materialBonusOf(ActivityKind::Manufacturing, $security, $recognisedRigNames, $rigTypes),
            $this->materialBonusOf(ActivityKind::Reaction, $security, $recognisedRigNames, $rigTypes),
            $hullToSpecify ? null : $this->timeBonusOf(ActivityKind::Manufacturing, $security, $structureTypeId, $recognisedRigNames, $rigTypes),
            $hullToSpecify ? null : $this->timeBonusOf(ActivityKind::Reaction, $security, $structureTypeId, $recognisedRigNames, $rigTypes),
            array_values(array_diff($distinctRigNames, $recognisedRigNames)),
            $hullToSpecify,
        );
    }

    /**
     * @param array<string> $recognisedRigNames
     * @param array<string, InvType> $rigTypes
     */
    private function materialBonusOf(ActivityKind $activity, SecurityClass $security, array $recognisedRigNames, array $rigTypes): float
    {
        $percent = 0.0;
        foreach ($recognisedRigNames as $rigName) {
            $rigTypeId = $rigTypes[$rigName]->getTypeId();
            $bonus = $this->rigBonusReader->materialBonusOf($rigTypeId, $activity);
            if (null === $bonus && ActivityKind::Manufacturing === $activity) {
                $bonus = $this->rigBonusReader->thukkerMaterialBonusOf($rigTypeId);
            }
            $percent += $bonus?->effectivePercentIn($security) ?? 0.0;
        }

        return round($percent, self::DECIMALS);
    }

    /**
     * @param array<string> $recognisedRigNames
     * @param array<string, InvType> $rigTypes
     */
    private function timeBonusOf(ActivityKind $activity, SecurityClass $security, ?int $structureTypeId, array $recognisedRigNames, array $rigTypes): float
    {
        $multiplier = null === $structureTypeId ? Multiplier::one() : $this->structureBonusReader->timeMultiplierOf($structureTypeId, $activity) ?? Multiplier::one();
        foreach ($recognisedRigNames as $rigName) {
            $bonus = [] === StructureRigTargets::categoriesOf($rigName) ? null : $this->rigBonusReader->timeBonusOf($rigTypes[$rigName]->getTypeId(), $activity);
            if (null !== $bonus && $bonus->reductionPercent > 0.0 && null !== $bonus->effectivePercentIn($security)) {
                $multiplier = $multiplier->times($bonus->multiplierIn($security));
            }
        }

        return round((1 - $multiplier->value) * self::PERCENT, self::DECIMALS);
    }
}
