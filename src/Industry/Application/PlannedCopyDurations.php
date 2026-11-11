<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Runs;

final readonly class PlannedCopyDurations
{
    public function __construct(
        private ResearchBaseTimes $baseTimes,
        private CharacterSkillLevels $characterSkillLevels,
        private UserProductionStructures $userStructures,
    ) {
    }

    /**
     * @param array<int, string> $ownerCharacterNamesByEveCharacterId
     */
    public function of(
        string $ownerUserId,
        array $ownerCharacterNamesByEveCharacterId,
        int $productTypeId,
        Runs $runs,
        ?string $plannedStructureId,
        ?StructureChoiceStatus $plannedStructureStatus,
    ): PlannedCopyDuration {
        return new PlannedCopyDuration(null, [], null);
    }
}
