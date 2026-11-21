<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Enum\ProjectStatus;

final readonly class ProjectCockpitCalculator
{
    private const string PHASE_PREPARE = 'prepare';
    private const string PHASE_SUPPLY = 'supply';
    private const string PHASE_PRODUCE = 'produce';
    private const string PHASE_SELL = 'sell';
    private const string PHASE_CLOSE = 'close';
    private const array PHASE_KEYS = [
        self::PHASE_PREPARE,
        self::PHASE_SUPPLY,
        self::PHASE_PRODUCE,
        self::PHASE_SELL,
        self::PHASE_CLOSE,
    ];

    private const string STATE_DONE = 'done';
    private const string STATE_CURRENT = 'current';
    private const string STATE_BLOCKED = 'blocked';
    private const string STATE_UPCOMING = 'upcoming';

    private const string BLOCKED_MATERIALS_MISSING = 'materials_missing';
    private const string BLOCKED_JOBS_UNFINISHED = 'jobs_unfinished';

    private const string ACTION_PROJECT_COMPLETED = 'project_completed';
    private const string ACTION_REVIEW_CONTRIBUTIONS = 'review_contributions';
    private const string ACTION_START_PROJECT = 'start_project';
    private const string ACTION_SOURCE_MATERIAL = 'source_material';
    private const string ACTION_LAUNCH_JOB = 'launch_job';
    private const string ACTION_AWAIT_JOBS = 'await_jobs';
    private const string ACTION_START_SELLING = 'start_selling';
    private const string ACTION_RECORD_SALE = 'record_sale';
    private const string ACTION_COMPLETE_PROJECT = 'complete_project';
    private const string ACTION_NOTHING_TO_DO = 'nothing_to_do';

    private const string TAB_MATERIALS = 'materials';
    private const string TAB_CONTRIBUTIONS = 'contributions';
    private const string TAB_SALES = 'sales';

    private const string JOB_GROUP_BLUEPRINT = 'blueprint';
    private const string JOB_GROUP_COMPONENT = 'component';
    private const string JOB_GROUP_FINAL = 'final';
    private const array JOB_GROUP_RANK = [
        self::JOB_GROUP_BLUEPRINT => 0,
        self::JOB_GROUP_COMPONENT => 1,
        self::JOB_GROUP_FINAL => 2,
    ];
    private const array BLOCKABLE_JOB_GROUPS = [self::JOB_GROUP_COMPONENT, self::JOB_GROUP_FINAL];

    private const int SINGLE_STEP = 1;
    private const int NONE = 0;

    public function calculate(ProjectCockpitInput $input): ProjectCockpit
    {
        $materialLines = array_values(array_filter($input->bomLines, static fn (CockpitBomLine $line): bool => !$line->isJob));
        $jobLines = array_values(array_filter($input->bomLines, static fn (CockpitBomLine $line): bool => $line->isJob));
        $finalLines = array_values(array_filter($jobLines, static fn (CockpitBomLine $line): bool => $line->jobGroup === self::JOB_GROUP_FINAL));
        $uncoveredMaterials = $this->undelivered($materialLines);
        $undeliveredJobs = $this->undeliveredJobs($jobLines);
        $unitsToSell = array_sum(array_map(static fn (CockpitBomLine $line): int => $line->requiredQuantity, $finalLines));
        $unitsSold = min($unitsToSell, $this->soldUnitsOf($finalLines, $input->soldQuantityByTypeId));

        $counters = [
            self::PHASE_PREPARE => [$input->status === ProjectStatus::Draft ? self::NONE : $input->productCount, $input->productCount],
            self::PHASE_SUPPLY => [\count($materialLines) - \count($uncoveredMaterials), \count($materialLines)],
            self::PHASE_PRODUCE => [\count($jobLines) - \count($undeliveredJobs), \count($jobLines)],
            self::PHASE_SELL => [$unitsSold, $unitsToSell],
            self::PHASE_CLOSE => [$input->status === ProjectStatus::Completed ? self::SINGLE_STEP : self::NONE, self::SINGLE_STEP],
        ];
        $isSourcingPhase = $input->status === ProjectStatus::InProgress && $uncoveredMaterials !== [] && !$this->productionStarted($jobLines);
        $blockedReasons = $this->blockedReasons($input->status, \count($uncoveredMaterials), \count($undeliveredJobs), $isSourcingPhase);
        $currentIndex = $isSourcingPhase ? array_search(self::PHASE_SUPPLY, self::PHASE_KEYS, true) : $this->currentPhaseIndex($input->status);

        $phases = [];
        foreach (self::PHASE_KEYS as $index => $key) {
            $blockedReason = $blockedReasons[$key] ?? null;
            $state = $blockedReason !== null ? self::STATE_BLOCKED : $this->stateOf($index, $currentIndex);
            $phases[] = new CockpitPhase($key, $state, $counters[$key][0], $counters[$key][1], $blockedReason);
        }

        return new ProjectCockpit(
            $input->status,
            $phases,
            $this->nextAction($input, $uncoveredMaterials, $jobLines, $undeliveredJobs, $unitsToSell - $unitsSold, $isSourcingPhase),
        );
    }

    /**
     * @param list<CockpitBomLine> $lines
     *
     * @return list<CockpitBomLine>
     */
    private function undelivered(array $lines): array
    {
        return array_values(array_filter(
            $lines,
            static fn (CockpitBomLine $line): bool => $line->fulfilledQuantity < $line->requiredQuantity,
        ));
    }

    /**
     * @param list<CockpitBomLine> $jobLines
     */
    private function productionStarted(array $jobLines): bool
    {
        foreach ($jobLines as $line) {
            if ($line->matchedRuns > self::NONE || $line->deliveredRuns > self::NONE || $line->fulfilledQuantity > self::NONE) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<CockpitBomLine> $jobLines
     *
     * @return list<CockpitBomLine>
     */
    private function undeliveredJobs(array $jobLines): array
    {
        return array_values(array_filter(
            $this->undelivered($jobLines),
            static fn (CockpitBomLine $line): bool => $line->runs === null || $line->deliveredRuns < $line->runs,
        ));
    }

    /**
     * @param list<CockpitBomLine> $finalLines
     * @param array<int, int>      $soldQuantityByTypeId
     */
    private function soldUnitsOf(array $finalLines, array $soldQuantityByTypeId): int
    {
        $requiredByTypeId = [];
        foreach ($finalLines as $line) {
            $requiredByTypeId[$line->typeId] = array_key_exists($line->typeId, $requiredByTypeId)
                ? $requiredByTypeId[$line->typeId] + $line->requiredQuantity
                : $line->requiredQuantity;
        }

        $soldUnits = self::NONE;
        foreach ($requiredByTypeId as $typeId => $requiredQuantity) {
            $soldUnits += min($requiredQuantity, array_key_exists($typeId, $soldQuantityByTypeId) ? $soldQuantityByTypeId[$typeId] : self::NONE);
        }

        return $soldUnits;
    }

    /**
     * @return array<string, CockpitBlockedReason>
     */
    private function blockedReasons(ProjectStatus $status, int $uncoveredMaterialCount, int $undeliveredJobCount, bool $isSourcingPhase): array
    {
        $reasons = [];

        if (!$isSourcingPhase && \in_array($status, [ProjectStatus::InProgress, ProjectStatus::Selling], true) && $uncoveredMaterialCount > self::NONE) {
            $reasons[self::PHASE_SUPPLY] = new CockpitBlockedReason(self::BLOCKED_MATERIALS_MISSING, $uncoveredMaterialCount);
        }

        if ($status === ProjectStatus::Selling && $undeliveredJobCount > self::NONE) {
            $reasons[self::PHASE_PRODUCE] = new CockpitBlockedReason(self::BLOCKED_JOBS_UNFINISHED, $undeliveredJobCount);
        }

        return $reasons;
    }

    private function currentPhaseIndex(ProjectStatus $status): int
    {
        return match ($status) {
            ProjectStatus::Draft => 0,
            ProjectStatus::Published => 1,
            ProjectStatus::InProgress => 2,
            ProjectStatus::Selling => 3,
            ProjectStatus::Completed => \count(self::PHASE_KEYS),
        };
    }

    private function stateOf(int $index, int $currentIndex): string
    {
        return match (true) {
            $index < $currentIndex => self::STATE_DONE,
            $index === $currentIndex => self::STATE_CURRENT,
            default => self::STATE_UPCOMING,
        };
    }

    /**
     * @param list<CockpitBomLine> $uncoveredMaterials
     * @param list<CockpitBomLine> $jobLines
     * @param list<CockpitBomLine> $undeliveredJobs
     */
    private function nextAction(ProjectCockpitInput $input, array $uncoveredMaterials, array $jobLines, array $undeliveredJobs, int $unitsLeftToSell, bool $isSourcingPhase): CockpitNextAction
    {
        if ($input->status === ProjectStatus::Completed) {
            return new CockpitNextAction(self::ACTION_PROJECT_COMPLETED, false, null, null, null, null, null, null);
        }

        if ($input->viewerIsManager && $input->pendingContributionCount > self::NONE) {
            return new CockpitNextAction(self::ACTION_REVIEW_CONTRIBUTIONS, false, self::TAB_CONTRIBUTIONS, null, null, null, null, $input->pendingContributionCount);
        }

        return match ($input->status) {
            ProjectStatus::Draft => $this->managerStatusAction($input, self::ACTION_START_PROJECT, ProjectStatus::InProgress),
            ProjectStatus::Published => $uncoveredMaterials !== []
                ? $this->sourceMaterial($input, $uncoveredMaterials)
                : $this->managerStatusAction($input, self::ACTION_START_PROJECT, ProjectStatus::InProgress),
            ProjectStatus::InProgress => $this->inProgressAction($input, $uncoveredMaterials, $jobLines, $undeliveredJobs, $isSourcingPhase),
            ProjectStatus::Selling => $unitsLeftToSell > self::NONE
                ? $this->managerAction($input, new CockpitNextAction(self::ACTION_RECORD_SALE, false, self::TAB_SALES, null, null, null, null, $unitsLeftToSell))
                : $this->managerStatusAction($input, self::ACTION_COMPLETE_PROJECT, ProjectStatus::Completed),
        };
    }

    /**
     * @param list<CockpitBomLine> $uncoveredMaterials
     * @param list<CockpitBomLine> $jobLines
     * @param list<CockpitBomLine> $undeliveredJobs
     */
    private function inProgressAction(ProjectCockpitInput $input, array $uncoveredMaterials, array $jobLines, array $undeliveredJobs, bool $isSourcingPhase): CockpitNextAction
    {
        if ($uncoveredMaterials !== []) {
            return $this->sourceMaterial($input, $uncoveredMaterials, !$isSourcingPhase);
        }

        if ($undeliveredJobs !== []) {
            $jobsToLaunch = $this->jobsToLaunch($undeliveredJobs, $jobLines);

            return $jobsToLaunch !== [] ? $this->launchJob($jobsToLaunch) : $this->awaitJobs($undeliveredJobs);
        }

        return $this->managerStatusAction($input, self::ACTION_START_SELLING, ProjectStatus::Selling);
    }

    /**
     * @param non-empty-list<CockpitBomLine> $uncoveredMaterials
     */
    private function sourceMaterial(ProjectCockpitInput $input, array $uncoveredMaterials, bool $blocked = false): CockpitNextAction
    {
        usort($uncoveredMaterials, static fn (CockpitBomLine $first, CockpitBomLine $second): int => [
            $second->requiredQuantity - $second->fulfilledQuantity,
            $first->typeName,
        ] <=> [
            $first->requiredQuantity - $first->fulfilledQuantity,
            $second->typeName,
        ]);
        $line = $uncoveredMaterials[0];

        return new CockpitNextAction(
            self::ACTION_SOURCE_MATERIAL,
            $blocked,
            self::TAB_MATERIALS,
            null,
            $line->id,
            $line->typeId,
            $line->typeName,
            $line->requiredQuantity - $line->fulfilledQuantity,
        );
    }

    /**
     * @param list<CockpitBomLine> $undeliveredJobs
     * @param list<CockpitBomLine> $jobLines
     *
     * @return list<CockpitBomLine>
     */
    private function jobsToLaunch(array $undeliveredJobs, array $jobLines): array
    {
        return array_values(array_filter(
            $undeliveredJobs,
            fn (CockpitBomLine $line): bool => ($line->runs === null || $line->runs > $line->matchedRuns) && !$this->isBlocked($line, $jobLines),
        ));
    }

    /**
     * @param list<CockpitBomLine> $jobLines
     */
    private function isBlocked(CockpitBomLine $line, array $jobLines): bool
    {
        if (!\in_array($line->jobGroup, self::BLOCKABLE_JOB_GROUPS, true) || $line->matchedRuns > self::NONE || $line->deliveredRuns > self::NONE) {
            return false;
        }

        foreach ($jobLines as $component) {
            if ($component->jobGroup === self::JOB_GROUP_COMPONENT && $component->parentTypeId === $line->typeId && !$this->isDelivered($component)) {
                return true;
            }
        }

        return false;
    }

    private function isDelivered(CockpitBomLine $line): bool
    {
        return $line->runs !== null && $line->deliveredRuns >= $line->runs;
    }

    /**
     * @param non-empty-list<CockpitBomLine> $runningJobs
     */
    private function awaitJobs(array $runningJobs): CockpitNextAction
    {
        $endsAt = null;
        foreach ($runningJobs as $line) {
            if ($line->latestJobEndDate !== null && ($endsAt === null || $line->latestJobEndDate > $endsAt)) {
                $endsAt = $line->latestJobEndDate;
            }
        }

        return new CockpitNextAction(self::ACTION_AWAIT_JOBS, false, self::TAB_MATERIALS, null, null, null, null, null, $endsAt);
    }

    /**
     * @param non-empty-list<CockpitBomLine> $undeliveredJobs
     */
    private function launchJob(array $undeliveredJobs): CockpitNextAction
    {
        usort($undeliveredJobs, fn (CockpitBomLine $first, CockpitBomLine $second): int => [
            $this->jobGroupRank($first),
            $this->chainLevelRank($first),
            $first->typeName,
        ] <=> [
            $this->jobGroupRank($second),
            $this->chainLevelRank($second),
            $second->typeName,
        ]);
        $line = $undeliveredJobs[0];
        $remainingRuns = $line->runs === null ? null : max(self::NONE, $line->runs - $line->matchedRuns);

        return new CockpitNextAction(self::ACTION_LAUNCH_JOB, false, self::TAB_MATERIALS, null, $line->id, $line->typeId, $line->typeName, $remainingRuns);
    }

    private function jobGroupRank(CockpitBomLine $line): int
    {
        if ($line->jobGroup === null || !array_key_exists($line->jobGroup, self::JOB_GROUP_RANK)) {
            throw new \UnexpectedValueException(sprintf('Unknown job group "%s" on BOM line %s', $line->jobGroup ?? 'null', $line->id));
        }

        return self::JOB_GROUP_RANK[$line->jobGroup];
    }

    private function chainLevelRank(CockpitBomLine $line): int
    {
        if ($line->jobGroup === self::JOB_GROUP_BLUEPRINT) {
            return self::NONE;
        }

        return $line->chainLevel ?? throw new \UnexpectedValueException(sprintf('Job line %s has no chain level', $line->id));
    }

    private function managerStatusAction(ProjectCockpitInput $input, string $code, ProjectStatus $targetStatus): CockpitNextAction
    {
        return $this->managerAction($input, new CockpitNextAction($code, false, null, $targetStatus->value, null, null, null, null));
    }

    private function managerAction(ProjectCockpitInput $input, CockpitNextAction $action): CockpitNextAction
    {
        return $input->viewerIsManager
            ? $action
            : new CockpitNextAction(self::ACTION_NOTHING_TO_DO, false, null, null, null, null, null, null);
    }
}
