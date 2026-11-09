<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

use App\Entity\MiningSiteScan;

final class MiningSiteHistorySummarizer
{
    private const float SECONDS_PER_MINUTE = 60.0;

    public function summarize(MiningSiteScan $first, MiningSiteScan $last): MiningSiteHistorySummary
    {
        $observedSeconds = $last->getScannedAt()->getTimestamp() - $first->getScannedAt()->getTimestamp();

        return new MiningSiteHistorySummary(
            $observedSeconds,
            $first->getTotalVolumeM3(),
            $first->getRemainingValue(),
            $observedSeconds === 0 ? null : ($first->getTotalUnits() - $last->getTotalUnits()) * self::SECONDS_PER_MINUTE / $observedSeconds,
            $this->extractedValueOf($first, $last, $observedSeconds),
        );
    }

    private function extractedValueOf(MiningSiteScan $first, MiningSiteScan $last, int $observedSeconds): ?float
    {
        $firstValue = $first->getRemainingValue();
        $remainingValue = $last->getRemainingValue();
        if ($observedSeconds === 0 || $firstValue === null || $remainingValue === null) {
            return null;
        }

        return $firstValue - $remainingValue;
    }
}
