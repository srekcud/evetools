<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\Sde\InvType;
use App\Repository\Sde\DgmTypeAttributeRepository;
use App\Repository\Sde\InvTypeRepository;
use Symfony\Contracts\Service\ResetInterface;

class PlanetaryPowerCalculator implements ResetInterface
{
    private const int COMMAND_CENTER_GROUP_ID = 1027;
    private const int EXTRACTOR_CONTROL_UNIT_GROUP_ID = 1063;

    private const int POWER_OUTPUT_ATTRIBUTE_ID = 11;
    private const int POWER_LOAD_ATTRIBUTE_ID = 15;
    private const int CPU_OUTPUT_ATTRIBUTE_ID = 48;
    private const int CPU_LOAD_ATTRIBUTE_ID = 49;
    private const int EXTRACTOR_HEAD_CPU_ATTRIBUTE_ID = 1690;
    private const int EXTRACTOR_HEAD_POWER_ATTRIBUTE_ID = 1691;

    private const array LEVEL_PREFIXES = [
        1 => 'Limited',
        2 => 'Standard',
        3 => 'Improved',
        4 => 'Advanced',
        5 => 'Elite',
    ];

    /** @var array<int, InvType|null> */
    private array $typesById = [];

    /** @var array<string, InvType|null> */
    private array $typesByName = [];

    /** @var array<string, array<int, int|float>> */
    private array $attributeValuesByRead = [];

    public function __construct(
        private readonly DgmTypeAttributeRepository $typeAttributeRepository,
        private readonly InvTypeRepository $invTypeRepository,
    ) {
    }

    public function reset(): void
    {
        $this->typesById = [];
        $this->typesByName = [];
        $this->attributeValuesByRead = [];
    }

    /**
     * @return array{cpu: int, powergrid: int}|null
     */
    public function outputs(PlanetaryColony $colony): ?array
    {
        $this->loadPinTypes($colony);

        foreach ($colony->getPins() as $pin) {
            $pinType = $this->typesById[$pin->getTypeId()] ?? null;
            if ($pinType !== null && $pinType->getGroup()->getGroupId() === self::COMMAND_CENTER_GROUP_ID) {
                return $this->outputsOfLevel($pinType, $colony->getUpgradeLevel());
            }
        }

        return null;
    }

    /**
     * @return array{cpu: int, powergrid: int}|null
     */
    public function load(PlanetaryColony $colony): ?array
    {
        $this->loadPinTypes($colony);

        $cpu = 0;
        $powergrid = 0;
        foreach ($colony->getPins() as $pin) {
            $pinType = $this->typesById[$pin->getTypeId()] ?? null;
            if ($pinType === null) {
                return null;
            }
            if ($pinType->getGroup()->getGroupId() === self::COMMAND_CENTER_GROUP_ID) {
                continue;
            }

            $pinLoad = $this->loadOfPin($pin, $pinType);
            if ($pinLoad === null) {
                return null;
            }
            $cpu += $pinLoad['cpu'];
            $powergrid += $pinLoad['powergrid'];
        }

        return ['cpu' => $cpu, 'powergrid' => $powergrid];
    }

    /**
     * @return array{cpu: int, powergrid: int}|null
     */
    public function loadOfInstallation(int $typeId, ?int $extractorHeads): ?array
    {
        return null;
    }

    /**
     * @return array{cpu: int, powergrid: int}|null
     */
    private function outputsOfLevel(InvType $baseCommandCenter, int $upgradeLevel): ?array
    {
        if ($upgradeLevel === 0) {
            $levelType = $baseCommandCenter;
        } elseif (isset(self::LEVEL_PREFIXES[$upgradeLevel])) {
            $levelType = $this->typeNamed(self::LEVEL_PREFIXES[$upgradeLevel].' '.$baseCommandCenter->getTypeName());
        } else {
            return null;
        }

        if ($levelType === null) {
            return null;
        }

        $values = $this->attributeValues($levelType->getTypeId(), [self::CPU_OUTPUT_ATTRIBUTE_ID, self::POWER_OUTPUT_ATTRIBUTE_ID]);
        if (!isset($values[self::CPU_OUTPUT_ATTRIBUTE_ID], $values[self::POWER_OUTPUT_ATTRIBUTE_ID])) {
            return null;
        }

        return [
            'cpu' => (int) round($values[self::CPU_OUTPUT_ATTRIBUTE_ID]),
            'powergrid' => (int) round($values[self::POWER_OUTPUT_ATTRIBUTE_ID]),
        ];
    }

