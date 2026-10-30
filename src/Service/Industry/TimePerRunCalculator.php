<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\CachedCharacterSkill;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\Sde\IndustryActivityRepository;
use Doctrine\ORM\EntityManagerInterface;

class TimePerRunCalculator
{
    /** @var array<string, int[]> Cache of blueprint science skill IDs */
    private array $blueprintSkillCache = [];

    /** @var array<string, int|null> */
    private array $baseTimeCache = [];

    public function __construct(
        private readonly IndustryCalculationService $calculationService,
        private readonly IndustryActivityRepository $activityRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CachedCharacterSkillRepository $skillRepository,
    ) {
    }

    /**
     * @param list<array{name: string, skills: array<int, int>|null}>|null $characterSkillLevels
     * @return array{timePerRun: int|null, recommendedCharacterName: string|null}
     */
    public function timePerRunAndRecommendedCharacterOf(User $user, int $blueprintTypeId, int $productTypeId, string $activityType, int $teLevel, ?IndustryStructureConfig $structureConfig, ?array $characterSkillLevels = null): array
    {
        $bestCharacter = $this->bestCharacterForStepTime($user, $blueprintTypeId, $activityType, $characterSkillLevels);

        $timePerRun = $bestCharacter['skillsUnknown']
            ? null
            : $this->timePerRunOf($user, $blueprintTypeId, $productTypeId, $activityType, $teLevel, $structureConfig, $bestCharacter['skills']);

        return ['timePerRun' => $timePerRun, 'recommendedCharacterName' => $bestCharacter['name']];
    }

    /**
     * Calculate time per run for a step.
     *
     * Formula: baseTime * (1 - TE/100) * (1 - structureTimeBonus/100) * Π(1 - skillBonus_i * level_i)
     *
     * Includes blueprint-specific science skills (1% per level) in addition to
     * Industry (4%/lvl), Advanced Industry (3%/lvl), and Reactions (4%/lvl).
     *
     * @param array<int, int>|null $characterSkills Skill type ID => level
     * @return int|null Time per run in seconds, or null if base time not found
     */
    public function calculateTimePerRun(IndustryProjectStep $step, ?array $characterSkills = null): ?int
    {
        return $this->timePerRunOf(
            $step->getProject()->getUser(),
            $step->getBlueprintTypeId(),
            $step->getProductTypeId(),
            $step->getActivityType(),
            $step->getTeLevel(),
            $step->getStructureConfig(),
            $characterSkills,
        );
    }

    /**
     * @param array<int, int>|null $characterSkills
     */
    public function timePerRunOf(
        User $user,
        int $blueprintTypeId,
        int $productTypeId,
        string $activityType,
        int $teLevel,
        ?IndustryStructureConfig $structureConfig,
        ?array $characterSkills,
    ): ?int {
        $baseTime = $this->baseTimePerRunOf($blueprintTypeId, $activityType);
        if ($baseTime === null) {
            return null;
        }

        $teMultiplier = 1 - $teLevel / 100;
        $structureTimeMultiplier = 1 - $this->calculationService->structureBonusOf($user, $productTypeId, $activityType, $structureConfig)['timeBonus'] / 100;

        $skillMultiplier = 1.0;
        if ($characterSkills !== null) {
            $scienceSkillIds = $this->getBlueprintScienceSkillIds($blueprintTypeId, $activityType);
            $skillMultiplier = $this->calculateSkillTimeMultiplier($characterSkills, $activityType, $scienceSkillIds);
        }

        return (int) ceil(round($baseTime * $teMultiplier * $structureTimeMultiplier * $skillMultiplier, 6));
    }

    /**
     * @return list<array{name: string, skills: array<int, int>|null}>
     */
    public function loadCharacterSkillLevels(User $user): array
    {
        $characterSkillLevels = [];
        foreach ($user->getCharacters() as $character) {
            $cachedSkills = $this->skillRepository->findAllSkillsForCharacter($character);
            $skillLevels = [];
            foreach ($cachedSkills as $skill) {
                $skillLevels[$skill->getSkillId()] = $skill->getLevel();
            }
            $characterSkillLevels[] = ['name' => $character->getName(), 'skills' => $cachedSkills === [] ? null : $skillLevels];
        }

        return $characterSkillLevels;
    }

