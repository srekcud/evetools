<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\MarketSide;
use App\Repository\Sde\InvTypeMaterialRepository;
use App\Repository\Sde\InvTypeRepository;

class OreValueTableService
{
    public function __construct(
        private readonly OreValueService $oreValueService,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly InvTypeMaterialRepository $invTypeMaterialRepository,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function build(string $market, MarketSide $side, ?int $structureId, ?float $reprocessYield): array
    {
        return [];
    }
}