    /**
     * @return array{cpu: int, powergrid: int}|null
     */
    private function loadOfPin(PlanetaryPin $pin, InvType $pinType): ?array
    {
        $values = $this->attributeValues($pinType->getTypeId(), [self::CPU_LOAD_ATTRIBUTE_ID, self::POWER_LOAD_ATTRIBUTE_ID]);
        if (!isset($values[self::CPU_LOAD_ATTRIBUTE_ID], $values[self::POWER_LOAD_ATTRIBUTE_ID])) {
            return null;
        }

        $cpu = $values[self::CPU_LOAD_ATTRIBUTE_ID];
        $powergrid = $values[self::POWER_LOAD_ATTRIBUTE_ID];

        if ($pinType->getGroup()->getGroupId() === self::EXTRACTOR_CONTROL_UNIT_GROUP_ID) {
            $heads = $pin->getExtractorNumHeads();
            $headValues = $this->attributeValues($pinType->getTypeId(), [self::EXTRACTOR_HEAD_CPU_ATTRIBUTE_ID, self::EXTRACTOR_HEAD_POWER_ATTRIBUTE_ID]);
            if ($heads === null || !isset($headValues[self::EXTRACTOR_HEAD_CPU_ATTRIBUTE_ID], $headValues[self::EXTRACTOR_HEAD_POWER_ATTRIBUTE_ID])) {
                return null;
            }
            $cpu += $heads * $headValues[self::EXTRACTOR_HEAD_CPU_ATTRIBUTE_ID];
            $powergrid += $heads * $headValues[self::EXTRACTOR_HEAD_POWER_ATTRIBUTE_ID];
        }

        return ['cpu' => (int) round($cpu), 'powergrid' => (int) round($powergrid)];
    }

    private function loadPinTypes(PlanetaryColony $colony): void
    {
        $missingTypeIds = [];
        foreach ($colony->getPins() as $pin) {
            if (!\array_key_exists($pin->getTypeId(), $this->typesById)) {
                $missingTypeIds[$pin->getTypeId()] = $pin->getTypeId();
            }
        }
        if ($missingTypeIds === []) {
            return;
        }

        $found = $this->invTypeRepository->findByTypeIds(array_values($missingTypeIds));
        foreach ($missingTypeIds as $typeId) {
            $this->typesById[$typeId] = $found[$typeId] ?? null;
        }
    }

    private function typeNamed(string $name): ?InvType
    {
        if (!\array_key_exists($name, $this->typesByName)) {
            $this->typesByName[$name] = $this->invTypeRepository->findByNames([$name])[$name] ?? null;
        }

        return $this->typesByName[$name];
    }

    /**
     * @param list<int> $attributeIds
     *
     * @return array<int, int|float> value by attribute id; an attribute the type does not carry or leaves empty is absent
     */
    private function attributeValues(int $typeId, array $attributeIds): array
    {
        $readKey = $typeId.':'.implode(',', $attributeIds);
        if (isset($this->attributeValuesByRead[$readKey])) {
            return $this->attributeValuesByRead[$readKey];
        }

        $values = [];
        foreach ($this->typeAttributeRepository->findBy(['typeId' => $typeId, 'attributeId' => $attributeIds]) as $typeAttribute) {
            $value = $typeAttribute->getValue();
            if ($value !== null) {
                $values[$typeAttribute->getAttributeId()] = $value;
            }
        }

        return $this->attributeValuesByRead[$readKey] = $values;
    }
}
