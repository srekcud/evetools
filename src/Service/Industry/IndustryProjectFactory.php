<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\IndustryBlueprintRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Creates industry projects and (re)generates their production steps.
 *
 * Responsible for building the step list from the production tree,
 * consolidating quantities, computing time data, and splitting long jobs.
 */
class IndustryProjectFactory
{
    private const SECONDS_PER_DAY = 86400;
    private const array NO_MATERIAL_BONUS = ['total' => 0.0, 'base' => 0.0, 'rig' => 0.0];

    public function __construct(
        private readonly IndustryTreeService $treeService,
        private readonly IndustryBlacklistService $blacklistService,
        private readonly IndustryBonusService $bonusService,
        private readonly IndustryCalculationService $calculationService,
        private readonly CachedCharacterSkillRepository $skillRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly IndustryActivityMaterialRepository $materialRepository,
        private readonly IndustryActivityRepository $activityRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurePublisherService $mercurePublisher,
        private readonly IndustryBlueprintRepository $blueprintRepository,
    ) {
    }

    /**
     * @param list<int> $excludedTypeIds
     */
    public function createProject(User $user, int $productTypeId, int $runs, int $meLevel, float $maxJobDurationDays = 2.0, int $teLevel = 0, ?string $name = null, array $excludedTypeIds = []): IndustryProject
    {
        $type = $this->invTypeRepository->find($productTypeId);
        if ($type === null) {
            throw new \InvalidArgumentException("Unknown type ID {$productTypeId}");
        }

        $project = new IndustryProject();
        $project->setUser($user);
        $project->setProductTypeId($productTypeId);
        $project->setName($name !== '' ? $name : null);
        $project->setRuns($runs);
        $project->setMeLevel($meLevel);
        $project->setTeLevel($teLevel);
        $project->setMaxJobDurationDays($maxJobDurationDays);
        $project->setExcludedTypeIds(array_values(array_unique($excludedTypeIds)));

        $tree = $this->productionTreeOf($project);

        $rawSteps = [];
        $this->collectStepsFromTree($rawSteps, $tree, $meLevel, $teLevel);
        $this->addTimeDataToSteps($rawSteps, $user);
        $rawSteps = $this->planStepsJobByJob($rawSteps, $user, $project->getMaxJobDurationDays(), [], []);

        $this->sortRawSteps($rawSteps);
        $this->applyPlanToExistingSteps($project, $rawSteps);

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    public function regenerateSteps(IndustryProject $project): void
    {
        $user = $project->getUser();
        $userId = $user->getId()?->toRfc4122();

        if ($userId !== null) {
            $this->mercurePublisher->syncStarted($userId, 'industry-project', 'Regenerating steps...');
        }

        try {
            $settingsOfExistingSteps = $this->settingsOfExistingStepsByKeyInSplitIndexOrder($project);
            $tree = $this->productionTreeOf($project);

            $rawSteps = [];
            $this->collectStepsFromTree($rawSteps, $tree, $project->getMeLevel(), $project->getTeLevel());
            $rawSteps = array_map(fn (array $rawStep): array => $this->withSettingsOfExistingStep($rawStep, $settingsOfExistingSteps), $rawSteps);
            $this->addTimeDataToSteps($rawSteps, $user);
            $rawSteps = $this->planStepsJobByJob($rawSteps, $user, $project->getMaxJobDurationDays(), $settingsOfExistingSteps, $this->existingJobsWithFixedRunsByKey($project));

            $this->sortRawSteps($rawSteps);
            $this->applyPlanToExistingSteps($project, $rawSteps);

            $this->entityManager->flush();

            if ($userId !== null) {
                $this->mercurePublisher->syncCompleted($userId, 'industry-project', 'Steps regenerated', [
                    'projectId' => $project->getId()?->toRfc4122() ?? '',
                    'stepsCount' => count($rawSteps),
                ]);
            }
        } catch (\Throwable $e) {
            if ($userId !== null) {
                $this->mercurePublisher->syncError($userId, 'industry-project', $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * @return array<string, list<array{meLevel: int, teLevel: int, structureConfig: IndustryStructureConfig|null}>>
     */
    private function settingsOfExistingStepsByKeyInSplitIndexOrder(IndustryProject $project): array
    {
        return array_map(
            static fn (array $steps): array => array_map(
                static fn (IndustryProjectStep $step): array => [
                    'meLevel' => $step->getMeLevel(),
                    'teLevel' => $step->getTeLevel(),
                    'structureConfig' => $step->getStructureConfig(),
                ],
                $steps,
            ),
            $this->existingStepsByKeyInSplitIndexOrder($project),
        );
    }

    /**
     * @param array<string, mixed> $plannedStep
     * @param array<string, list<array{meLevel: int, teLevel: int, structureConfig: IndustryStructureConfig|null}>> $settingsOfExistingSteps
     * @return array<string, mixed>
     */
    private function withSettingsOfExistingStep(array $plannedStep, array $settingsOfExistingSteps): array
    {
        $settingsBySplitIndex = $settingsOfExistingSteps[self::stepKey($plannedStep['blueprintTypeId'], $plannedStep['activityType'])] ?? [];
        $settings = $settingsBySplitIndex[$plannedStep['splitIndex'] ?? 0] ?? $settingsBySplitIndex[0] ?? null;
        if ($settings === null) {
            return $plannedStep;
        }

        $plannedStep['structureConfig'] = $settings['structureConfig'];
        if ($plannedStep['activityType'] === 'manufacturing' && $plannedStep['depth'] !== 0) {
            $plannedStep['meLevel'] = $settings['meLevel'];
            $plannedStep['teLevel'] = $settings['teLevel'];
        }

        return $plannedStep;
    }

    /**
     * @return array<string, mixed>
     */
    private function productionTreeOf(IndustryProject $project): array
    {
        $user = $project->getUser();

        return $this->treeService->buildProductionTree(
            $project->getProductTypeId(),
            $project->getRuns(),
            $project->getMeLevel(),
            $project->excludedTypeIdsWithBlacklist($this->blacklistService->resolveBlacklistedTypeIds($user)),
            $user,
        );
    }

    /**
     * @param list<array<string, mixed>> $rawSteps
     */
    private function sortRawSteps(array &$rawSteps): void
    {
        $activityOrder = ['reaction' => 0, 'copy' => 1, 'manufacturing' => 2];
        usort($rawSteps, function (array $a, array $b) use ($activityOrder) {
            if ($a['depth'] !== $b['depth']) {
                return $b['depth'] <=> $a['depth'];
            }
            $aOrder = $activityOrder[$a['activityType']] ?? 99;
            $bOrder = $activityOrder[$b['activityType']] ?? 99;
            if ($aOrder !== $bOrder) {
                return $aOrder <=> $bOrder;
            }
            $nameComparison = strcasecmp($a['productTypeName'], $b['productTypeName']);
            if ($nameComparison !== 0) {
                return $nameComparison;
            }
            return ($a['splitIndex'] ?? 0) <=> ($b['splitIndex'] ?? 0);
        });
    }

    /**
     * @param array<string, mixed> $plannedStep
     */
    private function applyPlannedStep(IndustryProject $project, IndustryProjectStep $step, array $plannedStep, int $sortOrder): void
    {
        $step->setBlueprintTypeId($plannedStep['blueprintTypeId']);
        $step->setProductTypeId($plannedStep['productTypeId']);
        $step->setQuantity($plannedStep['quantity']);
        $step->setRuns($plannedStep['runs']);
        $step->setDepth($plannedStep['depth']);
        $step->setActivityType($plannedStep['activityType']);
        $step->setSortOrder($sortOrder);
        $step->setSplitGroupId($plannedStep['splitGroupId'] ?? null);
        $step->setSplitIndex($plannedStep['splitIndex'] ?? 0);
        $step->setTotalGroupRuns($plannedStep['totalGroupRuns'] ?? null);
        $step->setStructureConfig($plannedStep['structureConfig']);

        $step->setMeLevel($plannedStep['meLevel'] ?? ($plannedStep['depth'] === 0 ? $project->getMeLevel() : 10));
        $step->setTeLevel($plannedStep['teLevel'] ?? ($plannedStep['depth'] === 0 ? $project->getTeLevel() : 20));
    }

    /**
     * @param list<array<string, mixed>> $rawSteps
     */
    private function applyPlanToExistingSteps(IndustryProject $project, array $rawSteps): void
    {
        $existingStepsByKey = $this->existingStepsByKeyInSplitIndexOrder($project);
        $lastKeptStepByKey = [];

        foreach ($rawSteps as $sortOrder => $plannedStep) {
            $key = self::stepKey($plannedStep['blueprintTypeId'], $plannedStep['activityType']);
            $existingStepsByKey[$key] ??= [];
            $step = array_shift($existingStepsByKey[$key]) ?? new IndustryProjectStep();
            $this->applyPlannedStep($project, $step, $plannedStep, $sortOrder);
            $project->addStep($step);
            $lastKeptStepByKey[$key] = $step;
        }

        $stepsOutOfPlan = [];
        foreach ($existingStepsByKey as $key => $remainingSteps) {
            foreach ($remainingSteps as $remainingStep) {
                if (isset($lastKeptStepByKey[$key])) {
                    $lastKeptStepByKey[$key]->absorb($remainingStep);
                    $project->getSteps()->removeElement($remainingStep);
                    $this->entityManager->remove($remainingStep);
                } else {
                    $stepsOutOfPlan[] = $remainingStep;
                }
            }
        }

        usort($stepsOutOfPlan, static fn (IndustryProjectStep $a, IndustryProjectStep $b): int => $a->getSortOrder() <=> $b->getSortOrder());
        foreach ($stepsOutOfPlan as $index => $stepOutOfPlan) {
            $stepOutOfPlan->setSortOrder(count($rawSteps) + $index);
        }
    }

    /**
     * @return array<string, list<IndustryProjectStep>>
     */
    private function existingStepsByKeyInSplitIndexOrder(IndustryProject $project): array
    {
        $existingStepsByKey = [];
        foreach ($project->getSteps() as $step) {
            $existingStepsByKey[self::stepKey($step->getBlueprintTypeId(), $step->getActivityType())][] = $step;
        }

        foreach ($existingStepsByKey as &$steps) {
            usort($steps, static fn (IndustryProjectStep $a, IndustryProjectStep $b): int => $a->getSplitIndex() <=> $b->getSplitIndex());
        }
        unset($steps);

        return $existingStepsByKey;
    }

    private static function stepKey(int $blueprintTypeId, string $activityType): string
    {
        return $blueprintTypeId . '_' . $activityType;
    }

    /**
     * Flatten the tree into an array of step data.
     * Steps are consolidated by (blueprintTypeId, activityType).
     *
     * @param array<string, array<string, mixed>> $steps
     * @param array<string, mixed> $node
     */
    private function collectStepsFromTree(array &$steps, array $node, int $rootMeLevel = 0, int $rootTeLevel = 0): void
    {
        if (!empty($node['hasCopy'])) {
            $copyKey = self::stepKey($node['blueprintTypeId'], 'copy');
            if (isset($steps[$copyKey])) {
                $steps[$copyKey]['quantity'] += $node['runs'];
                $steps[$copyKey]['runs'] = $steps[$copyKey]['quantity'];
                $steps[$copyKey]['planningLevel'] = max($steps[$copyKey]['planningLevel'], $node['depth']);
            } else {
                $steps[$copyKey] = [
                    'blueprintTypeId' => $node['blueprintTypeId'],
                    'productTypeId' => $node['productTypeId'],
                    'productTypeName' => $node['productTypeName'] . ' (BPC)',
                    'quantity' => $node['runs'],
                    'runs' => $node['runs'],
                    'outputPerRun' => 1,
                    'depth' => $node['depth'],
                    'activityType' => 'copy',
                    'meLevel' => 0,
                    'teLevel' => 0,
                    'structureConfig' => null,
                    'planningLevel' => $node['depth'],
                ];
            }
        }

        $activityType = $node['activityType'];
        $key = self::stepKey($node['blueprintTypeId'], $activityType);
        $outputPerRun = $node['outputPerRun'] ?? 1;

        // Determine ME/TE for this step based on depth and activity type
        $depth = $node['depth'];
        if ($activityType === 'reaction') {
            // Reactions have no ME/TE research
            $stepMeLevel = 0;
            $stepTeLevel = 0;
        } elseif ($depth === 0) {
            // Root blueprint: use user-specified values
            $stepMeLevel = $rootMeLevel;
            $stepTeLevel = $rootTeLevel;
        } else {
            // Intermediate components: standard EVE defaults (ME 10 / TE 20)
            $stepMeLevel = 10;
            $stepTeLevel = 20;
        }

        if (isset($steps[$key])) {
            $steps[$key]['quantity'] += $node['quantity'];
            $steps[$key]['runs'] = (int) ceil($steps[$key]['quantity'] / $steps[$key]['outputPerRun']);
            $steps[$key]['planningLevel'] = max($steps[$key]['planningLevel'], $depth);
        } else {
            $steps[$key] = [
                'blueprintTypeId' => $node['blueprintTypeId'],
                'productTypeId' => $node['productTypeId'],
                'productTypeName' => $node['productTypeName'],
                'quantity' => $node['quantity'],
                'runs' => $node['runs'],
                'outputPerRun' => $outputPerRun,
                'depth' => $node['depth'],
                'activityType' => $activityType,
                'meLevel' => $stepMeLevel,
                'teLevel' => $stepTeLevel,
                'structureConfig' => null,
                'planningLevel' => $depth,
            ];
        }

        foreach ($node['materials'] as $material) {
            if (($material['isBuildable'] ?? false) && isset($material['blueprint'])) {
                $this->collectStepsFromTree($steps, $material['blueprint'], $rootMeLevel, $rootTeLevel);
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $steps
     */
    private function addTimeDataToSteps(array &$steps, User $user): void
    {
        // Preload all skills for all characters (one query per character)
        $characterSkillLevels = $this->loadAllCharacterSkills($user);

        // Preload all activities in one batch query
        $allTypeIds = [];
        $allActivityIds = [];
        foreach ($steps as $step) {
            $activityId = match ($step['activityType']) {
                'reaction' => IndustryActivityType::Reaction->value,
                'copy' => IndustryActivityType::Copying->value,
                default => IndustryActivityType::Manufacturing->value,
            };
            $allTypeIds[] = $step['blueprintTypeId'];
            $allActivityIds[] = $activityId;
        }
        $activitiesByKey = $this->activityRepository->findByTypeIdsAndActivityIds($allTypeIds, $allActivityIds);

        foreach ($steps as &$step) {
            $activityId = match ($step['activityType']) {
                'reaction' => IndustryActivityType::Reaction->value,
                'copy' => IndustryActivityType::Copying->value,
                default => IndustryActivityType::Manufacturing->value,
            };

            $activityKey = $step['blueprintTypeId'] . '-' . $activityId;
            $activity = $activitiesByKey[$activityKey] ?? null;

            $baseTimePerRun = $activity?->getTime();
            $step['baseTimePerRun'] = $baseTimePerRun;

            if ($baseTimePerRun === null) {
                $step['timePerRun'] = null;
                continue;
            }

            $isReaction = $step['activityType'] === 'reaction';
            $structureTimeBonus = $step['structureConfig'] === null
                ? $this->bonusService->findBestStructureForProductTimeBonus($user, $step['productTypeId'], $isReaction)['bonus']
                : $this->calculationService->assignedStructureBonusOf($step['structureConfig'], $step['productTypeId'], $isReaction)['timeBonus'];

            $adjustedTimePerRun = $this->bonusService->calculateAdjustedTimePerRun(
                $baseTimePerRun,
                $step['teLevel'],
                $structureTimeBonus
            );

            // Find the best character's skill multiplier for this specific blueprint
            $skillMultiplier = $this->findBestSkillMultiplierForBlueprint(
                $characterSkillLevels,
                $step['blueprintTypeId'],
                $step['activityType'],
            );
            $adjustedTimePerRun = (int) ceil($adjustedTimePerRun * $skillMultiplier);

            $step['timePerRun'] = $adjustedTimePerRun;
        }
        unset($step);
    }

    /**
     * Load all cached skills for all user's characters.
     *
     * @return array<string, array<int, int>> characterName => [skillId => level]
     */
    private function loadAllCharacterSkills(User $user): array
    {
        $result = [];
        foreach ($user->getCharacters() as $character) {
            $charSkills = $this->skillRepository->findAllSkillsForCharacter($character);
            $levels = [];
            foreach ($charSkills as $skill) {
                $levels[$skill->getSkillId()] = $skill->getLevel();
            }
            $result[$character->getName()] = $levels;
        }
        return $result;
    }

    /**
     * Find the best (lowest) skill time multiplier for a specific blueprint
     * across all characters, including blueprint-specific science skills.
     *
     * @param array<string, array<int, int>> $characterSkillLevels
     */
    private function findBestSkillMultiplierForBlueprint(
        array $characterSkillLevels,
        int $blueprintTypeId,
        string $activityType,
    ): float {
        $scienceSkillIds = $this->calculationService->getBlueprintScienceSkillIds($blueprintTypeId, $activityType);

        $bestMultiplier = 1.0;

        foreach ($characterSkillLevels as $skillLevels) {
            $multiplier = $this->calculationService->calculateSkillTimeMultiplier($skillLevels, $activityType, $scienceSkillIds);

            if ($multiplier < $bestMultiplier) {
                $bestMultiplier = $multiplier;
            }
        }

        return $bestMultiplier;
    }

    /**
     * @param array<string, array<string, mixed>> $steps
     * @param array<string, list<array{meLevel: int, teLevel: int, structureConfig: IndustryStructureConfig|null}>> $settingsOfExistingSteps
     * @param array<string, list<IndustryProjectStep>> $existingJobsWithFixedRunsByKey
     * @return list<array<string, mixed>>
     */
    private function planStepsJobByJob(array $steps, User $user, float $maxJobDurationDays, array $settingsOfExistingSteps, array $existingJobsWithFixedRunsByKey): array
    {
        $activityTypeByProduct = [];
        foreach ($steps as $step) {
            if ($step['activityType'] !== 'copy') {
                $activityTypeByProduct[$step['productTypeId']] = $step['activityType'];
            }
        }
        $materialsByKey = $this->materialsOfSteps($steps);
        $blueprintTypeIdsWithCopyStep = array_column(
            array_filter($steps, static fn (array $step): bool => $step['activityType'] === 'copy'),
            'blueprintTypeId',
        );

        uasort($steps, static fn (array $a, array $b): int => $a['planningLevel'] <=> $b['planningLevel']);

        $needByProduct = array_fill_keys(array_keys($activityTypeByProduct), 0);
        $plannedSteps = [];
        foreach ($steps as $step) {
            if ($step['activityType'] === 'copy') {
                $plannedSteps[] = $step;
                continue;
            }

            if ($needByProduct[$step['productTypeId']] !== 0) {
                $step['quantity'] = $needByProduct[$step['productTypeId']];
                $step['runs'] = (int) ceil($step['quantity'] / $step['outputPerRun']);
            }

            $existingJobsWithFixedRuns = $existingJobsWithFixedRunsByKey[self::stepKey($step['blueprintTypeId'], $step['activityType'])] ?? null;
            $plannedJobs = $existingJobsWithFixedRuns === null
                ? $this->jobsOf($step, $maxJobDurationDays, $this->bpcMaxRunsOf($step, $blueprintTypeIdsWithCopyStep))
                : self::jobsKeepingFixedRuns($step, $existingJobsWithFixedRuns);
            $jobs = array_map(fn (array $job): array => $this->withSettingsOfExistingStep($job, $settingsOfExistingSteps), $plannedJobs);
            $materials = $materialsByKey[$step['blueprintTypeId'] . '-' . SplitGroupMaterials::activityIdOf($step['activityType'])] ?? [];
            foreach ($jobs as $job) {
                $this->addNeedOfJobs($needByProduct, $user, $job, [$job['runs']], $materials, $activityTypeByProduct);
            }

            array_push($plannedSteps, ...$jobs);
        }

        return $plannedSteps;
    }

    /**
     * @param array<string, array<string, mixed>> $steps
     * @return array<string, list<IndustryActivityMaterial>>
     */
    private function materialsOfSteps(array $steps): array
    {
        $blueprintTypeIds = [];
        $activityIds = [];
        foreach ($steps as $step) {
            if ($step['activityType'] === 'copy') {
                continue;
            }
            $blueprintTypeIds[] = $step['blueprintTypeId'];
            $activityIds[] = SplitGroupMaterials::activityIdOf($step['activityType']);
        }

        return $this->materialRepository->findMaterialEntitiesForBlueprints($blueprintTypeIds, $activityIds);
    }

    /**
     * @param array<int, int> $needByProduct
     * @param array<string, mixed> $consumer
     * @param list<int> $jobRuns
     * @param list<IndustryActivityMaterial> $materials
     * @param array<int, string> $activityTypeByProduct
     */
    private function addNeedOfJobs(array &$needByProduct, User $user, array $consumer, array $jobRuns, array $materials, array $activityTypeByProduct): void
    {
        foreach ($materials as $material) {
            $materialTypeId = $material->getMaterialTypeId();
            if (!isset($activityTypeByProduct[$materialTypeId])) {
                continue;
            }
            $materialBonus = $this->materialBonusOf($user, $consumer, $materialTypeId, $activityTypeByProduct[$materialTypeId] === 'reaction');
            $needByProduct[$materialTypeId] += SplitGroupMaterials::materialsOfJobs($this->calculationService, $material->getQuantity(), $jobRuns, $consumer['meLevel'], $materialBonus);
        }
    }

    /**
     * @param array<string, mixed> $consumer
     * @return array{total: float, base: float, rig: float}
     */
    private function materialBonusOf(User $user, array $consumer, int $materialTypeId, bool $materialIsReaction): array
    {
        if ($consumer['structureConfig'] !== null) {
            return $this->calculationService->assignedStructureBonusOf($consumer['structureConfig'], $consumer['productTypeId'], $consumer['activityType'] === 'reaction')['materialBonus'];
        }

        if ($materialIsReaction) {
            return $this->reactionMaterialBonusOf($user, $consumer);
        }

        $consumerIsReaction = $consumer['activityType'] === 'reaction';
        $consumerStructure = $this->bonusService->findBestStructureForProduct($user, $consumer['productTypeId'], $consumerIsReaction);
        $materialCategory = $this->bonusService->getCategoryForProduct($materialTypeId, false);
        if ($materialCategory !== null && $materialCategory !== $consumerStructure['category']) {
            return $this->bonusService->findBestStructureForCategory($user, $materialCategory, false)['bonus'];
        }

        return $consumerStructure['bonus'];
    }

    /**
     * @param array<string, mixed> $consumer
     * @return array{total: float, base: float, rig: float}
     */
    private function reactionMaterialBonusOf(User $user, array $consumer): array
    {
        $consumerIsReaction = $consumer['activityType'] === 'reaction';
        $consumerCategory = $this->bonusService->getCategoryForProduct($consumer['productTypeId'], $consumerIsReaction);

        return $consumerCategory === null
            ? self::NO_MATERIAL_BONUS
            : $this->bonusService->findBestStructureForCategory($user, $consumerCategory, $consumerIsReaction)['bonus'];
    }

    /**
     * @return array<string, list<IndustryProjectStep>>
     */
    private function existingJobsWithFixedRunsByKey(IndustryProject $project): array
    {
        return array_filter(
            $this->existingStepsByKeyInSplitIndexOrder($project),
            static fn (array $jobs): bool => array_filter($jobs, static fn (IndustryProjectStep $job): bool => $job->isRunsFixed()) !== [],
        );
    }

    /**
     * @param array<string, mixed> $step
     * @param list<IndustryProjectStep> $existingJobs
     * @return list<array<string, mixed>>
     */
    private static function jobsKeepingFixedRuns(array $step, array $existingJobs): array
    {
        SplitGroupQuantities::applyNeedToSteps($existingJobs, $step['quantity'], $step['outputPerRun']);

        return array_map(static fn (IndustryProjectStep $existingJob): array => [
            ...$step,
            'runs' => $existingJob->getRuns(),
            'quantity' => $existingJob->getQuantity(),
            'splitGroupId' => $existingJob->getSplitGroupId(),
            'splitIndex' => $existingJob->getSplitIndex(),
            'totalGroupRuns' => $existingJob->getTotalGroupRuns(),
        ], $existingJobs);
    }

    /**
     * @param array<string, mixed> $step
     * @param list<int> $blueprintTypeIdsWithCopyStep
     */
    private function bpcMaxRunsOf(array $step, array $blueprintTypeIdsWithCopyStep): ?int
    {
        if ($step['activityType'] !== 'manufacturing' || !in_array($step['blueprintTypeId'], $blueprintTypeIdsWithCopyStep, true)) {
            return null;
        }

        $industryBlueprint = $this->blueprintRepository->findBy(['typeId' => $step['blueprintTypeId']])[0] ?? null;
        if ($industryBlueprint === null) {
            throw new \RuntimeException("No industry blueprint in the SDE for blueprint {$step['blueprintTypeId']}: its BPC max runs is unknown");
        }

        $bpcMaxRuns = $industryBlueprint->getMaxProductionLimit();
        if ($bpcMaxRuns <= 0) {
            throw new \RuntimeException("Invalid max production limit {$bpcMaxRuns} in the SDE for blueprint {$step['blueprintTypeId']}");
        }

        return $bpcMaxRuns;
    }

    /**
     * @param array<string, mixed> $step
     */
    private static function maxRunsPerJob(array $step, float $maxJobDurationDays, ?int $bpcMaxRuns): ?int
    {
        $timePerRun = $step['timePerRun'];
        $maxDurationSeconds = $maxJobDurationDays * self::SECONDS_PER_DAY;
        $limits = $bpcMaxRuns === null ? [] : [$bpcMaxRuns];

        if ($timePerRun !== null && $timePerRun * $step['runs'] > $maxDurationSeconds) {
            $limits[] = max(1, (int) floor($maxDurationSeconds / $timePerRun));
        }

        return $limits === [] ? null : min($limits);
    }

    /**
     * @param array<string, mixed> $step
     * @return list<array<string, mixed>>
     */
    private function jobsOf(array $step, float $maxJobDurationDays, ?int $bpcMaxRuns): array
    {
        $maxRunsPerJob = self::maxRunsPerJob($step, $maxJobDurationDays, $bpcMaxRuns);
        $totalRuns = $step['runs'];

        if ($maxRunsPerJob === null) {
            return [$step];
        }

        $numJobs = (int) ceil($totalRuns / $maxRunsPerJob);

        if ($numJobs < 2) {
            return [$step];
        }

        $splitGroupId = Uuid::v4()->toRfc4122();
        $runsBySplitIndex = SplitGroupQuantities::runsBySplitIndex($totalRuns, $numJobs);
        $quantitiesBySplitIndex = SplitGroupQuantities::share($step['quantity'], $step['outputPerRun'], $runsBySplitIndex);

        $jobs = [];
        foreach ($runsBySplitIndex as $splitIndex => $runsForThisJob) {
            $job = $step;
            $job['runs'] = $runsForThisJob;
            $job['quantity'] = $quantitiesBySplitIndex[$splitIndex];
            $job['splitGroupId'] = $splitGroupId;
            $job['splitIndex'] = $splitIndex;
            $job['totalGroupRuns'] = $totalRuns;

            $jobs[] = $job;
        }

        return $jobs;
    }
}
