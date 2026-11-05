<?php

declare(strict_types=1);

namespace App\Service\Industry\SellMarkets;

use App\Entity\User;
use App\Service\Industry\SalesFees\SellerSalesFeesResolver;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\Service\StructureTradedVolumeService;

class SellMarketComparison
{
    public const float SELL_SHARE_OF_DAILY_VOLUME = 0.1;
    public const string JITA_MARKET_NAME = 'Jita IV - Moon 4';

    public function __construct(
        private readonly JitaMarketService $jitaMarketService,
        private readonly StructureMarketService $structureMarketService,
        private readonly StructureTradedVolumeService $structureTradedVolumeService,
        private readonly SellerSalesFeesResolver $salesFeesResolver,
    ) {
    }

    public function compare(User $user, int $typeId, int $quantity, \DateTimeImmutable $today): SellMarketComparisonResult
    {
        return new SellMarketComparisonResult($typeId, $quantity, [], new ImmediateSale(null, null, null));
    }
}
