<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Entity\User;
use App\Service\StructureMarketService;

final readonly class PlanetaryPlannerUserPrices
{
    public function __construct(
        private StructureMarketService $structureMarketService,
        private PlanetaryPaidPriceCalculator $paidPriceCalculator,
    ) {
    }

    /**
     * @param list<int> $typeIds
     *
     * @return array{hasHubData: bool, prices: array<int, array{hub: float|null, paid: float|null}>}
     */
    public function read(User $user, array $typeIds, int $hubStructureId, \DateTimeImmutable $now): array
    {
        $hubSells = $this->structureMarketService->getLowestSellPrices($hubStructureId, $typeIds);
        $paidPrices = array_column($this->paidPriceCalculator->calculate($user, $now)['prices'], 'paidPrice', 'typeId');

        $prices = [];
        foreach ($typeIds as $typeId) {
            $prices[$typeId] = ['hub' => $hubSells[$typeId] ?? null, 'paid' => $paidPrices[$typeId] ?? null];
        }

        return ['hasHubData' => $this->structureMarketService->hasCachedData($hubStructureId), 'prices' => $prices];
    }
}
