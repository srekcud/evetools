<?php

declare(strict_types=1);

namespace App\Service\Planetary\Layout;

use App\Entity\Sde\InvType;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Planetary\PlanetaryPowerCalculator;
use App\Service\Planetary\PlanetaryProductionCalculator;

final readonly class PlanetaryColonyLayoutSummarizer
{
    private const int PROCESSOR_GROUP_ID = 1028;
    private const int STORAGE_GROUP_ID = 1029;
    private const int LAUNCHPAD_GROUP_ID = 1030;
    private const int EXTRACTOR_CONTROL_UNIT_GROUP_ID = 1063;

    private const string COMMAND_CENTER_UPGRADES_SKILL = 'Command Center Upgrades';

    public function __construct(
        private InvTypeRepository $invTypeRepository,
        private PlanetaryPowerCalculator $powerCalculator,
        private PlanetaryProductionCalculator $productionCalculator,
    ) {
    }

    /**
     * @param array<string, mixed> $template
     *
     * @return array<string, mixed>
     */
    public function summarize(array $template): array
    {
        $planetTypeId = $this->integer($template, 'Pln');
        $commandCenterLevel = $this->integer($template, 'CmdCtrLv');
        $pins = array_map($this->readPin(...), $this->list($template, 'P'));
        $types = $this->invTypeRepository->findByTypeIds($this->referencedTypeIds($planetTypeId, $pins));

        $buildings = ['extractorControlUnits' => 0, 'extractorHeads' => 0, 'processors' => 0, 'storages' => 0, 'launchpads' => 0];
        $productTypeIds = [];
        $extractedTypeIds = [];
        $cpu = 0;
        $powergrid = 0;
        $isLoadKnown = true;

        foreach ($pins as $pin) {
            if (!isset($types[$pin['T']])) {
                $isLoadKnown = false;
                continue;
            }
            $groupId = $types[$pin['T']]->getGroup()->getGroupId();
            $isExtractor = $groupId === self::EXTRACTOR_CONTROL_UNIT_GROUP_ID;
            $role = match ($groupId) {
                self::EXTRACTOR_CONTROL_UNIT_GROUP_ID => 'extractorControlUnits',
                self::PROCESSOR_GROUP_ID => 'processors',
                self::STORAGE_GROUP_ID => 'storages',
                self::LAUNCHPAD_GROUP_ID => 'launchpads',
                default => null,
            };
            if ($role === null) {
                continue;
            }

            ++$buildings[$role];
            if ($isExtractor) {
                $buildings['extractorHeads'] += $pin['H'];
            }
            if ($pin['S'] !== null) {
                if ($isExtractor) {
                    $extractedTypeIds[$pin['S']] = $pin['S'];
                } else {
                    $productTypeIds[$pin['S']] = $pin['S'];
                }
            }

            $installationLoad = $this->powerCalculator->loadOfInstallation($pin['T'], $isExtractor ? $pin['H'] : null);
            if ($installationLoad === null) {
                $isLoadKnown = false;
                continue;
            }
            $cpu += $installationLoad['cpu'];
            $powergrid += $installationLoad['powergrid'];
        }

        return [
            'planetTypeId' => $planetTypeId,
            'planetTypeName' => $this->typeName($planetTypeId, $types),
            'commandCenterLevel' => $commandCenterLevel,
            'buildings' => $buildings,
            'links' => \count($this->list($template, 'L')),
            'routes' => \count($this->list($template, 'R')),
            'load' => $isLoadKnown ? ['cpu' => $cpu, 'powergrid' => $powergrid] : null,
            'products' => array_map(
                fn (int $typeId): array => [
                    'typeId' => $typeId,
                    'typeName' => $this->typeName($typeId, $types),
                    'tier' => $this->productionCalculator->findProductTier($typeId),
                ],
                array_values($productTypeIds),
            ),
            'extracted' => array_map(
                fn (int $typeId): array => ['typeId' => $typeId, 'typeName' => $this->typeName($typeId, $types)],
                array_values($extractedTypeIds),
            ),
            'skills' => $commandCenterLevel > 0
                ? [['name' => self::COMMAND_CENTER_UPGRADES_SKILL, 'level' => $commandCenterLevel]]
                : [],
        ];
    }

    /**
     * @param list<array{T: int, S: int|null, H: int}> $pins
     *
     * @return list<int>
     */
    private function referencedTypeIds(int $planetTypeId, array $pins): array
    {
        $typeIds = [$planetTypeId => $planetTypeId];
        foreach ($pins as $pin) {
            $typeIds[$pin['T']] = $pin['T'];
            if ($pin['S'] !== null) {
                $typeIds[$pin['S']] = $pin['S'];
            }
        }

        return array_values($typeIds);
    }

    /**
     * @param array<int, InvType> $types
     */
    private function typeName(int $typeId, array $types): ?string
    {
        return isset($types[$typeId]) ? $types[$typeId]->getTypeName() : null;
    }

    /**
     * @param array<mixed> $source
     *
     * @return list<array<mixed>>
     */
    private function list(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (!\is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException(\sprintf('The stored colony layout has no list "%s"', $key));
        }

        return array_map(
            static fn (mixed $entry): array => \is_array($entry) ? $entry : throw new \UnexpectedValueException(\sprintf('The stored colony layout has a malformed entry in "%s"', $key)),
            $value,
        );
    }

    /**
     * @param array<mixed> $source
     */
    private function integer(array $source, string $key): int
    {
        $value = $source[$key] ?? null;
        if (!\is_int($value)) {
            throw new \UnexpectedValueException(\sprintf('The stored colony layout has no integer "%s"', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $pin
     *
     * @return array{T: int, S: int|null, H: int}
     */
    private function readPin(array $pin): array
    {
        return [
            'T' => $this->integer($pin, 'T'),
            'S' => ($pin['S'] ?? null) === null ? null : $this->integer($pin, 'S'),
            'H' => $this->integer($pin, 'H'),
        ];
    }
}
