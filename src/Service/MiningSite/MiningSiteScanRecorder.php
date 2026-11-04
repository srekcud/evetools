<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

use App\Entity\MiningSite;
use App\Entity\MiningSiteScan;

final class MiningSiteScanRecorder
{
    /**
     * @param list<ScanLine> $lines
     */
    public function record(MiningSite $site, array $lines): MiningSiteScan
    {
        return new MiningSiteScan();
    }
}
