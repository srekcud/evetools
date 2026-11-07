<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class PlanningCharacterLane
{
    /**
     * @param array{manufacturing: int, reaction: int}|null                         $slots
     * @param array{industry: int, advancedIndustry: int, reactions: int}|null      $skills
     * @param list<PlanningJob>                                                     $jobs
     */
    public function __construct(
        public string $characterName,
        public bool $skillsUnknown,
        public ?array $slots,
        public ?array $skills,
        public ?\DateTimeImmutable $endsAt,
        public array $jobs,
    ) {
    }
}
