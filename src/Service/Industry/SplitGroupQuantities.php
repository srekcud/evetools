<?php

declare(strict_types=1);

namespace App\Service\Industry;

final class SplitGroupQuantities
{
    /**
     * @return list<int>
     */
    public static function runsBySplitIndex(int $totalRuns, int $numberOfJobs): array
    {
        $baseRunsPerJob = intdiv($totalRuns, $numberOfJobs);
        $remainder = $totalRuns - ($baseRunsPerJob * $numberOfJobs);

        $runsBySplitIndex = [];
        for ($splitIndex = 0; $splitIndex < $numberOfJobs; $splitIndex++) {
            $runsBySplitIndex[] = $baseRunsPerJob + ($splitIndex < $remainder ? 1 : 0);
        }

        return $runsBySplitIndex;
    }

    /**
     * @param list<int> $runsBySplitIndex
     * @return list<int>
     */
    public static function share(int $need, int $outputPerRun, array $runsBySplitIndex): array
    {
        $remainingNeed = $need;
        $quantities = [];

        foreach ($runsBySplitIndex as $runs) {
            $quantity = min($runs * $outputPerRun, $remainingNeed);
            $remainingNeed -= $quantity;
            $quantities[] = $quantity;
        }

        return $quantities;
    }
}
