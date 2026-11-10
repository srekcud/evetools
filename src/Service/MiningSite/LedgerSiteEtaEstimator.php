<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

use App\Enum\MiningSiteLedgerEtaUnknownReason;

final readonly class LedgerSiteEtaEstimator
{
    /**
     * @param array<int, int> $minedByTypeId
     */
    public function estimate(SiteScanSample $scan, array $minedByTypeId, \DateTimeImmutable $startedAt, ?\DateTimeImmutable $ledgerFreshAt): LedgerSiteEta
    {
        $windowSeconds = null === $ledgerFreshAt ? 0 : $ledgerFreshAt->getTimestamp() - $startedAt->getTimestamp();
        $latestEtaTimestamp = null;
        if ($windowSeconds > 0) {
            foreach ($scan->unitsByTypeId as $typeId => $units) {
                $mined = $minedByTypeId[$typeId] ?? 0;
                if ($mined > 0) {
                    $etaTimestamp = $scan->at->getTimestamp() + (int) round($units * $windowSeconds / $mined);
                    $latestEtaTimestamp = null === $latestEtaTimestamp ? $etaTimestamp : max($latestEtaTimestamp, $etaTimestamp);
                }
            }
        }

        if (null === $latestEtaTimestamp) {
            return new LedgerSiteEta(null, MiningSiteLedgerEtaUnknownReason::NothingMined);
        }

        return new LedgerSiteEta($scan->at->setTimestamp($latestEtaTimestamp), null);
    }
}
