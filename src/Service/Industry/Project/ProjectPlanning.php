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
}
