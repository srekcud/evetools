<?php

declare(strict_types=1);

namespace App\Service\Mining;

use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;

final readonly class OreSaleService
{
    public function __construct(
        private CachedAssetRepository $cachedAssetRepository,
        private InvTypeRepository $invTypeRepository,
        private JitaMarketService $jitaMarketService,
        private StructureMarketService $structureMarketService,
    ) {
    }

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function build(User $user, ?int $structureId): array
    {
        return ['rows' => [], 'totals' => []];
    }
}
