<?php

declare(strict_types=1);

namespace App\Service\MoonTax;

use App\Entity\MoonObserverRecord;
use App\Entity\MoonTaxRefinerySetting;
use App\Enum\MoonOreRarity;
use App\Repository\Sde\InvTypeRepository;
use App\Service\MiningSite\MiningSiteUnitPrices;

final readonly class MoonTaxAssessor
{
    public function __construct(
        private InvTypeRepository $typeRepository,
        private MiningSiteUnitPrices $unitPrices,
        private MoonTaxCalculator $calculator,
    ) {
    }

    /**
     * @template TGroup of array-key
     *
     * @param array<TGroup, list<MoonObserverRecord>> $recordsByGroup
     *
     * @return array<TGroup, MoonTax> same keys as the groups
     */
    public function assessAll(array $recordsByGroup, MoonTaxRefinerySetting $setting): array
    {
        $typeIds = [];
        foreach ($recordsByGroup as $records) {
            foreach ($records as $record) {
                $typeIds[$record->getTypeId()] = $record->getTypeId();
            }
        }
        $typeIds = array_values($typeIds);
        if ($typeIds === []) {
            return [];
        }

        $types = $this->typeRepository->findByTypeIds($typeIds);
        $rarityByTypeId = [];
        foreach ($typeIds as $typeId) {
            $rarityByTypeId[$typeId] = isset($types[$typeId]) ? MoonOreRarity::fromGroupId($types[$typeId]->getGroup()->getGroupId()) : null;
        }
        $unitPriceByTypeId = $this->unitPrices->read($typeIds);

        $moonTaxByGroup = [];
        foreach ($recordsByGroup as $group => $records) {
            $quantitiesByTypeId = [];
            foreach ($records as $record) {
                if (!isset($quantitiesByTypeId[$record->getTypeId()])) {
                    $quantitiesByTypeId[$record->getTypeId()] = $record->getQuantity();

                    continue;
                }
                $quantitiesByTypeId[$record->getTypeId()] += $record->getQuantity();
            }
            $moonTaxByGroup[$group] = $this->calculator->calculate($quantitiesByTypeId, $rarityByTypeId, $unitPriceByTypeId, $setting->ratesPercentByRarity());
        }

        return $moonTaxByGroup;
    }

    /**
     * @param non-empty-list<MoonObserverRecord> $records
     */
    public function latestRecordedCorporationId(array $records): int
    {
        usort($records, static fn (MoonObserverRecord $left, MoonObserverRecord $right): int => $right->getLastUpdated() <=> $left->getLastUpdated());

        return $records[0]->getRecordedCorporationId();
    }
}
