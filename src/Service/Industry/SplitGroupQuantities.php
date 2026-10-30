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

    public static function runsOfNeed(int $need, int $outputPerRun): int
    {
        return (int) ceil($need / $outputPerRun);
    }

    /**
     * @param list<IndustryProjectStep> $steps
     */
    public static function applyNeedToSteps(array $steps, int $need, int $outputPerRun): AppliedNeed
    {
        return new AppliedNeed(self::changedStepsOfTheNeed($steps, $need, $outputPerRun), []);
    }

    /**
     * @param list<IndustryProjectStep> $steps
     * @return list<IndustryProjectStep>
     */
    private static function changedStepsOfTheNeed(array $steps, int $need, int $outputPerRun): array
    {
        $totalRuns = self::runsOfNeed($need, $outputPerRun);

        if (count($steps) === 1) {
            $step = $steps[0];
            $stepRuns = $step->isRunsFixed() ? $step->getRuns() : $totalRuns;
            $stepQuantity = $step->isRunsFixed() ? $stepRuns * $outputPerRun : $need;
            $stepTotalGroupRuns = $step->getSplitGroupId() === null ? $step->getTotalGroupRuns() : $stepRuns;
            if ($step->getQuantity() === $stepQuantity && $step->getRuns() === $stepRuns && $step->getTotalGroupRuns() === $stepTotalGroupRuns) {
                return [];
            }
            $step->setQuantity($stepQuantity);
            $step->setRuns($stepRuns);
            $step->setTotalGroupRuns($stepTotalGroupRuns);

            return [$step];
        }

        $fixedSteps = array_filter($steps, static fn (IndustryProjectStep $step): bool => $step->isRunsFixed());
        $stepsNotFixed = array_diff_key($steps, $fixedSteps);
        $runsOfFixedSteps = array_map(static fn (IndustryProjectStep $step): int => $step->getRuns(), $fixedSteps);
        $fixedRuns = array_sum($runsOfFixedSteps);

        $runsByStep = $runsOfFixedSteps + self::runsProrataOfCurrentRuns($stepsNotFixed, $totalRuns - $fixedRuns);
        $quantityByStep = array_map(static fn (int $runs): int => $runs * $outputPerRun, $runsOfFixedSteps)
            + self::quantitiesInSplitIndexOrder($stepsNotFixed, $runsByStep, max(0, $need - $fixedRuns * $outputPerRun), $outputPerRun);
        $totalGroupRuns = $fixedSteps !== [] ? array_sum($runsByStep) : $totalRuns;

        $changedSteps = [];
        foreach ($steps as $i => $step) {
            $stepRuns = $runsByStep[$i];
            $stepQuantity = $quantityByStep[$i];

            if ($step->getQuantity() !== $stepQuantity || $step->getRuns() !== $stepRuns || $step->getTotalGroupRuns() !== $totalGroupRuns) {
                $step->setQuantity($stepQuantity);
                $step->setRuns($stepRuns);
                $step->setTotalGroupRuns($totalGroupRuns);
                $changedSteps[] = $step;
            }
        }

        return $changedSteps;
    }

    /**
     * @param array<int, IndustryProjectStep> $steps
     * @return array<int, int>
     */
    private static function runsProrataOfCurrentRuns(array $steps, int $totalRuns): array
    {
        $oldTotalRuns = array_sum(array_map(static fn (IndustryProjectStep $step): int => $step->getRuns(), $steps));

        if ($oldTotalRuns === 0) {
            $oldTotalRuns = 1;
        }

        $lastIndex = array_key_last($steps);
        $assignedRuns = 0;
        $runsByStep = [];
        foreach ($steps as $i => $step) {
            if ($i === $lastIndex) {
                $stepRuns = $totalRuns - $assignedRuns;
            } else {
                $stepRuns = (int) round($totalRuns * $step->getRuns() / $oldTotalRuns);
            }
            $stepRuns = max(1, $stepRuns);
            $runsByStep[$i] = $stepRuns;
            $assignedRuns += $stepRuns;
        }

        return $runsByStep;
    }

    /**
     * @param array<int, IndustryProjectStep> $steps
     * @param array<int, int> $runsByStep
     * @return array<int, int>
     */
    private static function quantitiesInSplitIndexOrder(array $steps, array $runsByStep, int $need, int $outputPerRun): array
    {
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

        return array_combine($stepIndexesBySplitIndex, $quantitiesBySplitIndex);
    }
}
