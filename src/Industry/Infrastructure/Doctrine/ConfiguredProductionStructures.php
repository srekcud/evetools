<?php

declare(strict_types=1);

namespace App\Industry\Infrastructure\Doctrine;

use App\Constant\StructureRigTargets;
use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\InvType;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureRig;
use App\Industry\Application\StructureType;
use App\Industry\Application\TimeRig;
use App\Industry\Application\UserProductionStructures;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\ScienceRig;
use App\Industry\Domain\SecurityClass;
use App\Industry\Infrastructure\Sde\SdeRigBonusReader;
use App\Industry\Infrastructure\Sde\SdeStructureBonusReader;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\InvTypeRepository;
use Symfony\Contracts\Service\ResetInterface;

final class ConfiguredProductionStructures implements UserProductionStructures, ResetInterface
{
    private const string NPC_STATION = 'station';

    /** @var array<string, int> */
    public const array STRUCTURE_TYPE_IDS = [
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

    /** @var array<string, list<ProductionStructure>> */
    private array $structuresByUserId = [];

    public function __construct(
        private readonly IndustryStructureConfigRepository $structureConfigRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly SdeStructureBonusReader $structureBonusReader,
        private readonly SdeRigBonusReader $rigBonusReader,
    ) {
    }

    public function configuredBy(string $userId): array
    {
        return $this->structuresByUserId[$userId] ??= $this->readStructuresOf($userId);
    }

    public function reset(): void
    {
        $this->structuresByUserId = [];
    }

    /**
     * @return list<ProductionStructure>
     */
    private function readStructuresOf(string $userId): array
    {
        $configurations = array_values(array_filter(
            $this->structureConfigRepository->findBy(['user' => $userId, 'isDeleted' => false]),
            static fn (IndustryStructureConfig $configuration): bool => self::NPC_STATION !== $configuration->getStructureType() && !$configuration->isHullToSpecify(),
        ));
        $rigNames = [];
        foreach ($configurations as $configuration) {
            array_push($rigNames, ...$configuration->getRigs());
        }
        $rigTypes = $this->invTypeRepository->findByNames(array_values(array_unique($rigNames)));

        return array_map(
            fn (IndustryStructureConfig $configuration): ProductionStructure => $this->productionStructureOf($configuration, $rigTypes),
            $configurations,
        );
    }

    /**
     * @param array<string, InvType> $rigTypes
     */
    private function productionStructureOf(IndustryStructureConfig $configuration, array $rigTypes): ProductionStructure
    {
        $structureType = $configuration->getStructureType();
        if (!\array_key_exists($structureType, self::STRUCTURE_TYPE_IDS)) {
            throw new \UnexpectedValueException(\sprintf('Structure %s has the unsupported type "%s".', $configuration->getId()->toRfc4122(), $structureType));
        }
        $structureTypeId = self::STRUCTURE_TYPE_IDS[$structureType];
        $type = \in_array($structureType, self::REFINERIES, true) ? StructureType::Refinery : StructureType::EngineeringComplex;
        $rigNames = $configuration->getRigs();
        $activity = StructureType::Refinery === $type ? ActivityKind::Reaction : ActivityKind::Manufacturing;

        return new ProductionStructure(
            $configuration->getId()->toRfc4122(),
            $type,
            self::securityClassOf($configuration->getSecurityType()),
            $configuration->getSolarSystemId(),
            $configuration->getCreatedAt(),
            $this->structureBonusReader->materialMultiplierOf($structureTypeId) ?? Multiplier::one(),
            $this->rigsOf($rigNames, $rigTypes, $activity),
            $this->structureBonusReader->costMultiplierOf($structureTypeId) ?? Multiplier::one(),
            self::facilityTaxRateOf($configuration),
            $this->structureBonusReader->timeMultiplierOf($structureTypeId, ActivityKind::Copying) ?? Multiplier::one(),
            $this->scienceRigsOf($rigNames, $rigTypes),
            $this->structureBonusReader->timeMultiplierOf($structureTypeId, ActivityKind::Reaction) ?? Multiplier::one(),
            $this->timeRigsOf($rigNames, $rigTypes, $activity),
            self::unrecognisedRigsOf($rigNames, $rigTypes),
        );
    }

    /**
     * @param array<string> $rigNames
     * @param array<string, InvType> $rigTypes
     *
     * @return list<string>
     */
    private static function unrecognisedRigsOf(array $rigNames, array $rigTypes): array
    {
        return array_values(array_unique(array_filter(
            $rigNames,
            static fn (string $rigName): bool => !\array_key_exists($rigName, $rigTypes),
        )));
    }

    private static function facilityTaxRateOf(IndustryStructureConfig $configuration): ?float
    {
        $facilityTaxPercent = $configuration->getFacilityTaxPercent();

        return null === $facilityTaxPercent ? null : $facilityTaxPercent / self::PERCENT;
    }

    public static function securityClassOf(string $securityType): SecurityClass
    {
        return match ($securityType) {
            'highsec' => SecurityClass::HighSec,
            'lowsec' => SecurityClass::LowSec,
            'nullsec' => SecurityClass::NullSec,
            default => throw new \UnexpectedValueException(\sprintf('Unsupported security "%s".', $securityType)),
        };
    }

    /**
     * @param array<string> $rigNames
     * @param array<string, InvType> $rigTypes
     *
     * @return list<ScienceRig>
     */
    private function scienceRigsOf(array $rigNames, array $rigTypes): array
    {
        $scienceRigs = [];
        foreach ($rigNames as $rigName) {
            if (\array_key_exists($rigName, $rigTypes)) {
                array_push($scienceRigs, ...$this->rigBonusReader->scienceRigsOf($rigTypes[$rigName]->getTypeId()));
            }
        }

        return $scienceRigs;
    }

    /**
     * @param array<string> $rigNames
     * @param array<string, InvType> $rigTypes
     *
     * @return list<TimeRig>
     */
    private function timeRigsOf(array $rigNames, array $rigTypes, ActivityKind $activity): array
    {
        $timeRigs = [];
        foreach ($rigNames as $rigName) {
            if (!\array_key_exists($rigName, $rigTypes)) {
                continue;
            }
            $targetedCategories = StructureRigTargets::categoriesOf($rigName);
            $timeBonus = [] === $targetedCategories ? null : $this->rigBonusReader->timeBonusOf($rigTypes[$rigName]->getTypeId(), $activity);
            if (null !== $timeBonus && $timeBonus->reductionPercent > 0.0) {
                $timeRigs[] = new TimeRig($timeBonus, $targetedCategories);
            }
        }

        return $timeRigs;
    }

    /**
     * @param array<string> $rigNames
     * @param array<string, InvType> $rigTypes
     *
     * @return list<StructureRig>
     */
    private function rigsOf(array $rigNames, array $rigTypes, ActivityKind $activity): array
    {
        $rigs = [];
        foreach ($rigNames as $rigName) {
            if (!\array_key_exists($rigName, $rigTypes)) {
                continue;
            }
            $rigTypeId = $rigTypes[$rigName]->getTypeId();
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
