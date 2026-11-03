<?php

declare(strict_types=1);

namespace App\Service\Mining;

final readonly class ReprocessingSkillLevels
{
    public const int REPROCESSING_SKILL_ID = 3385;
    public const int REPROCESSING_EFFICIENCY_SKILL_ID = 3389;
    private const int UNTRAINED_LEVEL = 0;

    public int $reprocessing;
    public int $reprocessingEfficiency;

    /**
     * @param array<int, int> $activeLevelsBySkillId
     */
    public function __construct(private array $activeLevelsBySkillId)
    {
        $this->reprocessing = $this->level(self::REPROCESSING_SKILL_ID);
        $this->reprocessingEfficiency = $this->level(self::REPROCESSING_EFFICIENCY_SKILL_ID);
    }

    public function oreProcessing(int $oreProcessingSkillTypeId): int
    {
        return $this->level($oreProcessingSkillTypeId);
    }

    private function level(int $skillId): int
    {
        return array_key_exists($skillId, $this->activeLevelsBySkillId)
            ? $this->activeLevelsBySkillId[$skillId]
            : self::UNTRAINED_LEVEL;
    }
}
