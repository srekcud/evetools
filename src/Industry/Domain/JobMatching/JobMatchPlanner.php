<?php

declare(strict_types=1);

namespace App\Industry\Domain\JobMatching;

final class JobMatchPlanner
{
    /**
     * @param list<CandidateProject> $projects
     * @param list<CandidateLine>    $lines
     * @param list<CandidateJob>     $jobs
     * @param list<EligiblePair>     $eligiblePairs
     */
    public static function plan(array $projects, array $lines, array $jobs, array $eligiblePairs): JobMatchPlan
    {
        $projectsById = [];
        foreach ($projects as $project) {
            $projectsById[$project->id] = $project;
        }

        $linesById = [];
        $remainingRunsByLineId = [];
        foreach ($lines as $line) {
            $linesById[$line->id] = $line;
            $remainingRunsByLineId[$line->id] = $line->remainingRuns;
        }

        $jobsById = [];
        foreach ($jobs as $job) {
            $jobsById[$job->jobId] = $job;
        }

        $candidates = self::candidatesOf($projectsById, $linesById, $jobsById, $eligiblePairs);

        $assignments = [];
        $unassignedJobs = [];
        $handledJobIds = [];
        while (true) {
            $fitting = self::fittingCandidates($candidates, $handledJobIds, $remainingRunsByLineId);
            if ($fitting === []) {
                break;
            }

            $best = self::bestOf($fitting);
            $jobId = $best['job']->jobId;
            $handledJobIds[$jobId] = true;
            $ofJob = array_values(array_filter($fitting, static fn (array $candidate): bool => $candidate['job']->jobId === $jobId));

            if (!self::oneProjectClearlyWins($ofJob)) {
                $unassignedJobs[] = new JobToAssign($jobId, self::candidatesToAssign($ofJob, false));
                continue;
            }

            $remainingRunsByLineId[$best['line']->id] -= $best['job']->runs;
            $assignments[] = new JobAssignment(
                $jobId,
                $best['line']->id,
                self::signalsOf($best['staticSignals'], $best['exactFit']),
            );
        }

        $oversizedByJobId = [];
        foreach ($candidates as $candidate) {
            if (isset($handledJobIds[$candidate['job']->jobId]) || $remainingRunsByLineId[$candidate['line']->id] <= 0) {
                continue;
            }

            $oversizedByJobId[$candidate['job']->jobId][] = $candidate;
        }
        foreach ($oversizedByJobId as $jobId => $ofJob) {
            $unassignedJobs[] = new JobToAssign($jobId, self::candidatesToAssign($ofJob, true));
        }

        usort($unassignedJobs, static fn (JobToAssign $left, JobToAssign $right): int => $left->jobId <=> $right->jobId);

        return new JobMatchPlan($assignments, $unassignedJobs);
    }

    /**
     * @param list<array{job: CandidateJob, line: CandidateLine, project: CandidateProject, staticSignals: list<MatchSignal>, staticScore: int, exactFit: bool, score: int}> $candidates
     * @param array<int, true>                                                                                                                                              $handledJobIds
     * @param array<string, int>                                                                                                                                            $remainingRunsByLineId
     *
     * @return list<array{job: CandidateJob, line: CandidateLine, project: CandidateProject, staticSignals: list<MatchSignal>, staticScore: int, exactFit: bool, score: int}>
     */
    private static function fittingCandidates(array $candidates, array $handledJobIds, array $remainingRunsByLineId): array
    {
        $fitting = [];
        foreach ($candidates as $candidate) {
            if (isset($handledJobIds[$candidate['job']->jobId])) {
                continue;
            }

            $remainingRuns = $remainingRunsByLineId[$candidate['line']->id];
            if ($candidate['job']->runs > $remainingRuns) {
                continue;
            }

            $candidate['exactFit'] = $candidate['job']->runs === $remainingRuns;
            $candidate['score'] = $candidate['staticScore'] + ($candidate['exactFit'] ? MatchSignal::ExactFit->weight() : 0);
            $fitting[] = $candidate;
        }

        return $fitting;
    }

