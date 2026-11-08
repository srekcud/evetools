<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Job;
use App\Industry\Domain\Plan;
use App\Industry\Domain\Quantity;

final class JobMaterialNeedsMapper
{
    private const string GROUP_COMPONENT = 'component';
    private const string GROUP_FINAL = 'final';

    /**
     * @param array<IndustryProjectBomLine> $bomLines
     * @return array<string, array<int, int>> material quantity by typeId, by BOM line id, for the job lines matched to a job of the plan
     */
    public static function map(array $bomLines, Plan $plan): array
    {
        $componentJobIds = [];
        foreach ($plan->intermediates as $intermediate) {
            foreach ($intermediate->jobs as $job) {
                $componentJobIds[spl_object_id($job)] = true;
            }
        }

        $jobsByKey = [];
        foreach ($plan->jobs as $job) {
            $group = isset($componentJobIds[spl_object_id($job)]) ? self::GROUP_COMPONENT : self::GROUP_FINAL;
            $jobsByKey[self::key($job->productTypeId, self::activityTypeOf($job->activity), $group, $job->materialEfficiency->value, $job->timeEfficiency->value)][] = $job;
        }

        $linesByKey = [];
        foreach ($bomLines as $line) {
            if ($line->isJob() && \in_array($line->getJobGroup(), [self::GROUP_COMPONENT, self::GROUP_FINAL], true)) {
                $linesByKey[self::key($line->getTypeId(), (string) $line->getActivityType(), (string) $line->getJobGroup(), (int) $line->getMeLevel(), (int) $line->getTeLevel())][] = $line;
            }
        }

        $needsByLineId = [];
        foreach ($linesByKey as $key => $lines) {
            $jobs = $jobsByKey[$key] ?? [];
            usort($lines, static fn (IndustryProjectBomLine $left, IndustryProjectBomLine $right): int => $right->getRuns() <=> $left->getRuns()
                ?: $left->getId()->toRfc4122() <=> $right->getId()->toRfc4122());
            usort($jobs, static fn (Job $left, Job $right): int => $right->runs->value <=> $left->runs->value);

            foreach ($lines as $rank => $line) {
                if (isset($jobs[$rank]) && $jobs[$rank]->runs->value === $line->getRuns()) {
                    $needsByLineId[$line->getId()->toRfc4122()] = array_map(static fn (Quantity $quantity): int => $quantity->value, $jobs[$rank]->materials);
                }
            }
        }

        $needs = [];
        foreach ($bomLines as $line) {
            $lineId = $line->getId()->toRfc4122();
            if (isset($needsByLineId[$lineId])) {
                $needs[$lineId] = $needsByLineId[$lineId];
            }
        }

        return $needs;
    }

    private static function key(int $typeId, string $activityType, string $group, int $materialEfficiency, int $timeEfficiency): string
    {
        return \sprintf('%d:%s:%s:%d:%d', $typeId, $activityType, $group, $materialEfficiency, $timeEfficiency);
    }

    private static function activityTypeOf(ActivityKind $activity): string
    {
        return match ($activity) {
            ActivityKind::Manufacturing => 'manufacturing',
            ActivityKind::Reaction => 'reaction',
            ActivityKind::Copying, ActivityKind::Invention => throw new \LogicException(\sprintf('A plan has no %s job.', $activity->name)),
        };
    }
}
