<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\CachedCharacterSkill;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepJobMatch;
use App\Entity\User;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class IndustryProjectService
{
    private const ACTIVITY_MANUFACTURING = 1;
    private const ACTIVITY_REACTION = 11;
    private const SECONDS_PER_DAY = 86400;

    public function __construct(
        private readonly IndustryTreeService $treeService,
        private readonly IndustryBlacklistService $blacklistService,
        private readonly IndustryBonusService $bonusService,
        private readonly IndustryCalculationService $calculationService,
        private readonly CachedIndustryJobRepository $jobRepository,
        private readonly CachedCharacterSkillRepository $skillRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly IndustryActivityMaterialRepository $materialRepository,
        private readonly IndustryActivityProductRepository $productRepository,
        private readonly IndustryActivityRepository $activityRepository,
        private readonly IndustryStructureConfigRepository $structureConfigRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function createProject(User $user, int $productTypeId, int $runs, int $meLevel, float $maxJobDurationDays = 2.0, int $teLevel = 0, ?string $name = null): IndustryProject
    {
        $type = $this->invTypeRepository->find($productTypeId);
        if ($type === null) {
            throw new \InvalidArgumentException("Unknown type ID {$productTypeId}");
        }

        $excludedTypeIds = $this->blacklistService->resolveBlacklistedTypeIds($user);
        $tree = $this->treeService->buildProductionTree($productTypeId, $runs, $meLevel, $excludedTypeIds, $user);

        $project = new IndustryProject();
        $project->setUser($user);
        $project->setProductTypeId($productTypeId);
        $project->setName($name !== '' ? $name : null);
        $project->setRuns($runs);
        $project->setMeLevel($meLevel);
        $project->setTeLevel($teLevel);
        $project->setMaxJobDurationDays($maxJobDurationDays);

        $rawSteps = [];
        $this->collectStepsFromTree($rawSteps, $tree);
        $this->recalculateReactionQuantities($rawSteps, $user);
        $this->addTimeDataToSteps($rawSteps, $user, $project->getTeLevel());
        $rawSteps = $this->splitLongJobs($rawSteps, $project->getMaxJobDurationDays());

        $this->sortAndCreateSteps($project, $rawSteps);

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
            foreach ($project->getSteps()->toArray() as $step) {
                $project->getSteps()->removeElement($step);
                $this->entityManager->remove($step);
            }

            $excludedTypeIds = $this->blacklistService->resolveBlacklistedTypeIds($user);
            $tree = $this->treeService->buildProductionTree(
                $project->getProductTypeId(),
                $project->getRuns(),
                $project->getMeLevel(),
                $excludedTypeIds,
                $user,
            );

            $rawSteps = [];
            $this->collectStepsFromTree($rawSteps, $tree);
            $this->recalculateReactionQuantities($rawSteps, $user);
            $this->addTimeDataToSteps($rawSteps, $user, $project->getTeLevel());
            $rawSteps = $this->splitLongJobs($rawSteps, $project->getMaxJobDurationDays());

            $this->sortAndCreateSteps($project, $rawSteps);

            $this->entityManager->flush();

            if ($userId !== null) {
                $this->mercurePublisher->syncCompleted($userId, 'industry-project', 'Steps regenerated', [
                    'projectId' => $project->getId()->toRfc4122(),
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
     * Sort raw steps and create entities on the project.
     */
    private function sortAndCreateSteps(IndustryProject $project, array &$rawSteps): void
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
            $aGroup = $a['splitGroupId'] ?? '';
            $bGroup = $b['splitGroupId'] ?? '';
            if ($aGroup !== $bGroup) {
                return strcmp($aGroup, $bGroup);
            }
            if (($a['splitIndex'] ?? 0) !== ($b['splitIndex'] ?? 0)) {
                return ($a['splitIndex'] ?? 0) <=> ($b['splitIndex'] ?? 0);
            }
            return strcasecmp($a['productTypeName'], $b['productTypeName']);
        });

        foreach ($rawSteps as $index => $data) {
            $step = new IndustryProjectStep();
            $step->setBlueprintTypeId($data['blueprintTypeId']);
            $step->setProductTypeId($data['productTypeId']);
            $step->setQuantity($data['quantity']);
            $step->setRuns($data['runs']);
            $step->setDepth($data['depth']);
            $step->setActivityType($data['activityType']);
            $step->setSortOrder($index);
            $step->setSplitGroupId($data['splitGroupId'] ?? null);
            $step->setSplitIndex($data['splitIndex'] ?? 0);
            $step->setTotalGroupRuns($data['totalGroupRuns'] ?? null);

            $step->setMeLevel($project->getMeLevel());
            $step->setTeLevel($project->getTeLevel());

            $project->addStep($step);
        }
    }

    /**
     * Flatten the tree into an array of step data.
     * Steps are consolidated by (blueprintTypeId, activityType).
     */
    private function collectStepsFromTree(array &$steps, array $node): void
    {
        if (!empty($node['hasCopy'])) {
            $copyKey = $node['blueprintTypeId'] . '_copy';
            if (isset($steps[$copyKey])) {
                $steps[$copyKey]['quantity'] += $node['runs'];
                $steps[$copyKey]['runs'] = $steps[$copyKey]['quantity'];
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
                ];
            }
        }

        $activityType = $node['activityType'];
        $key = $node['blueprintTypeId'] . '_' . $activityType;
        $outputPerRun = $node['outputPerRun'] ?? 1;

        if (isset($steps[$key])) {
            $steps[$key]['quantity'] += $node['quantity'];
            $steps[$key]['runs'] = (int) ceil($steps[$key]['quantity'] / $steps[$key]['outputPerRun']);
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
            ];
        }

        foreach ($node['materials'] as $material) {
            if (($material['isBuildable'] ?? false) && isset($material['blueprint'])) {
                $this->collectStepsFromTree($steps, $material['blueprint']);
            }
        }
    }

    /**
     * Recalculate reaction step quantities based on consolidated consumer needs.
     */
    private function recalculateReactionQuantities(array &$steps, User $user): void
    {
        $reactionSteps = [];

        foreach ($steps as $key => &$step) {
            if ($step['activityType'] === 'reaction') {
                $reactionSteps[$key] = &$step;
            }
        }
        unset($step);

        uasort($reactionSteps, fn ($a, $b) => $a['depth'] <=> $b['depth']);

        foreach ($reactionSteps as $reactionKey => &$reaction) {
            $productTypeId = $reaction['productTypeId'];
            $totalNeeded = 0;

            foreach ($steps as $consumerKey => &$consumer) {
                if ($consumerKey === $reactionKey) {
                    continue;
                }
                if ($consumer['activityType'] === 'copy') {
                    continue;
                }

                $activityId = match ($consumer['activityType']) {
                    'reaction' => self::ACTIVITY_REACTION,
                    default => self::ACTIVITY_MANUFACTURING,
                };

                $materials = $this->materialRepository->findBy([
                    'typeId' => $consumer['blueprintTypeId'],
                    'activityId' => $activityId,
                ]);

                foreach ($materials as $material) {
                    if ($material->getMaterialTypeId() === $productTypeId) {
                        $baseQty = $material->getQuantity();
                        $runs = $consumer['runs'];

                        $meMultiplier = 1.0;
                        if ($activityId === self::ACTIVITY_MANUFACTURING) {
                            $meMultiplier = 1 - 10 / 100;
                        }

                        $structureBonus = 0;
                        if ($activityId === self::ACTIVITY_REACTION) {
                            $consumerCategory = $this->bonusService->getCategoryForProduct($consumer['productTypeId'], true);
                            if ($consumerCategory !== null) {
                                $bonusData = $this->bonusService->findBestStructureForCategory($user, $consumerCategory, true);
                                $structureBonus = $bonusData['bonus'];
                            }
                        } else {
                            $consumerCategory = $this->bonusService->getCategoryForProduct($consumer['productTypeId'], false);
                            if ($consumerCategory !== null) {
                                $bonusData = $this->bonusService->findBestStructureForCategory($user, $consumerCategory, false);
                                $structureBonus = $bonusData['bonus'];
                            }
                        }
                        $structureMultiplier = $structureBonus > 0 ? (1 - $structureBonus / 100) : 1.0;

                        $adjustedQty = max(
                            $runs,
                            (int) ceil(round($baseQty * $runs * $meMultiplier * $structureMultiplier, 2))
                        );

                        $totalNeeded += $adjustedQty;
                        break;
                    }
                }
            }
            unset($consumer);

            if ($totalNeeded > 0) {
                $outputPerRun = $reaction['outputPerRun'] ?? 200;
                $steps[$reactionKey]['quantity'] = $totalNeeded;
                $steps[$reactionKey]['runs'] = (int) ceil($totalNeeded / $outputPerRun);
            }
        }
        unset($reaction);
    }

    /**
     * Match ESI jobs to project steps using IndustryStepJobMatch entities.
     *
     * Uses a greedy approach: for each step, finds all jobs for that blueprint
     * and assigns them until the step's runs are covered. Jobs already assigned
     * to another step are skipped to avoid double-matching.
     */
    public function matchEsiJobs(IndustryProject $project): void
    {
        $user = $project->getUser();
        $characterIds = [];
        foreach ($user->getCharacters() as $character) {
            $characterIds[] = $character->getId();
        }

        if (empty($characterIds)) {
            return;
        }

        $projectStartDate = $project->getEffectiveJobsStartDate();

        // Clear all previous matches first
        foreach ($project->getSteps() as $step) {
            foreach ($step->getJobMatches()->toArray() as $match) {
                $step->getJobMatches()->removeElement($match);
                $this->entityManager->remove($match);
            }
        }
        $this->entityManager->flush();

        // Collect ESI job IDs already matched to OTHER projects (avoid double-matching)
        $assignedJobIds = [];
        $otherMatches = $this->entityManager->createQuery(
            'SELECT m.esiJobId FROM App\Entity\IndustryStepJobMatch m
             JOIN m.step s
             WHERE s.project != :project'
        )->setParameter('project', $project)->getScalarResult();

        foreach ($otherMatches as $row) {
            $assignedJobIds[$row['esiJobId']] = true;
        }

        $stepsToRecalculate = false;

        foreach ($project->getSteps() as $step) {
            if ($step->isPurchased()) {
                continue;
            }
            if ($step->getActivityType() === 'copy') {
                continue;
            }
            if ($step->getJobMatchMode() === 'none') {
                continue;
            }

            // Find all jobs for this blueprint (no run count filter)
            $jobs = $this->jobRepository->findManufacturingJobsByBlueprint(
                $step->getBlueprintTypeId(),
                $characterIds,
                null,
                $projectStartDate,
            );

            if (empty($jobs)) {
                continue;
            }

            $remainingRuns = $step->getRuns();

            foreach ($jobs as $job) {
                if ($remainingRuns <= 0) {
                    break;
                }

                $jobId = $job->getJobId();
                if (isset($assignedJobIds[$jobId])) {
                    continue;
                }

                // Only match jobs whose runs fit within what this step needs
                if ($job->getRuns() > $remainingRuns) {
                    continue;
                }

                $assignedJobIds[$jobId] = true;
                $remainingRuns -= $job->getRuns();

                $match = $this->createJobMatch($job, $step, $project, $assignedJobIds, $stepsToRecalculate);
                $step->addJobMatch($match);
            }

            // Fallback: try matching one oversized job if no exact matches covered all runs
            if ($remainingRuns > 0) {
                foreach ($jobs as $job) {
                    $jobId = $job->getJobId();
                    if (isset($assignedJobIds[$jobId])) {
                        continue;
                    }
                    if ($job->getRuns() <= $remainingRuns) {
                        continue; // Would have been handled above
                    }

                    $assignedJobIds[$jobId] = true;

                    $match = $this->createJobMatch($job, $step, $project, $assignedJobIds, $stepsToRecalculate);
                    $step->addJobMatch($match);
                    break; // Only one fallback job
                }
            }

            // Adapt step runs if total matched runs differ from expected
            $totalMatchedRuns = 0;
            foreach ($step->getJobMatches() as $m) {
                $totalMatchedRuns += $m->getRuns();
            }
            if ($totalMatchedRuns > 0 && $totalMatchedRuns !== $step->getRuns()) {
                $this->adaptStepRuns($step, $totalMatchedRuns);
                $stepsToRecalculate = true;
            }
        }

        $this->entityManager->flush();

        if ($stepsToRecalculate) {
            $this->recalculateStepQuantities($project);
        }
    }

    /**
     * Adapt a step's runs and quantity to match the total runs from linked jobs.
     */
    private function adaptStepRuns(IndustryProjectStep $step, int $newRuns): void
    {
        $activityId = $step->getActivityType() === 'reaction'
            ? self::ACTIVITY_REACTION
            : self::ACTIVITY_MANUFACTURING;

        $product = $this->productRepository->findOneBy([
            'typeId' => $step->getBlueprintTypeId(),
            'activityId' => $activityId,
        ]);
        $outputPerRun = $product?->getQuantity() ?? 1;

        $step->setRuns($newRuns);
        $step->setQuantity($newRuns * $outputPerRun);
    }

    /**
     * Create a job match entity from a cached ESI job, with facility auto-correction.
     */
    private function createJobMatch(
        \App\Entity\CachedIndustryJob $job,
        IndustryProjectStep $step,
        IndustryProject $project,
        array &$assignedJobIds,
        bool &$stepsToRecalculate,
    ): IndustryStepJobMatch {
        $match = new IndustryStepJobMatch();
        $match->setEsiJobId($job->getJobId());
        $match->setCost($job->getCost());
        $match->setStatus($job->getStatus());
        $match->setEndDate($job->getEndDate());
        $match->setRuns($job->getRuns());
        $match->setCharacterName($job->getCharacter()->getName());

        // Capture facility info from ESI job
        $stationId = $job->getStationId();
        if ($stationId !== null) {
            $match->setFacilityId($stationId);
            $match->setFacilityName($this->calculationService->resolveFacilityName($stationId));

            // Try to auto-correct step's structure config
            $currentConfig = $step->getStructureConfig();
            $currentLocationId = $currentConfig?->getLocationId();

            if ($currentLocationId !== $stationId) {
                $facilityConfig = $this->structureConfigRepository
                    ->findByUserAndLocationId($project->getUser(), $stationId);

                if ($facilityConfig !== null && $facilityConfig->getId() !== $currentConfig?->getId()) {
                    // Record what was planned before correction
                    $match->setPlannedStructureName($currentConfig?->getName() ?? 'Aucune structure');
                    $currentBonus = $this->calculationService->getStructureBonusForStep($step);
                    $match->setPlannedMaterialBonus($currentBonus['materialBonus']);

                    // Auto-correct
                    $step->setStructureConfig($facilityConfig);
                    $stepsToRecalculate = true;
                }
            }
        }

        return $match;
    }

    /**
     * Recalculate step quantities based on current ME/structure values.
     *
     * Iterates depth by depth (0 → max): for each step, looks up SDE materials
     * and accumulates how much each child step needs to produce. Then updates
     * child step quantity/runs. Handles split groups by redistributing proportionally.
     *
     * @return IndustryProjectStep[] Steps whose quantity/runs changed
     */
    public function recalculateStepQuantities(IndustryProject $project): array
    {
        $steps = $project->getSteps()->toArray();
        if (empty($steps)) {
            return [];
        }

        // Build lookup: productTypeId → steps (handles split groups)
        $stepsByProduct = [];
        foreach ($steps as $step) {
            if ($step->getActivityType() === 'copy') {
                continue;
            }
            $stepsByProduct[$step->getProductTypeId()][] = $step;
        }

        // Find max depth
        $maxDepth = 0;
        foreach ($steps as $step) {
            $maxDepth = max($maxDepth, $step->getDepth());
        }

        $updatedSteps = [];

        // Process depth by depth: accumulate material needs, update child steps
        for ($depth = 0; $depth < $maxDepth; $depth++) {
            // Collect material needs from all steps at this depth
            $neededByTypeId = [];

            foreach ($steps as $step) {
                if ($step->getDepth() !== $depth || $step->getActivityType() === 'copy') {
                    continue;
                }

                $activityId = $step->getActivityType() === 'reaction'
                    ? self::ACTIVITY_REACTION
                    : self::ACTIVITY_MANUFACTURING;

                $materials = $this->materialRepository->findBy([
                    'typeId' => $step->getBlueprintTypeId(),
                    'activityId' => $activityId,
                ]);

                $structureData = $this->calculationService->getStructureBonusForStep($step);
                $structureBonus = $structureData['materialBonus'];

                foreach ($materials as $material) {
                    $materialTypeId = $material->getMaterialTypeId();

                    // Only process materials that have corresponding steps
                    if (!isset($stepsByProduct[$materialTypeId])) {
                        continue;
                    }

                    $needed = $this->calculationService->calculateMaterialQuantity(
                        $material->getQuantity(),
                        $step->getRuns(),
                        $step->getMeLevel(),
                        $structureBonus,
                    );

                    $neededByTypeId[$materialTypeId] = ($neededByTypeId[$materialTypeId] ?? 0) + $needed;
                }
            }

            // Update child steps at depth+1
            foreach ($neededByTypeId as $typeId => $newTotalQuantity) {
                $childSteps = $stepsByProduct[$typeId] ?? [];
                if (empty($childSteps)) {
                    continue;
                }

                // Get output per run from SDE
                $firstChild = $childSteps[0];
                $childActivityId = $firstChild->getActivityType() === 'reaction'
                    ? self::ACTIVITY_REACTION
                    : self::ACTIVITY_MANUFACTURING;
                $product = $this->productRepository->findOneBy([
                    'typeId' => $firstChild->getBlueprintTypeId(),
                    'activityId' => $childActivityId,
                ]);
                $outputPerRun = $product?->getQuantity() ?? 1;

                $newTotalRuns = (int) ceil($newTotalQuantity / $outputPerRun);

                // Single step (no split)
                if (count($childSteps) === 1) {
                    $child = $childSteps[0];
                    if ($child->getQuantity() !== $newTotalQuantity || $child->getRuns() !== $newTotalRuns) {
                        $child->setQuantity($newTotalQuantity);
                        $child->setRuns($newTotalRuns);
                        $updatedSteps[] = $child;
                    }
                    continue;
                }

                // Split group: redistribute runs proportionally
                $oldTotalRuns = 0;
                foreach ($childSteps as $child) {
                    $oldTotalRuns += $child->getRuns();
                }

                if ($oldTotalRuns === 0) {
                    $oldTotalRuns = 1;
                }

                $assignedRuns = 0;
                foreach ($childSteps as $i => $child) {
                    $isLast = ($i === count($childSteps) - 1);

                    if ($isLast) {
                        $childRuns = $newTotalRuns - $assignedRuns;
                    } else {
                        $childRuns = (int) round($newTotalRuns * $child->getRuns() / $oldTotalRuns);
                    }
                    $childRuns = max(1, $childRuns);
                    $childQuantity = $childRuns * $outputPerRun;
                    $assignedRuns += $childRuns;

                    if ($child->getQuantity() !== $childQuantity || $child->getRuns() !== $childRuns) {
                        $child->setQuantity($childQuantity);
                        $child->setRuns($childRuns);
                        $child->setTotalGroupRuns($newTotalRuns);
                        $updatedSteps[] = $child;
                    }
                }
            }
        }

        if (!empty($updatedSteps)) {
            $this->entityManager->flush();
        }

        return $updatedSteps;
    }

    /**
     * Get a shopping list of raw materials from the production tree.
     * Includes extraQuantity per material when steps use suboptimal structures.
     */
    public function getShoppingList(IndustryProject $project): array
    {
        $user = $project->getUser();
        $excludedTypeIds = $this->blacklistService->resolveBlacklistedTypeIds($user);

        $purchasedTypeIds = [];
        $inStockQuantities = [];
        foreach ($project->getSteps() as $step) {
            if ($step->getActivityType() === 'copy') {
                continue;
            }
            $typeId = $step->getProductTypeId();
            if ($step->getInStockQuantity() > 0) {
                $inStockQuantities[$typeId] = ($inStockQuantities[$typeId] ?? 0) + $step->getInStockQuantity();
            } elseif ($step->isPurchased()) {
                $purchasedTypeIds[] = $typeId;
            }
        }

        $rootProducts = [];
        foreach ($project->getSteps() as $step) {
            if ($step->getDepth() === 0 && $step->getActivityType() !== 'copy') {
                $key = $step->getProductTypeId();
                if (!isset($rootProducts[$key])) {
                    $rootProducts[$key] = [
                        'typeId' => $step->getProductTypeId(),
                        'runs' => $step->getRuns(),
                        'meLevel' => $step->getMeLevel(),
                    ];
                } else {
                    // Accumulate runs across multiple steps of the same product
                    $rootProducts[$key]['runs'] += $step->getRuns();
                }
            }
        }

        if (empty($rootProducts)) {
            $rootProducts[$project->getProductTypeId()] = [
                'typeId' => $project->getProductTypeId(),
                'runs' => $project->getRuns(),
                'meLevel' => $project->getMeLevel(),
            ];
        }

        // Build structure bonus overrides from actual step configs
        $structureBonusOverrides = [];
        $hasSuboptimal = false;
        foreach ($project->getSteps() as $step) {
            if ($step->getActivityType() === 'copy') {
                continue;
            }
            $productTypeId = $step->getProductTypeId();
            if (isset($structureBonusOverrides[$productTypeId])) {
                continue; // Already computed for this product
            }
            $structureData = $this->calculationService->getStructureBonusForStep($step);
            $actualBonus = $structureData['materialBonus'];
            $structureBonusOverrides[$productTypeId] = $actualBonus;

            // Check if this differs from the best available
            $isReaction = $step->getActivityType() === 'reaction';
            $bestData = $this->bonusService->findBestStructureForProduct($user, $productTypeId, $isReaction);
            if (abs($actualBonus - $bestData['bonus']) > 0.001) {
                $hasSuboptimal = true;
            }
        }

        $rawMaterials = [];
        $optimalStockQty = $inStockQuantities;

        foreach ($rootProducts as $product) {
            try {
                $tree = $this->treeService->buildProductionTree(
                    $product['typeId'],
                    $product['runs'],
                    $product['meLevel'],
                    $excludedTypeIds,
                    $user,
                );
                $this->collectRawMaterials($rawMaterials, $tree, $purchasedTypeIds, $optimalStockQty);
            } catch (\RuntimeException) {
                continue;
            }
        }

        // If any step uses a suboptimal structure, compute actual quantities and delta
        if ($hasSuboptimal) {
            $actualRawMaterials = [];
            $actualStockQty = $inStockQuantities;

            foreach ($rootProducts as $product) {
                try {
                    $actualTree = $this->treeService->buildProductionTree(
                        $product['typeId'],
                        $product['runs'],
                        $product['meLevel'],
                        $excludedTypeIds,
                        $user,
                        $structureBonusOverrides,
                    );
                    $this->collectRawMaterials($actualRawMaterials, $actualTree, $purchasedTypeIds, $actualStockQty);
                } catch (\RuntimeException) {
                    continue;
                }
            }

            // Build lookup: typeId => actual quantity
            $actualByType = [];
            foreach ($actualRawMaterials as $mat) {
                $actualByType[$mat['typeId']] = ($actualByType[$mat['typeId']] ?? 0) + $mat['quantity'];
            }

            // Compute extraQuantity as delta
            foreach ($rawMaterials as &$mat) {
                $actualQty = $actualByType[$mat['typeId']] ?? $mat['quantity'];
                $mat['extraQuantity'] = max(0, $actualQty - $mat['quantity']);
            }
            unset($mat);
        }

        usort($rawMaterials, fn (array $a, array $b) => strcasecmp($a['typeName'], $b['typeName']));

        return $rawMaterials;
    }

    /**
     * @return array<int, int> typeId => quantity
     */
    public function getPurchasedQuantities(IndustryProject $project): array
    {
        $quantities = [];
        foreach ($project->getSteps() as $step) {
            foreach ($step->getPurchases() as $purchase) {
                $typeId = $purchase->getTypeId();
                $quantities[$typeId] = ($quantities[$typeId] ?? 0) + $purchase->getQuantity();
            }
        }

        return $quantities;
    }

    private function collectRawMaterials(array &$materials, array $node, array $purchasedTypeIds, array &$inStockQuantities): void
    {
        foreach ($node['materials'] as $material) {
            $typeId = (int) $material['typeId'];
            $neededQuantity = (int) $material['quantity'];

            $availableStock = $inStockQuantities[$typeId] ?? 0;
            if ($availableStock > 0) {
                if ($availableStock >= $neededQuantity) {
                    $inStockQuantities[$typeId] -= $neededQuantity;
                    continue;
                }
                $neededQuantity -= $availableStock;
                $inStockQuantities[$typeId] = 0;
            }

            if (in_array($typeId, $purchasedTypeIds, true)) {
                $this->addToMaterialList($materials, $typeId, $material['typeName'], $neededQuantity);
                continue;
            }

            if (($material['isBuildable'] ?? false) && isset($material['blueprint'])) {
                $this->collectRawMaterials($materials, $material['blueprint'], $purchasedTypeIds, $inStockQuantities);
            } else {
                $this->addToMaterialList($materials, $typeId, $material['typeName'], $neededQuantity);
            }
        }
    }

    private function addToMaterialList(array &$materials, int $typeId, string $typeName, int $quantity): void
    {
        foreach ($materials as &$mat) {
            if ($mat['typeId'] === $typeId) {
                $mat['quantity'] += $quantity;
                return;
            }
        }
        $materials[] = [
            'typeId' => $typeId,
            'typeName' => $typeName,
            'quantity' => $quantity,
        ];
    }

    private function addTimeDataToSteps(array &$steps, User $user, int $projectTeLevel = 20): void
    {
        // Preload all skills for all characters (one query per character)
        $characterSkillLevels = $this->loadAllCharacterSkills($user);

        foreach ($steps as &$step) {
            $activityId = match ($step['activityType']) {
                'reaction' => self::ACTIVITY_REACTION,
                'copy' => 5,
                default => self::ACTIVITY_MANUFACTURING,
            };

            $activity = $this->activityRepository->findOneBy([
                'typeId' => $step['blueprintTypeId'],
                'activityId' => $activityId,
            ]);

            $baseTimePerRun = $activity?->getTime();
            $step['baseTimePerRun'] = $baseTimePerRun;

            if ($baseTimePerRun === null) {
                $step['timePerRun'] = null;
                continue;
            }

            $isReaction = $step['activityType'] === 'reaction';
            $timeBonusData = $this->bonusService->findBestStructureForProductTimeBonus(
                $user,
                $step['productTypeId'],
                $isReaction
            );
            $structureTimeBonus = $timeBonusData['bonus'];

            if ($isReaction) {
                $teLevel = 0;
            } else {
                $teLevel = ($step['depth'] ?? 0) === 0 ? $projectTeLevel : 20;
            }

            $adjustedTimePerRun = $this->bonusService->calculateAdjustedTimePerRun(
                $baseTimePerRun,
                $teLevel,
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
            $multiplier = 1.0;

            if ($activityType === 'reaction') {
                $reactionLevel = $skillLevels[CachedCharacterSkill::SKILL_REACTIONS] ?? 0;
                $multiplier *= (1 - 0.04 * $reactionLevel);
            } else {
                $industryLevel = $skillLevels[CachedCharacterSkill::SKILL_INDUSTRY] ?? 0;
                $advancedLevel = $skillLevels[CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY] ?? 0;
                $multiplier *= (1 - 0.04 * $industryLevel);
                $multiplier *= (1 - 0.03 * $advancedLevel);
            }

            // Blueprint-specific science skills (1% per level)
            foreach ($scienceSkillIds as $skillId) {
                $level = $skillLevels[$skillId] ?? 0;
                if ($level > 0) {
                    $multiplier *= (1 - 0.01 * $level);
                }
            }

            if ($multiplier < $bestMultiplier) {
                $bestMultiplier = $multiplier;
            }
        }

        return $bestMultiplier;
    }

    private function splitLongJobs(array $steps, float $maxDurationDays): array
    {
        $result = [];
        $maxDurationSeconds = $maxDurationDays * self::SECONDS_PER_DAY;

        foreach ($steps as $step) {
            $timePerRun = $step['timePerRun'] ?? null;

            if ($timePerRun === null || $step['activityType'] === 'copy') {
                $result[] = $step;
                continue;
            }

            $totalRuns = $step['runs'];
            $totalDuration = $timePerRun * $totalRuns;

            if ($totalDuration <= $maxDurationSeconds) {
                $result[] = $step;
                continue;
            }

            $maxRunsPerJob = max(1, (int) floor($maxDurationSeconds / $timePerRun));
            $numJobs = (int) ceil($totalRuns / $maxRunsPerJob);
            $baseRunsPerJob = (int) floor($totalRuns / $numJobs);
            $remainder = $totalRuns - ($baseRunsPerJob * $numJobs);

            $splitGroupId = Uuid::v4()->toRfc4122();
            $outputPerRun = $step['outputPerRun'] ?? 1;

            for ($splitIndex = 0; $splitIndex < $numJobs; $splitIndex++) {
                $runsForThisJob = $baseRunsPerJob + ($splitIndex < $remainder ? 1 : 0);
                $quantityForThisJob = $runsForThisJob * $outputPerRun;

                $splitStep = $step;
                $splitStep['runs'] = $runsForThisJob;
                $splitStep['quantity'] = $quantityForThisJob;
                $splitStep['splitGroupId'] = $splitGroupId;
                $splitStep['splitIndex'] = $splitIndex;
                $splitStep['totalGroupRuns'] = $totalRuns;

                $result[] = $splitStep;
            }
        }

        return $result;
    }
}