    /**
     * @param non-empty-list<array{job: CandidateJob, line: CandidateLine, project: CandidateProject, staticSignals: list<MatchSignal>, staticScore: int, exactFit: bool, score: int}> $candidates
     *
     * @return array{job: CandidateJob, line: CandidateLine, project: CandidateProject, staticSignals: list<MatchSignal>, staticScore: int, exactFit: bool, score: int}
     */
    private static function bestOf(array $candidates): array
    {
        $best = null;
        foreach ($candidates as $candidate) {
            if ($best === null || self::isBetter($candidate, $best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @param list<array{project: CandidateProject, staticScore: int}> $candidatesOfJob
     */
    private static function oneProjectClearlyWins(array $candidatesOfJob): bool
    {
        $decisiveScoreByProjectId = [];
        foreach ($candidatesOfJob as $candidate) {
            $projectId = $candidate['project']->id;
            $decisiveScoreByProjectId[$projectId] = max($decisiveScoreByProjectId[$projectId] ?? $candidate['staticScore'], $candidate['staticScore']);
        }

        if (\count($decisiveScoreByProjectId) === 1) {
            return true;
        }

        rsort($decisiveScoreByProjectId);

        return $decisiveScoreByProjectId[0] > $decisiveScoreByProjectId[1];
    }

    /**
     * @param list<array{job: CandidateJob, line: CandidateLine, project: CandidateProject, staticSignals: list<MatchSignal>, staticScore: int, exactFit: bool, score: int}> $candidatesOfJob
     *
     * @return list<AssignmentCandidate>
     */
    private static function candidatesToAssign(array $candidatesOfJob, bool $oversized): array
    {
        if ($oversized) {
            $candidatesOfJob = array_map(static function (array $candidate): array {
                $candidate['exactFit'] = false;
                $candidate['score'] = $candidate['staticScore'];

                return $candidate;
            }, $candidatesOfJob);
        }

        usort($candidatesOfJob, static fn (array $left, array $right): int => match (true) {
            self::isBetter($left, $right) => -1,
            self::isBetter($right, $left) => 1,
            default => 0,
        });

        return array_map(static fn (array $candidate): AssignmentCandidate => new AssignmentCandidate(
            $candidate['project']->id,
            $candidate['line']->id,
            $candidate['score'],
            self::signalsOf($candidate['staticSignals'], $candidate['exactFit']),
            $oversized,
        ), $candidatesOfJob);
    }

    /**
     * @param array<string, CandidateProject> $projectsById
     * @param array<string, CandidateLine>    $linesById
     * @param array<int, CandidateJob>        $jobsById
     * @param list<EligiblePair>              $eligiblePairs
     *
     * @return list<array{job: CandidateJob, line: CandidateLine, project: CandidateProject, staticSignals: list<MatchSignal>, staticScore: int, exactFit: bool, score: int}>
     */
    private static function candidatesOf(array $projectsById, array $linesById, array $jobsById, array $eligiblePairs): array
    {
        $containerItemSetsByProjectId = [];
        foreach ($projectsById as $project) {
            if ($project->containerItemIds !== null) {
                $containerItemSetsByProjectId[$project->id] = array_flip($project->containerItemIds);
            }
        }

        $dedicatedProjectIdsByInstallerId = [];
        foreach ($projectsById as $project) {
            foreach ($project->dedicatedInstallerIds as $installerId) {
                $dedicatedProjectIdsByInstallerId[$installerId][] = $project->id;
            }
        }

        $candidates = [];
        $seenPairs = [];
        foreach ($eligiblePairs as $pair) {
            $pairKey = $pair->jobId.'|'.$pair->lineId;
            if (isset($seenPairs[$pairKey])) {
                continue;
            }
            $seenPairs[$pairKey] = true;

            $job = $jobsById[$pair->jobId];
            $line = $linesById[$pair->lineId];
            $project = $projectsById[$line->projectId];

            if (self::blueprintIsInAnotherProjectContainer($job, $project->id, $containerItemSetsByProjectId)) {
                continue;
            }

            $signals = self::staticSignalsOf($job, $line, $project, $containerItemSetsByProjectId[$project->id] ?? null, $dedicatedProjectIdsByInstallerId);
            $candidates[] = [
                'job' => $job,
                'line' => $line,
                'project' => $project,
                'staticSignals' => $signals,
                'staticScore' => array_sum(array_map(static fn (MatchSignal $signal): int => $signal->weight(), $signals)),
                'exactFit' => false,
                'score' => 0,
            ];
        }

        return $candidates;
    }

    /**
     * @param array<string, array<int, int>> $containerItemSetsByProjectId
     */
    private static function blueprintIsInAnotherProjectContainer(CandidateJob $job, string $projectId, array $containerItemSetsByProjectId): bool
    {
        if ($job->blueprintLocationId === null) {
            return false;
        }

        $owned = false;
        $ownedByAnother = false;
        foreach ($containerItemSetsByProjectId as $ownerId => $itemSet) {
            if (!isset($itemSet[$job->blueprintLocationId])) {
                continue;
            }

            if ($ownerId === $projectId) {
                $owned = true;
            } else {
                $ownedByAnother = true;
            }
        }

        return $ownedByAnother && !$owned;
    }

    /**
     * @param array<int, int>|null                    $containerItemSet
     * @param array<int|string, non-empty-list<string>> $dedicatedProjectIdsByInstallerId
     *
     * @return list<MatchSignal>
     */
    private static function staticSignalsOf(CandidateJob $job, CandidateLine $line, CandidateProject $project, ?array $containerItemSet, array $dedicatedProjectIdsByInstallerId): array
    {
        $signals = [];

        $dedicatedProjectIds = $dedicatedProjectIdsByInstallerId[$job->installerId] ?? [];
        $dedicatedToThisProject = \in_array($project->id, $dedicatedProjectIds, true);
        $dedicatedToAnotherProject = array_diff($dedicatedProjectIds, [$project->id]) !== [];
        $chosenAsProducer = !$dedicatedToAnotherProject
            && $project->producerInstallerIds !== null
            && \in_array($job->installerId, $project->producerInstallerIds, true);
        if ($dedicatedToThisProject || $chosenAsProducer || \in_array($job->installerId, $line->reservingInstallerIds, true)) {
            $signals[] = MatchSignal::Intent;
        }

        if ($containerItemSet !== null && (
            ($job->outputLocationId !== null && isset($containerItemSet[$job->outputLocationId]))
            || ($job->blueprintLocationId !== null && isset($containerItemSet[$job->blueprintLocationId]))
        )) {
            $signals[] = MatchSignal::Place;
        }

        if ($line->plannedStructureId !== null && $job->stationId !== null && $line->plannedStructureId === $job->stationId) {
            $signals[] = MatchSignal::Structure;
        }

        return $signals;
    }

    /**
     * @param list<MatchSignal> $staticSignals
     *
     * @return list<MatchSignal>
     */
    private static function signalsOf(array $staticSignals, bool $exactFit): array
    {
        return $exactFit ? [...$staticSignals, MatchSignal::ExactFit] : $staticSignals;
    }

    /**
     * @param array{job: CandidateJob, line: CandidateLine, project: CandidateProject, score: int} $candidate
     * @param array{job: CandidateJob, line: CandidateLine, project: CandidateProject, score: int} $best
     */
    private static function isBetter(array $candidate, array $best): bool
    {
        if ($candidate['score'] !== $best['score']) {
            return $candidate['score'] > $best['score'];
        }

        $byCreation = $candidate['project']->createdAt <=> $best['project']->createdAt;
        if ($byCreation !== 0) {
            return $byCreation < 0;
        }

        $byProject = strcmp($candidate['project']->id, $best['project']->id);
        if ($byProject !== 0) {
            return $byProject < 0;
        }

        $byLine = strcmp($candidate['line']->id, $best['line']->id);
        if ($byLine !== 0) {
            return $byLine < 0;
        }

        return $candidate['job']->jobId < $best['job']->jobId;
    }
}
