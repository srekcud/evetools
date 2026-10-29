<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\User;
use App\Industry\Application\InventionCatalog;
use App\Repository\Sde\IndustryActivityMaterialRepository;
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

    public function __construct(
        private readonly IndustryTreeService $treeService,
        private readonly IndustryBlacklistService $blacklistService,
        private readonly IndustryCalculationService $calculationService,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly IndustryActivityMaterialRepository $materialRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurePublisherService $mercurePublisher,
        private readonly IndustryBlueprintRepository $blueprintRepository,
        private readonly InventionCatalog $inventionCatalog,
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
            $rawSteps = $this->regenerationPlanOf($project);
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

    public function stepRegenerationPreviewOf(IndustryProject $project): StepRegenerationPreview
    {
        $valuesBeforeByStepId = [];
        foreach ($project->getSteps() as $step) {
            $valuesBeforeByStepId[spl_object_id($step)] = self::valuesOfStep($step);
        }

        $rawSteps = $this->regenerationPlanOf($project);
        $matching = $this->matchPlanToExistingSteps($project, $rawSteps);

        $lines = array_map(
            fn (array $match): StepRegenerationPreviewLine => new StepRegenerationPreviewLine(
                $match['existingStep'],
                $match['plannedStep']['blueprintTypeId'],
                $match['plannedStep']['activityType'],
                $match['plannedStep']['depth'],
                $match['plannedStep']['splitIndex'] ?? 0,
                $match['existingStep'] === null ? null : $valuesBeforeByStepId[spl_object_id($match['existingStep'])],
                new StepJobValues(
                    $match['plannedStep']['runs'],
                    $match['plannedStep']['quantity'],
                    $this->meLevelOfPlannedStep($project, $match['plannedStep']),
                    $this->teLevelOfPlannedStep($project, $match['plannedStep']),
                ),
            ),
            $matching['matches'],
        );

        $plannedKeys = array_column($matching['matches'], 'key');
        $stepsKeptOutOfPlan = array_filter(
            $matching['remainingStepsByKey'],
            static fn (string $key): bool => !in_array($key, $plannedKeys, true),
            ARRAY_FILTER_USE_KEY,
        );

        return new StepRegenerationPreview(
            $lines,
            count($rawSteps) + array_sum(array_map(count(...), $stepsKeptOutOfPlan)),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function regenerationPlanOf(IndustryProject $project): array
    {
        $user = $project->getUser();
        $settingsOfExistingSteps = $this->settingsOfExistingStepsByKeyInSplitIndexOrder($project);
        $tree = $this->productionTreeOf($project);

        $rawSteps = [];
        $this->collectStepsFromTree($rawSteps, $tree, $project->getMeLevel(), $project->getTeLevel());
        $rawSteps = array_map(fn (array $rawStep): array => $this->withSettingsOfExistingStep($rawStep, $settingsOfExistingSteps), $rawSteps);
        $this->addTimeDataToSteps($rawSteps, $user);
        $rawSteps = $this->planStepsJobByJob($rawSteps, $user, $project->getMaxJobDurationDays(), $settingsOfExistingSteps, $this->existingJobsWithFixedRunsByKey($project));

        $this->sortRawSteps($rawSteps);

        return $rawSteps;
    }

    private static function valuesOfStep(IndustryProjectStep $step): StepJobValues
    {
        return new StepJobValues($step->getRuns(), $step->getQuantity(), $step->getMeLevel(), $step->getTeLevel());
    }

    /**
     * @param array<string, mixed> $plannedStep
     */
    private function meLevelOfPlannedStep(IndustryProject $project, array $plannedStep): int
    {
        return $plannedStep['meLevel'] ?? ($plannedStep['depth'] === 0 ? $project->getMeLevel() : 10);
    }

    /**
     * @param array<string, mixed> $plannedStep
     */
    private function teLevelOfPlannedStep(IndustryProject $project, array $plannedStep): int
    {
        return $plannedStep['teLevel'] ?? ($plannedStep['depth'] === 0 ? $project->getTeLevel() : 20);
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

        $step->setMeLevel($this->meLevelOfPlannedStep($project, $plannedStep));
        $step->setTeLevel($this->teLevelOfPlannedStep($project, $plannedStep));
    }

    /**
     * @param list<array<string, mixed>> $rawSteps
     * @return array{matches: list<array{plannedStep: array<string, mixed>, key: string, existingStep: IndustryProjectStep|null}>, remainingStepsByKey: array<string, list<IndustryProjectStep>>}
     */
    private function matchPlanToExistingSteps(IndustryProject $project, array $rawSteps): array
    {
        $existingStepsByKey = $this->existingStepsByKeyInSplitIndexOrder($project);
        $matches = [];

        foreach ($rawSteps as $plannedStep) {
            $key = self::stepKey($plannedStep['blueprintTypeId'], $plannedStep['activityType']);
            $existingStepsByKey[$key] ??= [];
            $matches[] = ['plannedStep' => $plannedStep, 'key' => $key, 'existingStep' => array_shift($existingStepsByKey[$key])];
        }

        return ['matches' => $matches, 'remainingStepsByKey' => $existingStepsByKey];
    }

    /**
     * @param list<array<string, mixed>> $rawSteps
     */
    private function applyPlanToExistingSteps(IndustryProject $project, array $rawSteps): void
    {
        $matching = $this->matchPlanToExistingSteps($project, $rawSteps);
        $lastKeptStepByKey = [];

        foreach ($matching['matches'] as $sortOrder => $match) {
            $step = $match['existingStep'] ?? new IndustryProjectStep();
            $this->applyPlannedStep($project, $step, $match['plannedStep'], $sortOrder);
            $project->addStep($step);
            $lastKeptStepByKey[$match['key']] = $step;
        }

        $existingStepsByKey = $matching['remainingStepsByKey'];
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
        $activityType = $node['activityType'];
        $key = self::stepKey($node['blueprintTypeId'], $activityType);
        $copyKey = self::stepKey($node['blueprintTypeId'], 'copy');
        $hasCopyActivity = !empty($node['hasCopy']);

        if (isset($steps[$copyKey])) {
            $steps[$copyKey]['quantity'] += $node['runs'];
            $steps[$copyKey]['runs'] = $steps[$copyKey]['quantity'];
            $steps[$copyKey]['planningLevel'] = max($steps[$copyKey]['planningLevel'], $node['depth']);
        } elseif ($hasCopyActivity && !isset($steps[$key]) && $this->inventionCatalog->inventionOf($node['productTypeId']) === null) {
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

        $outputPerRun = $node['outputPerRun'];

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
                'hasCopyActivity' => $hasCopyActivity,
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
        $characterSkillLevels = $this->calculationService->loadCharacterSkillLevels($user);

        foreach ($steps as &$step) {
            $bestCharacter = $this->calculationService->bestCharacterForStepTime($user, $step['blueprintTypeId'], $step['activityType'], $characterSkillLevels);

            $step['timePerRun'] = $this->calculationService->timePerRunOf(
                $user,
                $step['blueprintTypeId'],
                $step['productTypeId'],
                $step['activityType'],
                $step['teLevel'],
                $step['structureConfig'],
                $bestCharacter['skills'],
            );
        }
        unset($step);
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
                ? $this->jobsOf($step, $maxJobDurationDays, $this->bpcMaxRunsOf($step))
                : self::jobsKeepingFixedRuns($step, $existingJobsWithFixedRuns);
            $jobs = array_map(fn (array $job): array => $this->withSettingsOfExistingStep($job, $settingsOfExistingSteps), $plannedJobs);
            $materials = $materialsByKey[$step['blueprintTypeId'] . '-' . SplitGroupMaterials::activityIdOf($step['activityType'])] ?? [];
            foreach ($jobs as $job) {
                $this->addNeedOfJobs($needByProduct, $user, $job, [$job['runs']], $materials, $activityTypeByProduct);
            }

            array_push($plannedSteps, ...$jobs);
        }

        return $this->withCopyStepsFollowingManufacturing($plannedSteps, $settingsOfExistingSteps);
    }

    /**
     * @param list<array<string, mixed>> $plannedSteps
     * @param array<string, list<array{meLevel: int, teLevel: int, structureConfig: IndustryStructureConfig|null}>> $settingsOfExistingSteps
     * @return list<array<string, mixed>>
     */
    private function withCopyStepsFollowingManufacturing(array $plannedSteps, array $settingsOfExistingSteps): array
    {
        $stepsWithCopyJobs = [];
        foreach ($plannedSteps as $plannedStep) {
            if ($plannedStep['activityType'] !== 'copy') {
                $stepsWithCopyJobs[] = $plannedStep;
                continue;
            }

            $manufacturingRuns = array_sum(array_column(
                array_filter($plannedSteps, static fn (array $step): bool => $step['activityType'] === 'manufacturing' && $step['blueprintTypeId'] === $plannedStep['blueprintTypeId']),
                'runs',
            ));
            $copyStep = [...$plannedStep, 'runs' => $manufacturingRuns, 'quantity' => $manufacturingRuns];
            $copyJobs = self::splitUnderMaxRuns($copyStep, $this->maxProductionLimitOf($copyStep['blueprintTypeId']));
            array_push($stepsWithCopyJobs, ...array_map(fn (array $job): array => $this->withSettingsOfExistingStep($job, $settingsOfExistingSteps), $copyJobs));
        }

        return $stepsWithCopyJobs;
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
        $materialBonus = $this->calculationService->structureBonusOf($user, $consumer['productTypeId'], $consumer['activityType'], $consumer['structureConfig'])['materialBonus'];
        foreach ($materials as $material) {
            $materialTypeId = $material->getMaterialTypeId();
            if (!isset($activityTypeByProduct[$materialTypeId])) {
                continue;
            }
            $needByProduct[$materialTypeId] += SplitGroupMaterials::materialsOfJobs($this->calculationService, $material->getQuantity(), $jobRuns, $consumer['meLevel'], $materialBonus);
        }
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
     */
    private function bpcMaxRunsOf(array $step): ?int
    {
        if ($step['activityType'] !== 'manufacturing' || !$step['hasCopyActivity']) {
            return null;
        }

        return $this->maxProductionLimitOf($step['blueprintTypeId']);
    }

    private function maxProductionLimitOf(int $blueprintTypeId): int
    {
        $industryBlueprint = $this->blueprintRepository->findBy(['typeId' => $blueprintTypeId])[0] ?? null;
        if ($industryBlueprint === null) {
            throw new \RuntimeException("No industry blueprint in the SDE for blueprint {$blueprintTypeId}: its BPC max runs is unknown");
        }

        $bpcMaxRuns = $industryBlueprint->getMaxProductionLimit();
        if ($bpcMaxRuns <= 0) {
            throw new \RuntimeException("Invalid max production limit {$bpcMaxRuns} in the SDE for blueprint {$blueprintTypeId}");
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
        return self::splitUnderMaxRuns($step, self::maxRunsPerJob($step, $maxJobDurationDays, $bpcMaxRuns));
    }

    /**
     * @param array<string, mixed> $step
     * @return list<array<string, mixed>>
     */
    private static function splitUnderMaxRuns(array $step, ?int $maxRunsPerJob): array
    {
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
