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
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\StaStationRepository;
use Doctrine\ORM\EntityManagerInterface;

class IndustryCalculationService
{
    private const ACTIVITY_MANUFACTURING = 1;
    private const ACTIVITY_REACTION = 11;

    /** @var array<string, int[]> Cache of blueprint science skill IDs */
    private array $blueprintSkillCache = [];

    public function __construct(
        private readonly InvTypeRepository $invTypeRepository,
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

        // Player structures
        $structure = $this->cachedStructureRepository->findByStructureId($stationId);
        return $structure?->getName();
    }

    public function resolveTypeName(int $typeId): string
    {
        $type = $this->invTypeRepository->find($typeId);
        return $type?->getTypeName() ?? "Type #{$typeId}";
    }

    /**
     * Calculate adjusted material quantity using exact EVE formula.
     *
     * Formula: max(runs, ceil(round(baseQty * runs * ((100-ME)/100) * EC_modifier * rig_modifier, 2)))
     *
     * @param int $baseQty Base material quantity per run from SDE
     * @param int $runs Number of runs
     * @param int $meLevel Material Efficiency level (0-10)
     * @param float $structureBonus Total structure ME bonus percentage (base + rigs)
     */
    public function calculateMaterialQuantity(int $baseQty, int $runs, int $meLevel, float $structureBonus = 0.0): int
    {
        $meMultiplier = (100 - $meLevel) / 100;
        $structureMultiplier = $structureBonus > 0 ? (1 - $structureBonus / 100) : 1.0;

        return max(
            $runs,
            (int) ceil(round($baseQty * $runs * $meMultiplier * $structureMultiplier, 2))
        );
    }

    /**
     * Get the structure bonus for a step, using the step's assigned structure or finding the best one.
     *
     * @return array{structure: IndustryStructureConfig|null, materialBonus: float, timeBonus: float, name: string|null}
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
                $materialBonus = $this->bonusService->getBaseMaterialBonus($structureConfig, $isReaction);
                $timeBonus = $this->bonusService->getBaseTimeBonus($structureConfig, $isReaction);
            }

            return [
                'structure' => $structureConfig,
                'materialBonus' => $materialBonus,
                'timeBonus' => $timeBonus,
                'name' => $structureConfig->getName(),
            ];
        }

        // No assigned structure — try favorite system first, then fallback to global best
        $user = $step->getProject()->getUser();
        $favoriteResult = $this->findBestInFavoriteSystem($user, $step->getProductTypeId(), $isReaction);

        if ($favoriteResult !== null) {
            return $favoriteResult;
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
        ];
    }

    /**
     * Find the best structure in the user's favorite solar system for the given activity.
     *
     * @return array{structure: IndustryStructureConfig, materialBonus: float, timeBonus: float, name: string}|null
     */
    private function findBestInFavoriteSystem(User $user, int $productTypeId, bool $isReaction): ?array
    {
        $settings = $this->settingsRepository->findOneBy(['user' => $user]);
        if ($settings === null) {
            return null;
        }

        $favoriteSystemId = $isReaction
            ? $settings->getFavoriteReactionSystemId()
            : $settings->getFavoriteManufacturingSystemId();

        if ($favoriteSystemId === null) {
            return null;
        }

        // Get all user structures in the favorite system
        $structures = $this->structureConfigRepository->findByUser($user);
        $inSystem = array_filter(
            $structures,
            fn (IndustryStructureConfig $s) => $s->getSolarSystemId() === $favoriteSystemId,
        );

        if (empty($inSystem)) {
            return null;
        }

        // Find the best bonus among structures in the favorite system
        $category = $this->bonusService->getCategoryForProduct($productTypeId, $isReaction);

        $bestStructure = null;
        $bestBonus = -1.0;

        if ($category !== null) {
            foreach ($inSystem as $structure) {
                $bonus = $this->bonusService->calculateStructureBonusForCategory($structure, $category);
                if ($bonus > $bestBonus) {
                    $bestBonus = $bonus;
                    $bestStructure = $structure;
                }
            }
        } else {
            // No rig category — pick the structure with the best base time bonus
            $bestBaseTime = 0.0;
            foreach ($inSystem as $structure) {
                $baseTime = $this->bonusService->getBaseTimeBonus($structure, $isReaction);
                if ($baseTime > $bestBaseTime) {
                    $bestBaseTime = $baseTime;
                    $bestStructure = $structure;
                }
            }
            $bestBonus = $bestStructure ? $this->bonusService->getBaseMaterialBonus($bestStructure, $isReaction) : 0.0;
        }

        if ($bestStructure === null) {
            return null;
        }

        $timeBonus = $category !== null
            ? $this->bonusService->calculateStructureTimeBonusForCategory($bestStructure, $category)
            : $this->bonusService->getBaseTimeBonus($bestStructure, $isReaction);

        return [
            'structure' => $bestStructure,
            'materialBonus' => $bestBonus,
            'timeBonus' => $timeBonus,
            'name' => $bestStructure->getName(),
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

        // Skill multipliers
        $skillMultiplier = 1.0;
        if ($characterSkills !== null) {
            if ($step->getActivityType() === 'reaction') {
                $reactionLevel = $characterSkills[CachedCharacterSkill::SKILL_REACTIONS] ?? 0;
                $skillMultiplier *= (1 - 0.04 * $reactionLevel);
            } else {
                $industryLevel = $characterSkills[CachedCharacterSkill::SKILL_INDUSTRY] ?? 0;
                $advancedLevel = $characterSkills[CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY] ?? 0;
                $skillMultiplier *= (1 - 0.04 * $industryLevel);
                $skillMultiplier *= (1 - 0.03 * $advancedLevel);
            }

            // Blueprint-specific science skills (1% per level)
            $scienceSkillIds = $this->getBlueprintScienceSkillIds($step->getBlueprintTypeId(), $step->getActivityType());
            foreach ($scienceSkillIds as $skillId) {
                $level = $characterSkills[$skillId] ?? 0;
                if ($level > 0) {
                    $skillMultiplier *= (1 - 0.01 * $level);
                }
            }
        }

        return (int) ceil($baseTime * $teMultiplier * $structureTimeMultiplier * $skillMultiplier);
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
            'reaction' => self::ACTIVITY_REACTION,
            default => self::ACTIVITY_MANUFACTURING,
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
            'reaction' => self::ACTIVITY_REACTION,
            default => self::ACTIVITY_MANUFACTURING,
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
     * @return array{name: string|null, materialBonus: float}
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
