<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\JobDuration;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\Runs;
use App\Industry\Domain\SkillTimeModifier;

final readonly class PlannedCopyDurations
{
    private const int SCIENCE_SKILL_TYPE_ID = 3402;
    private const int ADVANCED_INDUSTRY_SKILL_TYPE_ID = 3388;

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
        if (null === $plannedStructureId) {
            return self::unknown(null === $plannedStructureStatus ? MissingData::copyStructureNotPlanned() : MissingData::noCopyStructure());
        }

        $structure = $this->plannedStructureOf($ownerUserId, $plannedStructureId);
        if (null === $structure) {
            return self::unknown(MissingData::plannedStructureDeleted());
        }

        $missingData = [];
        $baseTimeSeconds = $this->baseTimes->of($productTypeId, ActivityKind::Copying);
        if (null === $baseTimeSeconds) {
            $missingData[] = MissingData::copyBaseTime($productTypeId);
        }
        $fastest = $this->fastestCopier($ownerCharacterNamesByEveCharacterId);
        if (null === $fastest) {
            $missingData[] = MissingData::copySkills();
        }
        if (null === $baseTimeSeconds || null === $fastest) {
            return new PlannedCopyDuration(null, $missingData, null);
        }

        $duration = JobDuration::forCopyJob($baseTimeSeconds, $runs, $fastest['multiplier']->times($structure->timeMultiplierFor(ActivityKind::Copying)));

        return new PlannedCopyDuration($duration->seconds, [], $fastest['name']);
    }

    private static function unknown(MissingData $reason): PlannedCopyDuration
    {
        return new PlannedCopyDuration(null, [$reason], null);
    }

    private function plannedStructureOf(string $ownerUserId, string $structureId): ?ProductionStructure
    {
        foreach ($this->userStructures->configuredBy($ownerUserId) as $structure) {
            if ($structure->id === $structureId) {
                return $structure;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $namesByEveCharacterId
     *
     * @return array{multiplier: Multiplier, name: string}|null
     */
    private function fastestCopier(array $namesByEveCharacterId): ?array
    {
        $fastest = null;
        foreach ($namesByEveCharacterId as $eveCharacterId => $name) {
            $scienceLevel = $this->characterSkillLevels->levelOf($eveCharacterId, self::SCIENCE_SKILL_TYPE_ID);
            $advancedIndustryLevel = $this->characterSkillLevels->levelOf($eveCharacterId, self::ADVANCED_INDUSTRY_SKILL_TYPE_ID);
            if (null === $scienceLevel || null === $advancedIndustryLevel) {
                continue;
            }

            $multiplier = SkillTimeModifier::forCopy($scienceLevel, $advancedIndustryLevel);
            if (null === $fastest || $multiplier->value < $fastest['multiplier']->value || ($multiplier->value === $fastest['multiplier']->value && strcmp($name, $fastest['name']) < 0)) {
                $fastest = ['multiplier' => $multiplier, 'name' => $name];
            }
        }

        return $fastest;
    }
}
