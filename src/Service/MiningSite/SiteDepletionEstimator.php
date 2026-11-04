<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

final class SiteDepletionEstimator
{
    /**
     * @param list<SiteScanSample>  $scans
     * @param array<int, ?float>    $unitPrices
     * @param array<int, ?float>    $volumes
     */
    public function estimate(array $scans, array $unitPrices, array $volumes): SiteEstimate
    {
        $types = [];
        foreach ($scans as $scan) {
            foreach (array_keys($scan->unitsByTypeId) as $typeId) {
                $types[$typeId] = new SiteTypeEstimate($typeId, 0);
            }
        }

        return new SiteEstimate($types, null, null, [], null, [], null, false);
    }
}
