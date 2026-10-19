<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProjectStep;

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

    /**
     * @param list<IndustryProjectStep> $steps
     * @return list<IndustryProjectStep>
     */
    public static function applyNeedToSteps(array $steps, int $need, int $outputPerRun): array
    {
        $totalRuns = (int) ceil($need / $outputPerRun);

        if (count($steps) === 1) {
            $step = $steps[0];
            if ($step->getQuantity() === $need && $step->getRuns() === $totalRuns) {
                return [];
            }
            $step->setQuantity($need);
            $step->setRuns($totalRuns);

            return [$step];
        }

        $oldTotalRuns = 0;
        foreach ($steps as $step) {
            $oldTotalRuns += $step->getRuns();
        }

        if ($oldTotalRuns === 0) {
            $oldTotalRuns = 1;
        }

        $assignedRuns = 0;
        $runsByStep = [];
        foreach ($steps as $i => $step) {
            $isLast = ($i === count($steps) - 1);

            if ($isLast) {
                $stepRuns = $totalRuns - $assignedRuns;
            } else {
                $stepRuns = (int) round($totalRuns * $step->getRuns() / $oldTotalRuns);
            }
            $stepRuns = max(1, $stepRuns);
            $runsByStep[$i] = $stepRuns;
            $assignedRuns += $stepRuns;
        }

        $stepIndexesBySplitIndex = array_keys($steps);
        usort(
            $stepIndexesBySplitIndex,
            static fn (int $a, int $b): int => $steps[$a]->getSplitIndex() <=> $steps[$b]->getSplitIndex(),
        );
        $quantitiesBySplitIndex = self::share(
            $need,
            $outputPerRun,
            array_map(static fn (int $i): int => $runsByStep[$i], $stepIndexesBySplitIndex),
        );
        $quantityByStep = array_combine($stepIndexesBySplitIndex, $quantitiesBySplitIndex);

        $changedSteps = [];
        foreach ($steps as $i => $step) {
            $stepRuns = $runsByStep[$i];
            $stepQuantity = $quantityByStep[$i];

            if ($step->getQuantity() !== $stepQuantity || $step->getRuns() !== $stepRuns) {
                $step->setQuantity($stepQuantity);
                $step->setRuns($stepRuns);
                $step->setTotalGroupRuns($totalRuns);
                $changedSteps[] = $step;
            }
        }

        return $changedSteps;
    }
}
