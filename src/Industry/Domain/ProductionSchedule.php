<?php

declare(strict_types=1);

namespace App\Industry\Domain;

final class ProductionSchedule
{
    /**
     * @param list<ScheduleJob>                 $jobs
     * @param list<ScheduleWorker>              $workers
     * @param array<string, \DateTimeImmutable> $fixedEnds job id => imposed end; such a job is not scheduled
     */
    public static function earliest(array $jobs, array $workers, \DateTimeImmutable $start, array $fixedEnds = []): Schedule
    {
        $slotsFreeAt = self::initialSlots($workers, $start);
        $endsByJobId = [];
        $assignments = [];
        $unscheduled = [];

        foreach (self::inTopologicalOrder($jobs) as $job) {
            if (isset($fixedEnds[$job->id])) {
                $endsByJobId[$job->id] = $fixedEnds[$job->id];

                continue;
            }

            $dependencyEnds = [];
            foreach ($job->dependsOn as $dependencyId) {
                if (!isset($endsByJobId[$dependencyId])) {
                    $unscheduled[$job->id] = Schedule::DEPENDENCY_UNSCHEDULED;

                    continue 2;
                }
                $dependencyEnds[] = $endsByJobId[$dependencyId];
            }

            $assignment = self::bestAssignment($job, $workers, $slotsFreeAt, max([$start, ...$dependencyEnds]));
            if (null === $assignment) {
                $unscheduled[$job->id] = Schedule::NO_ELIGIBLE_WORKER;

                continue;
            }

            [$scheduledJob, $slotIndex] = $assignment;
            $slotsFreeAt[$scheduledJob->workerId][$job->activity->name][$slotIndex] = $scheduledJob->end;
            $endsByJobId[$job->id] = $scheduledJob->end;
            $assignments[] = $scheduledJob;
        }

        return new Schedule($assignments, $unscheduled, self::planEnd($assignments, $unscheduled));
    }

    /**
     * @param list<ScheduleWorker> $workers
     *
     * @return array<string, array<string, array<int, \DateTimeImmutable>>> worker id => activity name => slot free time
     */
    private static function initialSlots(array $workers, \DateTimeImmutable $start): array
    {
        $slotsFreeAt = [];
        foreach ($workers as $worker) {
            $slotsFreeAt[$worker->id] = [
                ActivityKind::Manufacturing->name => array_fill(0, max(0, $worker->manufacturingSlots), $start),
                ActivityKind::Reaction->name => array_fill(0, max(0, $worker->reactionSlots), $start),
            ];
        }

        return $slotsFreeAt;
    }

    /**
     * @param list<ScheduleWorker>                                            $workers
     * @param array<string, array<string, array<int, \DateTimeImmutable>>>          $slotsFreeAt
     *
     * @return array{ScheduledJob, int}|null
     */
    private static function bestAssignment(ScheduleJob $job, array $workers, array $slotsFreeAt, \DateTimeImmutable $readyAt): ?array
    {
        $best = null;
        foreach ($workers as $worker) {
            $durationSeconds = $job->durationSecondsByWorker[$worker->id] ?? null;
            $slots = $slotsFreeAt[$worker->id][$job->activity->name] ?? [];
            if (null === $durationSeconds || [] === $slots) {
                continue;
            }

            $slotIndex = self::firstFreeSlot($slots);
            $jobStart = max($readyAt, $slots[$slotIndex]);
            $jobEnd = $jobStart->modify(\sprintf('+%d seconds', $durationSeconds));
            if (null === $best || $jobEnd < $best[0]->end) {
                $best = [new ScheduledJob($job->id, $worker->id, $jobStart, $jobEnd), $slotIndex];
            }
        }

        return $best;
    }

    /**
     * @param non-empty-array<int, \DateTimeImmutable> $slots
     */
    private static function firstFreeSlot(array $slots): int
    {
        $firstFreeIndex = 0;
        foreach ($slots as $index => $freeAt) {
            if ($freeAt < $slots[$firstFreeIndex]) {
                $firstFreeIndex = $index;
            }
        }

        return $firstFreeIndex;
    }

    /**
     * @param list<ScheduleJob> $jobs
     *
     * @return list<ScheduleJob>
     */
    private static function inTopologicalOrder(array $jobs): array
    {
        $knownIds = [];
        foreach ($jobs as $job) {
            $knownIds[$job->id] = true;
        }
        foreach ($jobs as $job) {
            foreach ($job->dependsOn as $dependencyId) {
                if (!isset($knownIds[$dependencyId])) {
                    throw new \DomainException(\sprintf('Job "%s" depends on the unknown job "%s".', $job->id, $dependencyId));
                }
            }
        }

        $ordered = [];
        $placedIds = [];
        $remaining = $jobs;
        while ([] !== $remaining) {
            $next = null;
            foreach ($remaining as $index => $job) {
                if ([] === array_filter($job->dependsOn, static fn (string $dependencyId): bool => !isset($placedIds[$dependencyId]))) {
                    $next = $index;

                    break;
                }
            }
            if (null === $next) {
                throw new \DomainException('The job dependencies contain a cycle.');
            }

            $ordered[] = $remaining[$next];
            $placedIds[$remaining[$next]->id] = true;
            unset($remaining[$next]);
        }

        return $ordered;
    }

    /**
     * @param list<ScheduledJob>    $assignments
     * @param array<string, string> $unscheduled
     */
    private static function planEnd(array $assignments, array $unscheduled): ?\DateTimeImmutable
    {
        if ([] !== $unscheduled || [] === $assignments) {
            return null;
        }

        return max(array_map(static fn (ScheduledJob $assignment): \DateTimeImmutable => $assignment->end, $assignments));
    }
}
