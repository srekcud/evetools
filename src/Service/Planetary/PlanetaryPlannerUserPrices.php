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
        return ['hasHubData' => false, 'prices' => []];
    }
}
