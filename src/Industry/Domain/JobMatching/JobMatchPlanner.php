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
     *
     * @return list<JobAssignment>
     */
    public static function plan(array $projects, array $lines, array $jobs, array $eligiblePairs): array
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
        $assignedJobIds = [];
        while (true) {
            $best = null;
            foreach ($candidates as $candidate) {
                if (isset($assignedJobIds[$candidate['job']->jobId])) {
                    continue;
                }

                $remainingRuns = $remainingRunsByLineId[$candidate['line']->id];
                if ($candidate['job']->runs > $remainingRuns) {
                    continue;
                }

                $candidate['exactFit'] = $candidate['job']->runs === $remainingRuns;
                $candidate['score'] = $candidate['staticScore'] + ($candidate['exactFit'] ? MatchSignal::ExactFit->weight() : 0);

                if ($best === null || self::isBetter($candidate, $best)) {
                    $best = $candidate;
                }
            }

            if ($best === null) {
                return $assignments;
            }

            $assignedJobIds[$best['job']->jobId] = true;
            $remainingRunsByLineId[$best['line']->id] -= $best['job']->runs;
            $assignments[] = new JobAssignment(
                $best['job']->jobId,
                $best['line']->id,
                self::signalsOf($best['staticSignals'], $best['exactFit']),
            );
        }
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

            $signals = self::staticSignalsOf($job, $line, $project, $containerItemSetsByProjectId[$project->id] ?? null);
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
     * @param array<int, int>|null $containerItemSet
     *
     * @return list<MatchSignal>
     */
    private static function staticSignalsOf(CandidateJob $job, CandidateLine $line, CandidateProject $project, ?array $containerItemSet): array
    {
        $signals = [];

        $chosenAsProducer = $project->producerInstallerIds !== null && \in_array($job->installerId, $project->producerInstallerIds, true);
        if ($chosenAsProducer || \in_array($job->installerId, $line->reservingInstallerIds, true)) {
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
