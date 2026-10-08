<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\CachedCharacterSkill;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\IndustryUserSettingsRepository;
use App\Enum\IndustryActivityType;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\StaStationRepository;
use App\Service\TypeNameResolver;
use Doctrine\ORM\EntityManagerInterface;

class IndustryCalculationService
{

    /** @var array<string, int[]> Cache of blueprint science skill IDs */
    private array $blueprintSkillCache = [];

    public function __construct(
        private readonly InvTypeRepository $invTypeRepository,
        private readonly TypeNameResolver $typeNameResolver,
        private readonly IndustryBonusService $bonusService,
        private readonly IndustryStructureConfigRepository $structureConfigRepository,
        private readonly IndustryUserSettingsRepository $settingsRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly StaStationRepository $staStationRepository,
        private readonly CachedStructureRepository $cachedStructureRepository,
    ) {
    }

    /**
     * Resolve the name of a facility (NPC station or player structure) from its ID.
     */
    public function resolveFacilityName(int $stationId): ?string
    {
        // NPC stations have IDs below 1 billion
        if ($stationId < 1_000_000_000) {
            $station = $this->staStationRepository->findByStationId($stationId);
            return $station?->getStationName();
        }

        // Player structures: check cached structures first (market-synced)
        $structure = $this->cachedStructureRepository->findByStructureId($stationId);
        if ($structure !== null) {
            return $structure->getName();
        }

        // Fallback: check user-configured industry structures
        $config = $this->structureConfigRepository->findByLocationId($stationId);
        return $config?->getName();
    }

    public function resolveTypeName(int $typeId): string
    {
        return $this->typeNameResolver->resolve($typeId);
    }

    /**
     * Calculate adjusted material quantity using exact EVE formula.
     *
     * Formula: max(runs, ceil(round(baseQty * runs * ((100-ME)/100) * EC_modifier * rig_modifier, 2)))
     *
     * EVE applies structure base bonus and rig bonus multiplicatively:
     * EC_modifier = (1 - structureBaseBonus/100)
     * rig_modifier = (1 - rigBonus/100)
     *
     * @param int $baseQty Base material quantity per run from SDE
     * @param int $runs Number of runs
     * @param int $meLevel Material Efficiency level (0-10)
     * @param float $structureBaseBonus Structure base ME bonus percentage (e.g. 1% for EC)
     * @param float $rigBonus Rig ME bonus percentage after security multiplier (e.g. 4.2%)
     */
    public function calculateMaterialQuantity(int $baseQty, int $runs, int $meLevel, float $structureBaseBonus = 0.0, float $rigBonus = 0.0): int
    {
        $meMultiplier = (100 - $meLevel) / 100;
        $structureMultiplier = $structureBaseBonus > 0 ? (1 - $structureBaseBonus / 100) : 1.0;
        $rigMultiplier = $rigBonus > 0 ? (1 - $rigBonus / 100) : 1.0;

        return max(
            $runs,
            (int) ceil(round($baseQty * $runs * $meMultiplier * $structureMultiplier * $rigMultiplier, 2))
        );
    }

    /**
     * Get the structure bonus for a step, using the step's assigned structure or finding the best one.
     *
     * Returns materialBonus as an array with separate base/rig values for multiplicative stacking.
     * favoriteSystemWithoutSuitableStructure is true when a favorite system is configured for the
     * activity but holds no structure able to run it, so the best of all structures was taken.
     *
     * @return array{structure: IndustryStructureConfig|null, materialBonus: array{total: float, base: float, rig: float}, timeBonus: float, name: string|null, favoriteSystemWithoutSuitableStructure: bool}
     */
    public function getStructureBonusForStep(IndustryProjectStep $step): array
    {
        $structureConfig = $step->getStructureConfig();
        $isReaction = $step->getActivityType() === 'reaction';

        if ($structureConfig !== null) {
            $category = $this->bonusService->getCategoryForProduct($step->getProductTypeId(), $isReaction);
            if ($category) {
                $materialBonus = $this->bonusService->calculateStructureBonusForCategory($structureConfig, $category);
                $timeBonus = $this->bonusService->calculateStructureTimeBonusForCategory($structureConfig, $category);
            } else {
                // No rig category (e.g. Deployables) — apply base structure bonus only
                $baseMat = $this->bonusService->getBaseMaterialBonus($structureConfig, $isReaction);
                $materialBonus = ['total' => $baseMat, 'base' => $baseMat, 'rig' => 0.0];
                $timeBonus = $this->bonusService->getBaseTimeBonus($structureConfig, $isReaction);
            }

            return [
                'structure' => $structureConfig,
                'materialBonus' => $materialBonus,
                'timeBonus' => $timeBonus,
                'name' => $structureConfig->getName(),
                'favoriteSystemWithoutSuitableStructure' => false,
            ];
        }

        // No assigned structure — try favorite system first, then fallback to global best
        $user = $step->getProject()->getUser();
        $favoriteSystemId = $this->findFavoriteSystemId($user, $isReaction);

        if ($favoriteSystemId !== null) {
            $favoriteResult = $this->findBestInFavoriteSystem($user, $favoriteSystemId, $step->getProductTypeId(), $isReaction);
            if ($favoriteResult !== null) {
                return $favoriteResult;
            }
        }

        // Fallback: find the global best structure
        $bestMaterial = $this->bonusService->findBestStructureForProduct($user, $step->getProductTypeId(), $isReaction);

        $structure = $bestMaterial['structure'];
        $materialBonus = $bestMaterial['bonus'];

        $timeBonus = 0.0;
        if ($structure !== null) {
            if ($bestMaterial['category'] !== null) {
                $timeBonus = $this->bonusService->calculateStructureTimeBonusForCategory($structure, $bestMaterial['category']);
            } else {
                // No rig category — apply base structure time bonus only
                $timeBonus = $this->bonusService->getBaseTimeBonus($structure, $isReaction);
            }
        }

        return [
            'structure' => $structure,
            'materialBonus' => $materialBonus,
            'timeBonus' => $timeBonus,
            'name' => $structure?->getName(),
            'favoriteSystemWithoutSuitableStructure' => $favoriteSystemId !== null,
        ];
    }