    /**
     * @param list<array{name: string, skills: array<int, int>|null}>|null $characterSkillLevels
     * @return array{skills: array<int, int>|null, name: string|null, skillsUnknown: bool}
     */
    public function bestCharacterForStepTime(User $user, int $blueprintTypeId, string $activityType, ?array $characterSkillLevels = null): array
    {
        if ($activityType === 'copy') {
            return ['skills' => null, 'name' => null, 'skillsUnknown' => false];
        }

        $characterSkillLevels ??= $this->loadCharacterSkillLevels($user);
        $scienceSkillIds = $this->getBlueprintScienceSkillIds($blueprintTypeId, $activityType);

        $bestSkills = null;
        $bestName = null;
        $bestMultiplier = PHP_FLOAT_MAX;

        foreach ($characterSkillLevels as $character) {
            if ($character['skills'] === null) {
                continue;
            }

            $multiplier = $this->calculateSkillTimeMultiplier($character['skills'], $activityType, $scienceSkillIds);
            if ($multiplier < $bestMultiplier) {
                $bestMultiplier = $multiplier;
                $bestSkills = $character['skills'];
                $bestName = $character['name'];
            }
        }

        $skillsUnknown = $bestName === null && $characterSkillLevels !== [];

        return ['skills' => $bestSkills, 'name' => $bestName, 'skillsUnknown' => $skillsUnknown];
    }

    /**
     * Calculate the skill time multiplier for a given set of character skills.
     *
     * Applies Industry (4%/lvl), Advanced Industry (3%/lvl), or Reactions (4%/lvl)
     * plus blueprint-specific science skills (1%/lvl).
     *
     * @param array<int, int> $skillLevels Skill type ID => level
     * @param int[] $scienceSkillIds Blueprint-specific science skill IDs
     */
    public function calculateSkillTimeMultiplier(array $skillLevels, string $activityType, array $scienceSkillIds = []): float
    {
        $multiplier = 1.0;

        if ($activityType === 'reaction') {
            $reactionLevel = $skillLevels[CachedCharacterSkill::SKILL_REACTIONS] ?? 0;
            $multiplier *= (1 - CachedCharacterSkill::REACTION_TIME_BONUS_PER_LEVEL * $reactionLevel);
        } else {
            $industryLevel = $skillLevels[CachedCharacterSkill::SKILL_INDUSTRY] ?? 0;
            $advancedLevel = $skillLevels[CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY] ?? 0;
            $multiplier *= (1 - CachedCharacterSkill::INDUSTRY_TIME_BONUS_PER_LEVEL * $industryLevel);
            $multiplier *= (1 - CachedCharacterSkill::ADVANCED_INDUSTRY_TIME_BONUS_PER_LEVEL * $advancedLevel);
        }

        foreach ($scienceSkillIds as $skillId) {
            $level = $skillLevels[$skillId] ?? 0;
            if ($level > 0) {
                $multiplier *= (1 - CachedCharacterSkill::SCIENCE_SKILL_TIME_BONUS_PER_LEVEL * $level);
            }
        }

        return $multiplier;
    }

    /**
     * Get the science skill IDs required by a blueprint for manufacturing/reaction.
     * Excludes Industry, Advanced Industry, and Reactions (handled separately with different bonuses).
     *
     * @return int[]
     */
    public function getBlueprintScienceSkillIds(int $blueprintTypeId, string $activityType): array
    {
        $cacheKey = $blueprintTypeId . '_' . $activityType;
        if (isset($this->blueprintSkillCache[$cacheKey])) {
            return $this->blueprintSkillCache[$cacheKey];
        }

        $activityId = match ($activityType) {
            'reaction' => IndustryActivityType::Reaction->value,
            default => IndustryActivityType::Manufacturing->value,
        };

        $conn = $this->entityManager->getConnection();
        $rows = $conn->fetchAllAssociative(
            'SELECT skill_id FROM sde_industry_activity_skills WHERE type_id = ? AND activity_id = ?',
            [$blueprintTypeId, $activityId],
        );

        $skipSkills = CachedCharacterSkill::INDUSTRY_SKILL_IDS;
        $skillIds = [];

        foreach ($rows as $row) {
            $skillId = (int) $row['skill_id'];
            if (!in_array($skillId, $skipSkills, true)) {
                $skillIds[] = $skillId;
            }
        }

        $this->blueprintSkillCache[$cacheKey] = $skillIds;

        return $skillIds;
    }

    private function baseTimePerRunOf(int $blueprintTypeId, string $activityType): ?int
    {
        $cacheKey = $blueprintTypeId . '_' . $activityType;
        if (array_key_exists($cacheKey, $this->baseTimeCache)) {
            return $this->baseTimeCache[$cacheKey];
        }

        $activityId = match ($activityType) {
            'reaction' => IndustryActivityType::Reaction->value,
            'copy' => IndustryActivityType::Copying->value,
            default => IndustryActivityType::Manufacturing->value,
        };

        $activitiesByKey = $this->activityRepository->findByTypeIdsAndActivityIds([$blueprintTypeId], [$activityId]);

        $baseTime = ($activitiesByKey[$blueprintTypeId . '-' . $activityId] ?? null)?->getTime();
        $this->baseTimeCache[$cacheKey] = $baseTime;

        return $baseTime;
    }
}
