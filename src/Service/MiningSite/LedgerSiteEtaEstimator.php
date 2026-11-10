<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

final readonly class LedgerSiteEtaEstimator
{
    /**
     * @param array<int, int> $minedByTypeId
     */
    public function estimate(SiteScanSample $scan, array $minedByTypeId, \DateTimeImmutable $startedAt, ?\DateTimeImmutable $ledgerFreshAt): LedgerSiteEta
    {
        return new LedgerSiteEta(null, null);
    }
}
