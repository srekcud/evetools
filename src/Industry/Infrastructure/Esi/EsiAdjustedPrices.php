<?php

declare(strict_types=1);

namespace App\Industry\Infrastructure\Esi;

use App\Industry\Application\AdjustedPrices;
use App\Industry\Domain\Isk;
use App\Service\Industry\EsiCostIndexService;

final readonly class EsiAdjustedPrices implements AdjustedPrices
{
    public function __construct(private EsiCostIndexService $esiCostIndexService)
    {
    }

    public function adjustedPriceOf(int $typeId): ?Isk
    {
        $adjustedPrice = $this->esiCostIndexService->getAdjustedPrice($typeId);
        if (null === $adjustedPrice || $adjustedPrice <= 0.0) {
            return null;
        }

        return new Isk($adjustedPrice);
    }
}
