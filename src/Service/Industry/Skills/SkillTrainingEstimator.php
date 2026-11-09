<?php

declare(strict_types=1);

namespace App\Service\Industry\Skills;

use App\Entity\CachedCharacterAttributes;
use App\Entity\Character;
use App\Enum\SkillTrainingUnknownReason;
use App\Industry\Domain\SkillTraining;
use App\Repository\CachedCharacterAttributesRepository;
use App\Repository\Sde\DgmTypeAttributeRepository;

class SkillTrainingEstimator
{
    private const int MAX_LEVEL = 5;
    private const int RANK_ATTRIBUTE_ID = 275;
    private const int PRIMARY_ATTRIBUTE_ID = 180;
    private const int SECONDARY_ATTRIBUTE_ID = 181;
    private const int CHARISMA_ID = 164;
    private const int INTELLIGENCE_ID = 165;
    private const int MEMORY_ID = 166;
    private const int PERCEPTION_ID = 167;
    private const int WILLPOWER_ID = 168;
    private const array CHARACTER_ATTRIBUTE_IDS = [
        self::CHARISMA_ID,
        self::INTELLIGENCE_ID,
        self::MEMORY_ID,
        self::PERCEPTION_ID,
        self::WILLPOWER_ID,
    ];

    public function __construct(
        private readonly DgmTypeAttributeRepository $typeAttributeRepository,
        private readonly CachedCharacterAttributesRepository $attributesRepository,
    ) {
    }

    public function nextLevel(Character $character, int $skillId, int $currentLevel): SkillTrainingEstimate
    {
        if ($currentLevel >= self::MAX_LEVEL) {
            return $this->unknown(SkillTrainingUnknownReason::MaxLevelReached);
        }

        $valuesByAttributeId = [];
        foreach ($this->typeAttributeRepository->findByTypeId($skillId) as $row) {
            $valuesByAttributeId[$row->getAttributeId()] = $row->getValue();
        }

        $rank = $valuesByAttributeId[self::RANK_ATTRIBUTE_ID] ?? null;
        $primaryAttributeId = $valuesByAttributeId[self::PRIMARY_ATTRIBUTE_ID] ?? null;
        $secondaryAttributeId = $valuesByAttributeId[self::SECONDARY_ATTRIBUTE_ID] ?? null;
        if ($rank === null
            || !in_array($primaryAttributeId, self::CHARACTER_ATTRIBUTE_IDS)
            || !in_array($secondaryAttributeId, self::CHARACTER_ATTRIBUTE_IDS)
        ) {
            return $this->unknown(SkillTrainingUnknownReason::SkillNotInSde);
        }

        $attributes = $this->attributesRepository->findOneBy(['character' => $character]);
        if ($attributes === null) {
            return $this->unknown(SkillTrainingUnknownReason::AttributesNotSynchronized);
        }

        $pointsByAttributeId = $this->pointsByAttributeId($attributes);

        return new SkillTrainingEstimate(
            SkillTraining::secondsToNextLevel(
                (int) $rank,
                $currentLevel,
                $pointsByAttributeId[(int) $primaryAttributeId],
                $pointsByAttributeId[(int) $secondaryAttributeId],
            ),
            null,
        );
    }

    /**
     * @return array<int, int>
     */
    private function pointsByAttributeId(CachedCharacterAttributes $attributes): array
    {
        return [
            self::CHARISMA_ID => $attributes->getCharisma(),
            self::INTELLIGENCE_ID => $attributes->getIntelligence(),
            self::MEMORY_ID => $attributes->getMemory(),
            self::PERCEPTION_ID => $attributes->getPerception(),
            self::WILLPOWER_ID => $attributes->getWillpower(),
        ];
    }

    private function unknown(SkillTrainingUnknownReason $reason): SkillTrainingEstimate
    {
        return new SkillTrainingEstimate(null, $reason);
    }
}
