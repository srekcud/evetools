<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class ProjectPlanning
{
    /**
     * @param list<PlanningCharacterLane> $characters
     * @param list<PlanningUnscheduled>   $unscheduled
     */
    public function __construct(
        public \DateTimeImmutable $anchor,
        public bool $structureTimeBonusIncluded,
        public ?\DateTimeImmutable $plannedEnd,
        public ?\DateTimeImmutable $expectedEnd,
        public ?int $delaySeconds,
        public ?PlanningWait $delayCause,
        public array $characters,
        public array $unscheduled,
    ) {
    }

    /**
     * @return list<PlanningJob>
     */
    public function jobs(): array
    {
        $jobs = [];
        foreach ($this->characters as $lane) {
            foreach ($lane->jobs as $job) {
                $jobs[] = $job;
            }
        }

        usort($jobs, static function (PlanningJob $left, PlanningJob $right): int {
            if (null === $left->planned || null === $right->planned) {
                if (null === $left->planned && null === $right->planned) {
                    return strcmp($left->lineId, $right->lineId);
                }

                return null === $left->planned ? 1 : -1;
            }

            return ($left->planned->start <=> $right->planned->start) ?: strcmp($left->lineId, $right->lineId);
        });

        return $jobs;
    }
}
