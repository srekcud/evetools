<?php

declare(strict_types=1);

namespace App\Service\Industry\Opportunities;

final readonly class OpportunityContext
{
    /**
     * @param array<int, list<array{skillId: int, level: int}>> $requiredSkillsByBlueprint
     * @param list<array<int, int>> $characterSkillLevels skill levels of each character whose skills are known
     * @param array<int, string> $skillNames
     * @param array<int, int> $baseTimeSecondsByBlueprint
     */
    public function __construct(
        public array $requiredSkillsByBlueprint,
        public array $characterSkillLevels,
        public array $skillNames,
        public array $baseTimeSecondsByBlueprint,
        public ?FreeSlots $freeSlots,
        public ?int $capitalCap,
    ) {
    }
}
