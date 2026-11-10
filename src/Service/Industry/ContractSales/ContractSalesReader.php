<?php

declare(strict_types=1);

namespace App\Service\Industry\ContractSales;

use App\Entity\PublicContractListing;
use App\Repository\PublicContractListingRepository;

class ContractSalesReader
{
    private const int HISTORY_MARGIN_DAYS = 1;

    public function __construct(
        private readonly PublicContractListingRepository $repository,
        private readonly ContractSalesAnalyzer $analyzer,
        private readonly FittedShipHullPricer $hullPricer,
    ) {
    }

    /**
     * @return array<int, ContractSalesIndicators>
     */
    public function indicatorsFor(int $regionId, \DateTimeImmutable $now): array
    {
        $since = $now->modify('-' . (ContractSalesAnalyzer::WINDOW_DAYS + self::HISTORY_MARGIN_DAYS) . ' days');

        $listings = $this->repository->findInRegionLastSeenSince($regionId, $since);
        $windowStart = $now->modify('-' . ContractSalesAnalyzer::WINDOW_DAYS . ' days');
        $confirmedMultiTypeListings = array_values(array_filter(
            $listings,
            static fn (PublicContractListing $listing): bool => null === $listing->getTypeId()
                && $listing->isDisappearanceConfirmed()
                && $listing->getDisappearedAt() >= $windowStart,
        ));

        return $this->analyzer->indicators($listings, $now, $this->hullPricer->attributionsFor($confirmedMultiTypeListings));
    }
}
