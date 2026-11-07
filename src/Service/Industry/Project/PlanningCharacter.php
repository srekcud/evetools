<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class PlanningCharacter
{
    /**
     * @param array<int, int>|null $skillLevels skill id => level; null when no skill is cached (unknown, never level 0)
     */
    public function __construct(
        public string $name,
        public ?array $skillLevels,
        public ?int $manufacturingSlots,
        public ?int $reactionSlots,
    ) {
    }
}
