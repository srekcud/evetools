<?php

declare(strict_types=1);

namespace App\Service\Industry\ContractSales;

use App\Repository\PublicContractListingRepository;

class ContractSalesReader
{
    private const int HISTORY_MARGIN_DAYS = 1;

    public function __construct(
        private readonly PublicContractListingRepository $repository,
        private readonly ContractSalesAnalyzer $analyzer,
    ) {
    }

    /**
     * @return array<int, ContractSalesIndicators>
     */
    public function indicatorsFor(int $regionId, \DateTimeImmutable $now): array
    {
        $since = $now->modify('-' . (ContractSalesAnalyzer::WINDOW_DAYS + self::HISTORY_MARGIN_DAYS) . ' days');

        return $this->analyzer->indicators($this->repository->findInRegionLastSeenSince($regionId, $since), $now);
    }
}
