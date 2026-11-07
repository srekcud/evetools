<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\CachedCharacterSkill;
use App\Entity\CachedIndustryJob;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\JobDuration;
use App\Industry\Domain\ProductionSchedule;
use App\Industry\Domain\Runs;
use App\Industry\Domain\Schedule;
use App\Industry\Domain\ScheduledJob;
use App\Industry\Domain\ScheduleJob;
use App\Industry\Domain\ScheduleWorker;
use App\Industry\Domain\SkillTimeModifier;
use App\Industry\Domain\TimeEfficiency;

final readonly class ProjectPlanningBuilder
{
    private const string ACTIVITY_MANUFACTURING = 'manufacturing';
    private const string ACTIVITY_REACTION = 'reaction';

    private const string REAL_DONE = 'done';
    private const string REAL_ACTIVE = 'active';

    private const int UNTRAINED_LEVEL = 0;

    private const array STATUSES_IN_PROGRESS = [
        CachedIndustryJob::STATUS_ACTIVE,
        CachedIndustryJob::STATUS_PAUSED,
        CachedIndustryJob::STATUS_READY,
    ];

    public function build(ProjectPlanningInput $input, \DateTimeImmutable $now): ProjectPlanning
    {
        $lines = $input->lines;
        $workers = $this->workersOf($input->characters);

        $scheduleJobs = [];
        $durationUnknownLineIds = [];
        foreach ($lines as $line) {
            $durationsByWorker = $this->durationsByWorkerOf($line, $input->characters);
            if (null === $durationsByWorker) {
                $durationUnknownLineIds[$line->lineId] = true;
                $durationsByWorker = [];
            }
            $scheduleJobs[] = new ScheduleJob(
                $line->lineId,
                self::activityKindOf($line),
                $durationsByWorker,
                $this->dependencyLineIdsOf($line, $lines),
            );
        }

        $realByLineId = [];
        $fixedEnds = [];
        foreach ($lines as $line) {
            $real = $this->realOf($line);
            if (null !== $real) {
                $realByLineId[$line->lineId] = $real;
                $fixedEnds[$line->lineId] = $real['span']->end;
            }
        }

        $planned = ProductionSchedule::earliest($scheduleJobs, $workers, $input->anchor);
        $replanned = ProductionSchedule::earliest($scheduleJobs, $workers, $now, $fixedEnds);

        $plannedByLineId = $this->assignmentsByJobId($planned);
        $replannedByLineId = $this->assignmentsByJobId($replanned);

        $waitByLineId = $this->waitsOf($input, $plannedByLineId, $replannedByLineId, $realByLineId);

        $expectedEnd = $this->expectedEndOf($planned, $replanned, $fixedEnds);

        return new ProjectPlanning(
            $input->anchor,
            false,
            $planned->end,
            $expectedEnd,
            null === $planned->end || null === $expectedEnd ? null : $expectedEnd->getTimestamp() - $planned->end->getTimestamp(),
            $this->delayCauseOf($waitByLineId),
            $this->lanesOf($input, $plannedByLineId, $realByLineId, $waitByLineId),
            $this->unscheduledOf($lines, $planned, $durationUnknownLineIds),
        );
    }

    /**
     * @param list<PlanningCharacter> $characters
     *
     * @return list<ScheduleWorker>
     */
    private function workersOf(array $characters): array
    {
        $workers = [];
        foreach ($characters as $character) {
            if (null === $character->skillLevels) {
                continue;
            }
            $workers[] = new ScheduleWorker(
                $character->name,
                $character->manufacturingSlots ?? throw self::slotsMissing($character),
                $character->reactionSlots ?? throw self::slotsMissing($character),
            );
        }

        return $workers;
    }

    private static function slotsMissing(PlanningCharacter $character): \UnexpectedValueException
    {
        return new \UnexpectedValueException(\sprintf('Character "%s" has cached skills but unknown slots.', $character->name));
    }

    private static function activityKindOf(PlanningLine $line): ActivityKind
    {
        return match ($line->activity) {
            self::ACTIVITY_MANUFACTURING => ActivityKind::Manufacturing,
            self::ACTIVITY_REACTION => ActivityKind::Reaction,
        };
    }

    /**
     * @param list<PlanningCharacter> $characters
     *
     * @return array<string, int>|null null when the duration cannot be known for any character
     */
    private function durationsByWorkerOf(PlanningLine $line, array $characters): ?array
    {
        if (null === $line->baseTimeSeconds || null === $line->teLevel) {
            return null;
        }

        $runs = new Runs($line->runs);
        $durations = [];
        foreach ($characters as $character) {
            if (null === $character->skillLevels) {
                continue;
            }
            $durations[$character->name] = (self::ACTIVITY_REACTION === $line->activity
                ? JobDuration::forReactionJob(
                    $line->baseTimeSeconds,
                    $runs,
                    SkillTimeModifier::forReaction(self::levelOf($character->skillLevels, CachedCharacterSkill::SKILL_REACTIONS)),
                )
                : JobDuration::forManufacturingJob(
                    $line->baseTimeSeconds,
                    $runs,
                    new TimeEfficiency($line->teLevel),
                    SkillTimeModifier::forManufacturing(
                        self::levelOf($character->skillLevels, CachedCharacterSkill::SKILL_INDUSTRY),
                        self::levelOf($character->skillLevels, CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY),
                        array_map(
                            static fn (int $skillId): int => self::levelOf($character->skillLevels, $skillId),
                            $line->scienceSkillIds,
                        ),
                    ),
                ))->seconds;
        }

        return $durations;
    }

    /**
     * @param array<int, int> $skillLevels
     */
    private static function levelOf(array $skillLevels, int $skillId): int
    {
        return \array_key_exists($skillId, $skillLevels) ? $skillLevels[$skillId] : self::UNTRAINED_LEVEL;
    }

    /**
     * @param list<PlanningLine> $lines
     *
     * @return list<string>
     */
    private function dependencyLineIdsOf(PlanningLine $line, array $lines): array
    {
        $dependencyIds = [];
        foreach ($this->dependenciesOf($line, $lines) as $dependency) {
            $dependencyIds[] = $dependency->lineId;
        }

        return $dependencyIds;
    }

    /**
     * @param list<PlanningLine> $lines
     *
     * @return list<PlanningLine>
     */
    private function dependenciesOf(PlanningLine $line, array $lines): array
    {
        return array_values(array_filter(
            $lines,
            static fn (PlanningLine $candidate): bool => $candidate->parentTypeId === $line->typeId,
        ));
    }

    /**
     * @return array{span: PlanningRealSpan, characterName: string}|null
     */
    private function realOf(PlanningLine $line): ?array
    {
        $deliveredRuns = 0;
        $consideredJobs = [];
        foreach ($line->linkedJobs as $linkedJob) {
            if (\in_array($linkedJob->status, CachedIndustryJob::STATUSES_WITHOUT_OUTPUT, true)) {
                continue;
            }
            if (CachedIndustryJob::STATUS_DELIVERED === $linkedJob->status) {
                $deliveredRuns += $linkedJob->runs;
            } elseif (!\in_array($linkedJob->status, self::STATUSES_IN_PROGRESS, true)) {
                throw new \UnexpectedValueException(\sprintf('Line %s has a linked job with the unexpected status "%s".', $line->lineId, $linkedJob->status));
            }
            $consideredJobs[] = $linkedJob;
        }

        if ([] === $consideredJobs) {
            return null;
        }

        usort($consideredJobs, static fn (PlanningLinkedJob $left, PlanningLinkedJob $right): int => $left->start <=> $right->start);

        return [
            'span' => new PlanningRealSpan(
                $consideredJobs[0]->start,
                max(array_map(static fn (PlanningLinkedJob $linkedJob): \DateTimeImmutable => $linkedJob->end, $consideredJobs)),
                $deliveredRuns >= $line->runs ? self::REAL_DONE : self::REAL_ACTIVE,
            ),
            'characterName' => $consideredJobs[0]->characterName,
        ];
    }

    /**
     * @return array<string, ScheduledJob>
     */
    private function assignmentsByJobId(Schedule $schedule): array
    {
        $assignments = [];
        foreach ($schedule->assignments as $assignment) {
            $assignments[$assignment->jobId] = $assignment;
        }

        return $assignments;
    }

    /**
     * @param array<string, ScheduledJob>                                         $plannedByLineId
     * @param array<string, ScheduledJob>                                         $replannedByLineId
     * @param array<string, array{span: PlanningRealSpan, characterName: string}> $realByLineId
     *
     * @return array<string, PlanningWait>
     */
    private function waitsOf(ProjectPlanningInput $input, array $plannedByLineId, array $replannedByLineId, array $realByLineId): array
    {
        $missingMaterialNames = [];
        foreach ($input->materials as $material) {
            if ($material->fulfilledQuantity < $material->requiredQuantity) {
                $missingMaterialNames[] = $material->typeName;
            }
        }

        $waits = [];
        foreach ($input->lines as $line) {
            $plannedStart = isset($plannedByLineId[$line->lineId]) ? $plannedByLineId[$line->lineId]->start : null;
            if (null === $plannedStart) {
                continue;
            }

            if (isset($realByLineId[$line->lineId])) {
                $actualStart = $realByLineId[$line->lineId]['span']->start;
            } elseif (isset($replannedByLineId[$line->lineId])) {
                $actualStart = $replannedByLineId[$line->lineId]->start;
            } else {
                continue;
            }

            $lateSeconds = $actualStart->getTimestamp() - $plannedStart->getTimestamp();
            if ($lateSeconds <= 0) {
                continue;
            }

            $lateDependencyNames = [];
            foreach ($this->dependenciesOf($line, $input->lines) as $dependency) {
                $dependencyEnd = $this->actualEndOf($dependency, $replannedByLineId, $realByLineId);
                if (null !== $dependencyEnd && $dependencyEnd > $plannedStart) {
                    $lateDependencyNames[] = $dependency->typeName;
                }
            }

            $waits[$line->lineId] = match (true) {
                [] !== $lateDependencyNames => new PlanningWait(PlanningWait::WAITING_JOB, $lateDependencyNames, $lateSeconds),
                [] !== $missingMaterialNames => new PlanningWait(PlanningWait::WAITING_MATERIALS, $missingMaterialNames, $lateSeconds),
                default => new PlanningWait(PlanningWait::UNKNOWN, [], $lateSeconds),
            };
        }

        return $waits;
    }

    /**
     * @param array<string, ScheduledJob>                                         $replannedByLineId
     * @param array<string, array{span: PlanningRealSpan, characterName: string}> $realByLineId
     */
    private function actualEndOf(PlanningLine $line, array $replannedByLineId, array $realByLineId): ?\DateTimeImmutable
    {
        if (isset($realByLineId[$line->lineId])) {
            return $realByLineId[$line->lineId]['span']->end;
        }

        return isset($replannedByLineId[$line->lineId]) ? $replannedByLineId[$line->lineId]->end : null;
    }

    /**
     * @param array<string, \DateTimeImmutable> $fixedEnds
     */
    private function expectedEndOf(Schedule $planned, Schedule $replanned, array $fixedEnds): ?\DateTimeImmutable
    {
        if (null === $planned->end || [] !== $replanned->unscheduled) {
            return null;
        }

        $ends = $fixedEnds;
        foreach ($replanned->assignments as $assignment) {
            $ends[$assignment->jobId] = $assignment->end;
        }

        return [] === $ends ? null : max($ends);
    }

    /**
     * @param array<string, PlanningWait> $waitByLineId
     */
    private function delayCauseOf(array $waitByLineId): ?PlanningWait
    {
        $cause = null;
        foreach ($waitByLineId as $wait) {
            if (null === $cause || $wait->lateSeconds > $cause->lateSeconds) {
                $cause = $wait;
            }
        }

        return $cause;
    }

    /**
     * @param array<string, ScheduledJob>                                         $plannedByLineId
     * @param array<string, array{span: PlanningRealSpan, characterName: string}> $realByLineId
     * @param array<string, PlanningWait>                                         $waitByLineId
     *
     * @return list<PlanningCharacterLane>
     */
    private function lanesOf(ProjectPlanningInput $input, array $plannedByLineId, array $realByLineId, array $waitByLineId): array
    {
        $jobsByCharacterName = [];
        $jobSortKeys = [];
        $endsAtByCharacterName = [];
        foreach ($plannedByLineId as $assignment) {
            $currentEnd = $endsAtByCharacterName[$assignment->workerId] ?? null;
            if (null === $currentEnd || $assignment->end > $currentEnd) {
                $endsAtByCharacterName[$assignment->workerId] = $assignment->end;
            }
        }

        foreach ($input->characters as $character) {
            $jobsByCharacterName[$character->name] = [];
        }

        foreach ($input->lines as $line) {
            $assignment = $plannedByLineId[$line->lineId] ?? null;
            $real = $realByLineId[$line->lineId] ?? null;
            if (null !== $real) {
                $characterName = $real['characterName'];
                $sortKey = $assignment?->start->getTimestamp() ?? $real['span']->start->getTimestamp();
            } elseif (null !== $assignment) {
                $characterName = $assignment->workerId;
                $sortKey = $assignment->start->getTimestamp();
            } else {
                continue;
            }

            $jobsByCharacterName[$characterName][] = new PlanningJob(
                $line->lineId,
                $line->typeId,
                $line->typeName,
                $line->activity,
                $line->runs,
                null === $assignment ? null : new PlanningSpan($assignment->start, $assignment->end),
                $real['span'] ?? null,
                $waitByLineId[$line->lineId] ?? null,
            );
            $jobSortKeys[$line->lineId] = $sortKey;
        }

        $lanes = [];
        $characterByName = [];
        foreach ($input->characters as $character) {
            $characterByName[$character->name] = $character;
        }

        foreach ($jobsByCharacterName as $characterName => $jobs) {
            usort($jobs, static fn (PlanningJob $left, PlanningJob $right): int => $jobSortKeys[$left->lineId] <=> $jobSortKeys[$right->lineId]);
            $character = $characterByName[$characterName] ?? null;
            $lanes[] = new PlanningCharacterLane(
                (string) $characterName,
                null !== $character && null === $character->skillLevels,
                $this->slotsOf($character),
                $this->skillsOf($character),
                $endsAtByCharacterName[$characterName] ?? null,
                $jobs,
            );
        }

        return $lanes;
    }

    /**
     * @return array{manufacturing: int, reaction: int}|null
     */
    private function slotsOf(?PlanningCharacter $character): ?array
    {
        if (null === $character || null === $character->skillLevels) {
            return null;
        }

        return [
            'manufacturing' => $character->manufacturingSlots ?? throw self::slotsMissing($character),
            'reaction' => $character->reactionSlots ?? throw self::slotsMissing($character),
        ];
    }

    /**
     * @return array{industry: int, advancedIndustry: int, reactions: int}|null
     */
    private function skillsOf(?PlanningCharacter $character): ?array
    {
        if (null === $character || null === $character->skillLevels) {
            return null;
        }

        return [
            'industry' => self::levelOf($character->skillLevels, CachedCharacterSkill::SKILL_INDUSTRY),
            'advancedIndustry' => self::levelOf($character->skillLevels, CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY),
            'reactions' => self::levelOf($character->skillLevels, CachedCharacterSkill::SKILL_REACTIONS),
        ];
    }

    /**
     * @param list<PlanningLine>  $lines
     * @param array<string, true> $durationUnknownLineIds
     *
     * @return list<PlanningUnscheduled>
     */
    private function unscheduledOf(array $lines, Schedule $planned, array $durationUnknownLineIds): array
    {
        $unscheduled = [];
        foreach ($lines as $line) {
            if (isset($durationUnknownLineIds[$line->lineId])) {
                $unscheduled[] = new PlanningUnscheduled($line->lineId, $line->typeName, PlanningUnscheduled::DURATION_UNKNOWN);

                continue;
            }
            if (isset($planned->unscheduled[$line->lineId])) {
                $unscheduled[] = new PlanningUnscheduled($line->lineId, $line->typeName, $planned->unscheduled[$line->lineId]);
            }
        }

        return $unscheduled;
    }
}
