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
        $totalRuns = self::runsOfNeed($need, $outputPerRun);

        if (count($steps) === 1) {
            return new AppliedNeed(self::applyNeedToSingleStep($steps[0], $need, $totalRuns, $outputPerRun) ? [$steps[0]] : [], []);
        }

        $fixedSteps = array_filter($steps, static fn (IndustryProjectStep $step): bool => $step->isRunsFixed());
        $stepsNotFixed = array_diff_key($steps, $fixedSteps);
        $runsOfFixedSteps = array_map(static fn (IndustryProjectStep $step): int => $step->getRuns(), $fixedSteps);
        $fixedRuns = array_sum($runsOfFixedSteps);

        $keptSteps = array_intersect_key($steps, $fixedSteps + self::keptStepsNotFixed($stepsNotFixed, $totalRuns - $fixedRuns, $fixedSteps === []));
        $surplusJobs = array_values(array_diff_key($steps, $keptSteps));

        if ($surplusJobs !== []) {
            self::absorbSurplusJobs($keptSteps, $surplusJobs);

            if (count($keptSteps) === 1) {
                $keptStep = reset($keptSteps);
                $keptStep->setSplitGroupId(null)->setSplitIndex(0)->setTotalGroupRuns(null);
                self::applyNeedToSingleStep($keptStep, $need, $totalRuns, $outputPerRun);

                return new AppliedNeed([$keptStep], $surplusJobs);
            }
        }

        $keptStepsNotFixed = array_diff_key($keptSteps, $fixedSteps);
        $runsByStep = $runsOfFixedSteps + self::runsProrataOfCurrentRuns($keptStepsNotFixed, $totalRuns - $fixedRuns);
        $quantityByStep = array_map(static fn (int $runs): int => $runs * $outputPerRun, $runsOfFixedSteps)
            + self::quantitiesInSplitIndexOrder($keptStepsNotFixed, $runsByStep, max(0, $need - $fixedRuns * $outputPerRun), $outputPerRun);
        $totalGroupRuns = $fixedSteps !== [] ? array_sum($runsByStep) : $totalRuns;
        $splitIndexByStep = $surplusJobs === []
            ? array_map(static fn (IndustryProjectStep $step): int => $step->getSplitIndex(), $keptSteps)
            : self::splitIndexesFromZero($keptSteps);

        $changedSteps = [];
        foreach ($keptSteps as $i => $step) {
            $stepRuns = $runsByStep[$i];
            $stepQuantity = $quantityByStep[$i];

            if ($step->getQuantity() !== $stepQuantity || $step->getRuns() !== $stepRuns || $step->getTotalGroupRuns() !== $totalGroupRuns || $step->getSplitIndex() !== $splitIndexByStep[$i]) {
                $step->setQuantity($stepQuantity);
                $step->setRuns($stepRuns);
                $step->setTotalGroupRuns($totalGroupRuns);
                $step->setSplitIndex($splitIndexByStep[$i]);
                $changedSteps[] = $step;
            }
        }

        return new AppliedNeed($changedSteps, $surplusJobs);
    }

    private static function applyNeedToSingleStep(IndustryProjectStep $step, int $need, int $totalRuns, int $outputPerRun): bool
    {
        $stepRuns = $step->isRunsFixed() ? $step->getRuns() : $totalRuns;
        $stepQuantity = $step->isRunsFixed() ? $stepRuns * $outputPerRun : $need;
        $stepTotalGroupRuns = $step->getSplitGroupId() === null ? $step->getTotalGroupRuns() : $stepRuns;
        if ($step->getQuantity() === $stepQuantity && $step->getRuns() === $stepRuns && $step->getTotalGroupRuns() === $stepTotalGroupRuns) {
            return false;
        }
        $step->setQuantity($stepQuantity);
        $step->setRuns($stepRuns);
        $step->setTotalGroupRuns($stepTotalGroupRuns);

        return true;
    }

    /**
     * @param array<int, IndustryProjectStep> $stepsNotFixed
     * @return array<int, IndustryProjectStep>
     */
    private static function keptStepsNotFixed(array $stepsNotFixed, int $runsLeftForStepsNotFixed, bool $groupHasNoFixedStep): array
    {
        $numberOfKeptSteps = max($groupHasNoFixedStep ? 1 : 0, min(count($stepsNotFixed), $runsLeftForStepsNotFixed));

        $stepIndexesByPriority = array_keys($stepsNotFixed);
        usort(
            $stepIndexesByPriority,
            static fn (int $a, int $b): int => [!$stepsNotFixed[$a]->isCommitted(), $stepsNotFixed[$a]->getSplitIndex()]
                <=> [!$stepsNotFixed[$b]->isCommitted(), $stepsNotFixed[$b]->getSplitIndex()],
        );

        return array_intersect_key($stepsNotFixed, array_flip(array_slice($stepIndexesByPriority, 0, $numberOfKeptSteps)));
    }

    /**
     * @param array<int, IndustryProjectStep> $keptSteps
     * @param list<IndustryProjectStep> $surplusJobs
     */
    private static function absorbSurplusJobs(array $keptSteps, array $surplusJobs): void
    {
        $stepIndexesFromLargestSplitIndex = array_reverse(self::stepIndexesInSplitIndexOrder($keptSteps));
        $absorbingJob = $keptSteps[$stepIndexesFromLargestSplitIndex[0]];

        foreach ($surplusJobs as $surplusJob) {
            $absorbingJob->absorb($surplusJob);
        }
    }

    /**
     * @param array<int, IndustryProjectStep> $steps
     * @return array<int, int>
     */
    private static function splitIndexesFromZero(array $steps): array
    {
        return array_flip(self::stepIndexesInSplitIndexOrder($steps));
    }

    /**
     * @param array<int, IndustryProjectStep> $steps
     * @return list<int>
     */
    private static function stepIndexesInSplitIndexOrder(array $steps): array
    {
        $stepIndexes = array_keys($steps);
        usort(
            $stepIndexes,
            static fn (int $a, int $b): int => $steps[$a]->getSplitIndex() <=> $steps[$b]->getSplitIndex(),
        );

        return $stepIndexes;
    }

    /**
     * @param array<int, IndustryProjectStep> $steps
     * @return array<int, int>
     */
    private static function runsProrataOfCurrentRuns(array $steps, int $totalRuns): array
    {
        if ($steps === []) {
            return [];
        }

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

        return self::withoutExcessRuns($runsByStep, $steps, $totalRuns);
    }

    /**
     * @param array<int, int> $runsByStep
     * @param array<int, IndustryProjectStep> $steps
     * @return array<int, int>
     */
    private static function withoutExcessRuns(array $runsByStep, array $steps, int $totalRuns): array
    {
        for ($excessRuns = array_sum($runsByStep) - $totalRuns; $excessRuns > 0; $excessRuns--) {
            $stepIndexes = array_keys($runsByStep);
            usort(
                $stepIndexes,
                static fn (int $a, int $b): int => [$runsByStep[$b], $steps[$a]->getSplitIndex()] <=> [$runsByStep[$a], $steps[$b]->getSplitIndex()],
            );
            $runsByStep[$stepIndexes[0]]--;
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
        $stepIndexesBySplitIndex = self::stepIndexesInSplitIndexOrder($steps);
        $quantitiesBySplitIndex = self::share(
            $need,
            $outputPerRun,
            array_map(static fn (int $i): int => $runsByStep[$i], $stepIndexesBySplitIndex),
        );

        return array_combine($stepIndexesBySplitIndex, $quantitiesBySplitIndex);
    }
}
