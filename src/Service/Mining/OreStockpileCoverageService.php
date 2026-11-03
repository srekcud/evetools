<?php

declare(strict_types=1);

namespace App\Service\Mining;

use App\Entity\MiningOperationMember;
use App\Entity\User;
use App\Repository\ReprocessingProfileRepository;
use App\Repository\Sde\InvTypeMaterialRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\StockpileService;
use App\Service\MiningOperation\MinedQuantityCalculator;

final readonly class OreStockpileCoverageService
{
    public function __construct(
        private MinedQuantityCalculator $calculator,
        private ReprocessingProfileRepository $profileRepository,
        private InvTypeMaterialRepository $materialRepository,
        private InvTypeRepository $typeRepository,
        private StockpileService $stockpileService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function coverageOf(User $user, MiningOperationMember $member): array
    {
        return [];
    }
}
