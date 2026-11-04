<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

use App\Entity\MiningSiteScan;

final class MiningSiteHistorySummarizer
{
    public function summarize(MiningSiteScan $first, MiningSiteScan $last): MiningSiteHistorySummary
    {
        return new MiningSiteHistorySummary(0, null, null, null);
    }
}
