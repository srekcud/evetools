<?php

declare(strict_types=1);

namespace App\Service\Industry\ContractSales;

use App\Entity\PublicContractListing;
use App\Repository\Sde\InvTypeRepository;
use App\Service\JitaMarketService;

class FittedShipHullPricer
{
    private const int SHIP_CATEGORY_ID = 6;
    private const int HULL_QUANTITY = 1;

    public function __construct(
        private readonly InvTypeRepository $invTypeRepository,
        private readonly JitaMarketService $jitaMarketService,
    ) {
    }

    /**
     * @param list<PublicContractListing> $listings
     * @return array<int, HullAttribution>
     */
    public function attributionsFor(array $listings): array
    {
        $multiTypeListings = array_filter(
            $listings,
            static fn (PublicContractListing $listing): bool => null === $listing->getTypeId(),
        );
        if ([] === $multiTypeListings) {
            return [];
        }

        $shipTypeIds = $this->shipTypeIdsAmong($multiTypeListings);
        $hullByContractId = [];
        $equipmentTypeIds = [];
        foreach ($multiTypeListings as $listing) {
            $hull = $this->singleHullOf($listing, $shipTypeIds);
            if (null === $hull) {
                continue;
            }
            $hullByContractId[$listing->getContractId()] = $hull;
            foreach ($this->equipmentOf($listing, $hull) as $item) {
                $equipmentTypeIds[$item['typeId']] = true;
            }
        }
        if ([] === $hullByContractId) {
            return [];
        }

        $jitaPrices = $this->jitaMarketService->getPrices(array_keys($equipmentTypeIds));
        $attributions = [];
        foreach ($multiTypeListings as $listing) {
            $hullTypeId = $hullByContractId[$listing->getContractId()] ?? null;
            if (null === $hullTypeId) {
                continue;
            }
            $equipmentValue = $this->equipmentValue($this->equipmentOf($listing, $hullTypeId), $jitaPrices);
            if (null === $equipmentValue) {
                continue;
            }
            $hullPrice = $listing->getPrice() - $equipmentValue;
            if ($hullPrice <= 0.0) {
                continue;
            }
            $attributions[$listing->getContractId()] = new HullAttribution($hullTypeId, $hullPrice);
        }

        return $attributions;
    }

    /**
     * @param array<int, PublicContractListing> $listings
     * @return array<int, true>
     */
    private function shipTypeIdsAmong(array $listings): array
    {
        $typeIds = [];
        foreach ($listings as $listing) {
            foreach ($listing->getItems() as $item) {
                $typeIds[$item['typeId']] = true;
            }
        }

        $shipTypeIds = [];
        foreach ($this->invTypeRepository->findByTypeIds(array_keys($typeIds)) as $typeId => $type) {
            if (self::SHIP_CATEGORY_ID === $type->getGroup()->getCategory()->getCategoryId()) {
                $shipTypeIds[$typeId] = true;
            }
        }

        return $shipTypeIds;
    }

    /**
     * @param array<int, true> $shipTypeIds
     */
    private function singleHullOf(PublicContractListing $listing, array $shipTypeIds): ?int
    {
        $ships = array_values(array_filter(
            $listing->getItems(),
            static fn (array $item): bool => isset($shipTypeIds[$item['typeId']]),
        ));
        if (1 !== count($ships) || self::HULL_QUANTITY !== $ships[0]['quantity']) {
            return null;
        }

        return $ships[0]['typeId'];
    }

    /**
     * @return list<array{typeId: int, quantity: int}>
     */
    private function equipmentOf(PublicContractListing $listing, int $hullTypeId): array
    {
        return array_values(array_filter(
            $listing->getItems(),
            static fn (array $item): bool => $item['typeId'] !== $hullTypeId,
        ));
    }

    /**
     * @param list<array{typeId: int, quantity: int}> $equipment
     * @param array<int, float|null> $jitaPrices
     */
    private function equipmentValue(array $equipment, array $jitaPrices): ?float
    {
        $value = 0.0;
        foreach ($equipment as $item) {
            $unitPrice = $jitaPrices[$item['typeId']] ?? null;
            if (null === $unitPrice) {
                return null;
            }
            $value += $unitPrice * $item['quantity'];
        }

        return $value;
    }
}
