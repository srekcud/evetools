<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Industry\Application\StructureSelectionPolicy;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Isk;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\StaStationRepository;
use App\Service\TypeNameResolver;

class IndustryCalculationService
{
    public function __construct(
        private readonly TypeNameResolver $typeNameResolver,
        private readonly IndustryBonusService $bonusService,
        private readonly IndustryStructureConfigRepository $structureConfigRepository,
        private readonly IndustryUserSettingsRepository $settingsRepository,
        private readonly StaStationRepository $staStationRepository,
        private readonly CachedStructureRepository $cachedStructureRepository,
        private readonly ?StructureChoiceByTotalCost $structureChoiceByTotalCost = null,
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

    public function resolveTypeName(int $typeId): ?string
    {
        return $this->typeNameResolver->findName($typeId);
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
        return MaterialQuantityFormula::forJob($baseQty, $runs, $meLevel, $structureBaseBonus, $rigBonus);
    }

    /**
     * @return array{materialBonus: array{total: float, base: float, rig: float}, timeBonus: float}
     */
    public function assignedStructureBonusOf(IndustryStructureConfig $structureConfig, int $productTypeId, bool $isReaction): array
    {
        $category = $this->bonusService->getCategoryForProduct($productTypeId, $isReaction);
        if ($category) {
            return [
                'materialBonus' => $this->bonusService->calculateStructureBonusForCategory($structureConfig, $category),
                'timeBonus' => $this->bonusService->calculateStructureTimeBonusForCategory($structureConfig, $category),
            ];
        }

        $baseMaterialBonus = $this->bonusService->getBaseMaterialBonus($structureConfig, $isReaction);

        return [
            'materialBonus' => ['total' => $baseMaterialBonus, 'base' => $baseMaterialBonus, 'rig' => 0.0],
            'timeBonus' => $this->bonusService->getBaseTimeBonus($structureConfig, $isReaction),
        ];
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
        return $this->structureBonusOf(
            $step->getProject()->getUser(),
            $step->getProductTypeId(),
            $step->getActivityType(),
            $step->getStructureConfig(),
            self::selectionPolicyOf($step->getProject()),
            self::referenceRunsOf($step),
        );
    }

    public static function referenceRunsOf(IndustryProjectStep $step): ?int
    {
        return $step->getProject()->isAcceptsOutsideFavoriteSystem() ? $step->getRuns() : null;
    }

    public static function selectionPolicyOf(IndustryProject $project): StructureSelectionPolicy
    {
        return new StructureSelectionPolicy($project->isAcceptsOutsideFavoriteSystem(), new Isk($project->getMinimumOutsideSaving()));
    }

    /**
     * @return array{structure: IndustryStructureConfig|null, materialBonus: array{total: float, base: float, rig: float}, timeBonus: float, name: string|null, favoriteSystemWithoutSuitableStructure: bool}
     */
    public function structureBonusOf(User $user, int $productTypeId, string $activityType, ?IndustryStructureConfig $structureConfig, ?StructureSelectionPolicy $selectionPolicy = null, ?int $referenceRuns = null): array
    {
        $isReaction = $activityType === 'reaction';

        if ($structureConfig !== null) {
            $assignedStructureBonus = $this->assignedStructureBonusOf($structureConfig, $productTypeId, $isReaction);

            return [
                'structure' => $structureConfig,
                'materialBonus' => $assignedStructureBonus['materialBonus'],
                'timeBonus' => $assignedStructureBonus['timeBonus'],
                'name' => $structureConfig->getName(),
                'favoriteSystemWithoutSuitableStructure' => false,
            ];
        }

        if ($selectionPolicy?->acceptsOutsideFavoriteSystem === true) {
            $structureChosenByTotalCost = $this->structureChosenByTotalCost($user, $productTypeId, $activityType, $selectionPolicy, $referenceRuns);
            if ($structureChosenByTotalCost !== null) {
                $chosenStructureBonus = $this->assignedStructureBonusOf($structureChosenByTotalCost, $productTypeId, $isReaction);

                return [
                    'structure' => $structureChosenByTotalCost,
                    'materialBonus' => $chosenStructureBonus['materialBonus'],
                    'timeBonus' => $chosenStructureBonus['timeBonus'],
                    'name' => $structureChosenByTotalCost->getName(),
                    'favoriteSystemWithoutSuitableStructure' => false,
                ];
            }
        }

        // No assigned structure — try favorite system first, then fallback to global best
        $favoriteSystemId = $this->findFavoriteSystemId($user, $isReaction);

        if ($favoriteSystemId !== null) {
            $favoriteResult = $this->findBestInFavoriteSystem($user, $favoriteSystemId, $productTypeId, $isReaction);
            if ($favoriteResult !== null) {
                return $favoriteResult;
            }
        }

        // Fallback: find the global best structure
        $bestMaterial = $this->bonusService->findBestStructureForProduct($user, $productTypeId, $isReaction);

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

    private function structureChosenByTotalCost(User $user, int $productTypeId, string $activityType, StructureSelectionPolicy $selectionPolicy, ?int $referenceRuns): ?IndustryStructureConfig
    {
        $activity = match ($activityType) {
            'manufacturing' => ActivityKind::Manufacturing,
            'reaction' => ActivityKind::Reaction,
            default => null,
        };
        if ($activity === null) {
            return null;
        }
        $chooser = $this->structureChoiceByTotalCost
            ?? throw new \LogicException('A selection policy accepting structures outside the favorite system needs the structure choice by total cost.');
        $runs = $referenceRuns ?? throw new \LogicException('A selection policy accepting structures outside the favorite system needs the reference runs of the job.');

        $structureId = $chooser->structureIdChosenFor($user, $productTypeId, $activity, $selectionPolicy, $runs);
        if ($structureId === null) {
            return null;
        }

        return array_find(
            $this->structureConfigRepository->findByUser($user),
            static fn (IndustryStructureConfig $structure): bool => $structure->getId()->toRfc4122() === $structureId,
        ) ?? throw new \LogicException(\sprintf('The structure %s chosen by total cost is not configured by the user.', $structureId));
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
    public function getProjectDisplayName(IndustryProject $project): ?string
    {
        return $project->getName() ?? $this->resolveTypeName($project->getProductTypeId());
    }
}
