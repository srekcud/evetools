<?php

declare(strict_types=1);

namespace App\Industry\Infrastructure\Doctrine;

use App\Constant\StructureRigTargets;
use App\Entity\IndustryStructureConfig;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureRig;
use App\Industry\Application\StructureType;
use App\Industry\Application\UserProductionStructures;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\SecurityClass;
use App\Industry\Infrastructure\Sde\SdeRigBonusReader;
use App\Industry\Infrastructure\Sde\SdeStructureBonusReader;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\InvTypeRepository;

final readonly class ConfiguredProductionStructures implements UserProductionStructures
{
    private const string NPC_STATION = 'station';

    /** @var array<string, int> */
    private const array STRUCTURE_TYPE_IDS = [
        'raitaru' => 35825,
        'azbel' => 35826,
        'sotiyo' => 35827,
        'athanor' => 35835,
        'tatara' => 35836,
    ];

    private const array REFINERIES = ['athanor', 'tatara'];

    private const float UNSET_FACILITY_TAX_PERCENT = 0.0;

    private const float PERCENT = 100.0;

    private const string THUKKER_CATEGORY = 'basic_capital_component';

    public function __construct(
        private IndustryStructureConfigRepository $structureConfigRepository,
        private InvTypeRepository $invTypeRepository,
        private SdeStructureBonusReader $structureBonusReader,
        private SdeRigBonusReader $rigBonusReader,
    ) {
    }

    public function configuredBy(string $userId): array
    {
        $configurations = array_filter(
            $this->structureConfigRepository->findBy(['user' => $userId, 'isDeleted' => false]),
            static fn (IndustryStructureConfig $configuration): bool => self::NPC_STATION !== $configuration->getStructureType(),
        );

        return array_values(array_map($this->productionStructureOf(...), $configurations));
    }

    private function productionStructureOf(IndustryStructureConfig $configuration): ProductionStructure
    {
        $structureType = $configuration->getStructureType();
        if (!\array_key_exists($structureType, self::STRUCTURE_TYPE_IDS)) {
            throw new \UnexpectedValueException(\sprintf('Structure %s has the unsupported type "%s".', $configuration->getId()->toRfc4122(), $structureType));
        }
        $structureTypeId = self::STRUCTURE_TYPE_IDS[$structureType];
        $type = \in_array($structureType, self::REFINERIES, true) ? StructureType::Refinery : StructureType::EngineeringComplex;

        return new ProductionStructure(
            $configuration->getId()->toRfc4122(),
            $type,
            self::securityOf($configuration),
            $configuration->getSolarSystemId(),
            $configuration->getCreatedAt(),
            $this->structureBonusReader->materialMultiplierOf($structureTypeId) ?? Multiplier::one(),
            $this->rigsOf($configuration->getRigs(), StructureType::Refinery === $type ? ActivityKind::Reaction : ActivityKind::Manufacturing),
            $this->structureBonusReader->costMultiplierOf($structureTypeId) ?? Multiplier::one(),
            ($configuration->getFacilityTaxRate() ?? self::UNSET_FACILITY_TAX_PERCENT) / self::PERCENT,
        );
    }

    private static function securityOf(IndustryStructureConfig $configuration): SecurityClass
    {
        return match ($configuration->getSecurityType()) {
            'highsec' => SecurityClass::HighSec,
            'lowsec' => SecurityClass::LowSec,
            'nullsec' => SecurityClass::NullSec,
            default => throw new \UnexpectedValueException(\sprintf('Structure %s has the unsupported security "%s".', $configuration->getId()->toRfc4122(), $configuration->getSecurityType())),
        };
    }

    /**
     * @param array<string> $rigNames
     *
     * @return list<StructureRig>
     */
    private function rigsOf(array $rigNames, ActivityKind $activity): array
    {
        $rigTypes = $this->invTypeRepository->findByNames(array_values($rigNames));

        $rigs = [];
        foreach ($rigNames as $rigName) {
            $rigTypeId = ($rigTypes[$rigName] ?? throw new \UnexpectedValueException(\sprintf('Rig "%s" is missing from the SDE.', $rigName)))->getTypeId();
            $targetedCategories = StructureRigTargets::categoriesOf($rigName);

            $thukkerBonus = ActivityKind::Manufacturing === $activity ? $this->rigBonusReader->thukkerMaterialBonusOf($rigTypeId) : null;
            if (null !== $thukkerBonus) {
                $rigs[] = new StructureRig($thukkerBonus, [self::THUKKER_CATEGORY]);
                $targetedCategories = array_values(array_diff($targetedCategories, [self::THUKKER_CATEGORY]));
            }

            $materialBonus = $this->rigBonusReader->materialBonusOf($rigTypeId, $activity);
            if (null !== $materialBonus && [] !== $targetedCategories) {
                $rigs[] = new StructureRig($materialBonus, $targetedCategories);
            }
        }

        return $rigs;
    }
}