    private function findFavoriteSystemId(User $user, bool $isReaction): ?int
    {
        $settings = $this->settingsRepository->findOneBy(['user' => $user]);
        if ($settings === null) {
            return null;
        }

        return $isReaction
            ? $settings->getFavoriteReactionSystemId()
            : $settings->getFavoriteManufacturingSystemId();
    }

    /**
     * Find the best structure able to run the activity in the user's favorite solar system.
     * Among equal bonuses, the most recently configured structure wins.
     *
     * @return array{structure: IndustryStructureConfig, materialBonus: array{total: float, base: float, rig: float}, timeBonus: float, name: string, favoriteSystemWithoutSuitableStructure: false}|null
     */
    private function findBestInFavoriteSystem(User $user, int $favoriteSystemId, int $productTypeId, bool $isReaction): ?array
    {
        $suitableInSystem = array_filter(
            $this->structureConfigRepository->findByUser($user),
            fn (IndustryStructureConfig $s) => $s->getSolarSystemId() === $favoriteSystemId
                && $this->bonusService->isSuitableForActivity($s, $isReaction),
        );
        usort(
            $suitableInSystem,
            static fn (IndustryStructureConfig $a, IndustryStructureConfig $b) => $b->getCreatedAt() <=> $a->getCreatedAt(),
        );

        $category = $this->bonusService->getCategoryForProduct($productTypeId, $isReaction);

        $bestStructure = null;
        $bestScore = null;
        foreach ($suitableInSystem as $structure) {
            // Without a rig category, only the base time bonus tells structures apart
            $score = $category !== null
                ? $this->bonusService->calculateStructureBonusForCategory($structure, $category)['total']
                : $this->bonusService->getBaseTimeBonus($structure, $isReaction);
            if ($bestScore === null || $score > $bestScore) {
                $bestScore = $score;
                $bestStructure = $structure;
            }
        }

        if ($bestStructure === null) {
            return null;
        }

        if ($category !== null) {
            $materialBonus = $this->bonusService->calculateStructureBonusForCategory($bestStructure, $category);
            $timeBonus = $this->bonusService->calculateStructureTimeBonusForCategory($bestStructure, $category);
        } else {
            $baseMat = $this->bonusService->getBaseMaterialBonus($bestStructure, $isReaction);
            $materialBonus = ['total' => $baseMat, 'base' => $baseMat, 'rig' => 0.0];
            $timeBonus = $this->bonusService->getBaseTimeBonus($bestStructure, $isReaction);
        }

        return [
            'structure' => $bestStructure,
            'materialBonus' => $materialBonus,
            'timeBonus' => $timeBonus,
            'name' => $bestStructure->getName(),
            'favoriteSystemWithoutSuitableStructure' => false,
        ];
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
        $baseTime = $this->getBaseTimePerRun($step->getBlueprintTypeId(), $step->getActivityType());
        if ($baseTime === null) {
            return null;
        }

        $teMultiplier = 1 - $step->getTeLevel() / 100;

        $structureData = $this->getStructureBonusForStep($step);
        $structureTimeMultiplier = 1 - $structureData['timeBonus'] / 100;

        $skillMultiplier = 1.0;
        if ($characterSkills !== null) {
            $scienceSkillIds = $this->getBlueprintScienceSkillIds($step->getBlueprintTypeId(), $step->getActivityType());
            $skillMultiplier = $this->calculateSkillTimeMultiplier($characterSkills, $step->getActivityType(), $scienceSkillIds);
        }

        return (int) ceil($baseTime * $teMultiplier * $structureTimeMultiplier * $skillMultiplier);
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

    /**
     * Get the base time per run from SDE for a blueprint activity.
     */
    private function getBaseTimePerRun(int $blueprintTypeId, string $activityType): ?int
    {
        $activityId = match ($activityType) {
            'reaction' => IndustryActivityType::Reaction->value,
            default => IndustryActivityType::Manufacturing->value,
        };

        $conn = $this->entityManager->getConnection();
        $time = $conn->fetchOne(
            'SELECT time FROM sde_industry_activities WHERE type_id = ? AND activity_id = ?',
            [$blueprintTypeId, $activityId],
        );

        return $time !== false ? (int) $time : null;
    }

    /**
     * Get the best structure bonus available for a product, across all user structures.
     *
     * @return array{name: string|null, materialBonus: array{total: float, base: float, rig: float}}
     */
    public function getBestStructureBonusForProduct(User $user, int $productTypeId, bool $isReaction): array
    {
        $bestData = $this->bonusService->findBestStructureForProduct($user, $productTypeId, $isReaction);

        return [
            'name' => $bestData['structure']?->getName(),
            'materialBonus' => $bestData['bonus'],
        ];
    }

    /**
     * Get the display name for a project (custom name or resolved product name).
     */
    public function getProjectDisplayName(IndustryProject $project): string
    {
        return $project->getName() ?? $this->resolveTypeName($project->getProductTypeId());
    }
}
