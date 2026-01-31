<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\InvTypeRepository;
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
        private readonly IndustryProjectRepository $projectRepository,
        private readonly CachedIndustryJobRepository $jobRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly IndustryActivityMaterialRepository $materialRepository,
        private readonly IndustryActivityProductRepository $productRepository,
        private readonly IndustryActivityRepository $activityRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function createProject(User $user, int $productTypeId, int $runs, int $meLevel, float $maxJobDurationDays = 2.0): IndustryProject
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
        $project->setProductTypeName($type->getTypeName());
        $project->setRuns($runs);
        $project->setMeLevel($meLevel);
        $project->setMaxJobDurationDays($maxJobDurationDays);

        // Collect all steps from tree, then sort and assign order
        $rawSteps = [];
        $this->collectStepsFromTree($rawSteps, $tree);

        // Recalculate reaction quantities based on consolidated consumer needs
        // This fixes the issue where individual paths calculate material quantities
        // before consolidation, leading to incorrect totals
        $this->recalculateReactionQuantities($rawSteps, $user);

        // Add time data to steps (with TE and structure time bonuses)
        $this->addTimeDataToSteps($rawSteps, $user);

        // Split steps that exceed max job duration
        $rawSteps = $this->splitLongJobs($rawSteps, $project->getMaxJobDurationDays());

        // Sort: deepest first, then by activity type (reactions before manufacturing), then by name
        $activityOrder = ['reaction' => 0, 'copy' => 1, 'manufacturing' => 2];
        usort($rawSteps, function (array $a, array $b) use ($activityOrder) {
            // Deepest first
            if ($a['depth'] !== $b['depth']) {
                return $b['depth'] <=> $a['depth'];
            }
            // Group by activity type
            $aOrder = $activityOrder[$a['activityType']] ?? 99;
            $bOrder = $activityOrder[$b['activityType']] ?? 99;
            if ($aOrder !== $bOrder) {
                return $aOrder <=> $bOrder;
            }
            // Within same activity type, group by splitGroupId (keep splits together)
            $aGroup = $a['splitGroupId'] ?? '';
            $bGroup = $b['splitGroupId'] ?? '';
            if ($aGroup !== $bGroup) {
                return strcmp($aGroup, $bGroup);
            }
            // Within same split group, order by splitIndex
            if (($a['splitIndex'] ?? 0) !== ($b['splitIndex'] ?? 0)) {
                return ($a['splitIndex'] ?? 0) <=> ($b['splitIndex'] ?? 0);
            }
            // Alphabetical within group
            return strcasecmp($a['productTypeName'], $b['productTypeName']);
        });

        // Create step entities with sort order
        foreach ($rawSteps as $index => $data) {
            $step = new IndustryProjectStep();
            $step->setBlueprintTypeId($data['blueprintTypeId']);
            $step->setProductTypeId($data['productTypeId']);
            $step->setProductTypeName($data['productTypeName']);
            $step->setQuantity($data['quantity']);
            $step->setRuns($data['runs']);
            $step->setDepth($data['depth']);
            $step->setActivityType($data['activityType']);
            $step->setSortOrder($index);
            $step->setRecommendedStructureName($data['structureName'] ?? null);
            $step->setStructureBonus($data['structureBonus'] ?? null);
            $step->setStructureTimeBonus($data['structureTimeBonus'] ?? null);
            $step->setTimePerRun($data['timePerRun'] ?? null);
            $step->setSplitGroupId($data['splitGroupId'] ?? null);
            $step->setSplitIndex($data['splitIndex'] ?? 0);
            $step->setTotalGroupRuns($data['totalGroupRuns'] ?? null);

            $project->addStep($step);
        }

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    public function regenerateSteps(IndustryProject $project): void
    {
        // Remove existing steps
        foreach ($project->getSteps()->toArray() as $step) {
            $project->getSteps()->removeElement($step);
            $this->entityManager->remove($step);
        }

        $user = $project->getUser();
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

        // Recalculate reaction quantities based on consolidated consumer needs
        $this->recalculateReactionQuantities($rawSteps, $user);

        // Add time data to steps (with TE and structure time bonuses)
        $this->addTimeDataToSteps($rawSteps, $user);

        // Split steps that exceed max job duration
        $rawSteps = $this->splitLongJobs($rawSteps, $project->getMaxJobDurationDays());

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
            // Within same activity type, group by splitGroupId (keep splits together)
            $aGroup = $a['splitGroupId'] ?? '';
            $bGroup = $b['splitGroupId'] ?? '';
            if ($aGroup !== $bGroup) {
                return strcmp($aGroup, $bGroup);
            }
            // Within same split group, order by splitIndex
            if (($a['splitIndex'] ?? 0) !== ($b['splitIndex'] ?? 0)) {
                return ($a['splitIndex'] ?? 0) <=> ($b['splitIndex'] ?? 0);
            }
            return strcasecmp($a['productTypeName'], $b['productTypeName']);
        });

        foreach ($rawSteps as $index => $data) {
            $step = new IndustryProjectStep();
            $step->setBlueprintTypeId($data['blueprintTypeId']);
            $step->setProductTypeId($data['productTypeId']);
            $step->setProductTypeName($data['productTypeName']);
            $step->setQuantity($data['quantity']);
            $step->setRuns($data['runs']);
            $step->setDepth($data['depth']);
            $step->setActivityType($data['activityType']);
            $step->setSortOrder($index);
            $step->setRecommendedStructureName($data['structureName'] ?? null);
            $step->setStructureBonus($data['structureBonus'] ?? null);
            $step->setStructureTimeBonus($data['structureTimeBonus'] ?? null);
            $step->setTimePerRun($data['timePerRun'] ?? null);
            $step->setSplitGroupId($data['splitGroupId'] ?? null);
            $step->setSplitIndex($data['splitIndex'] ?? 0);
            $step->setTotalGroupRuns($data['totalGroupRuns'] ?? null);
            $project->addStep($step);
        }

        $this->entityManager->flush();
    }

    /**
     * Flatten the tree into an array of step data, including BPC (copy) steps.
     * Steps are consolidated by (blueprintTypeId, activityType) to avoid duplicates.
     * Quantities are summed, then runs are recalculated optimally based on total quantity.
     */
    private function collectStepsFromTree(array &$steps, array $node): void
    {
        // Add a BPC (copy) step if this blueprint supports copying
        if (!empty($node['hasCopy'])) {
            $copyKey = $node['blueprintTypeId'] . '_copy';
            if (isset($steps[$copyKey])) {
                // Consolidate: sum quantities, recalculate runs
                $steps[$copyKey]['quantity'] += $node['runs'];
                $steps[$copyKey]['runs'] = $steps[$copyKey]['quantity']; // For copies, runs = quantity
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

        // Add the production step itself (manufacturing or reaction)
        $activityType = $node['activityType'];
        $key = $node['blueprintTypeId'] . '_' . $activityType;
        $outputPerRun = $node['outputPerRun'] ?? 1;

        if (isset($steps[$key])) {
            // Consolidate: sum quantities, then recalculate runs optimally
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
                'structureName' => $node['structureName'] ?? null,
                'structureBonus' => $node['structureBonus'] ?? null,
            ];
        }

        // Recurse into buildable materials
        foreach ($node['materials'] as $material) {
            if (($material['isBuildable'] ?? false) && isset($material['blueprint'])) {
                $this->collectStepsFromTree($steps, $material['blueprint']);
            }
        }
    }

    /**
     * Recalculate reaction step quantities based on consolidated consumer needs.
     *
     * The tree builds material quantities before consolidation, which leads to
     * incorrect totals when multiple paths consume the same reaction product.
     * This method recalculates reaction quantities by:
     * 1. Finding all manufacturing steps that consume each reaction product
     * 2. Calculating how much they actually need based on their consolidated runs
     * 3. Setting the reaction quantity and runs from this total
     * 4. Repeating for deeper reactions (e.g., CF needed by RCF)
     */
    private function recalculateReactionQuantities(array &$steps, User $user): void
    {
        // Group steps by activity type - store references to original array elements
        $reactionSteps = [];

        foreach ($steps as $key => &$step) {
            if ($step['activityType'] === 'reaction') {
                $reactionSteps[$key] = &$step;
            }
        }
        unset($step);

        // Sort reactions by depth (ascending = process shallower reactions first)
        // This ensures parent reactions are recalculated before their child inputs
        uasort($reactionSteps, fn($a, $b) => $a['depth'] <=> $b['depth']);

        // For each reaction, recalculate based on what consumers need
        foreach ($reactionSteps as $reactionKey => &$reaction) {
            $productTypeId = $reaction['productTypeId'];
            $totalNeeded = 0;

            // Find all steps (manufacturing AND reactions) that consume this product
            foreach ($steps as $consumerKey => &$consumer) {
                if ($consumerKey === $reactionKey) {
                    continue;
                }

                // Skip copy steps
                if ($consumer['activityType'] === 'copy') {
                    continue;
                }

                // Get material requirements for this consumer's blueprint
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

                        // Apply ME for manufacturing (intermediate = ME 10)
                        $meMultiplier = 1.0;
                        if ($activityId === self::ACTIVITY_MANUFACTURING) {
                            $meMultiplier = 1 - 10 / 100; // ME 10 for intermediates
                        }

                        // Apply structure bonus for the MATERIAL's category, not consumer's category
                        // E.g., when Rorqual (capital_ship) consumes RCF (composite_reaction),
                        // we need the structure bonus for composite_reaction category
                        $materialCategory = $this->bonusService->getCategoryForProduct($productTypeId, false);
                        $structureBonus = 0;
                        if ($materialCategory !== null) {
                            $isReaction = str_contains($materialCategory, 'reaction');
                            $bonusData = $this->bonusService->findBestStructureForCategory($user, $materialCategory, $isReaction);
                            $structureBonus = $bonusData['bonus'];
                        }
                        $structureMultiplier = $structureBonus > 0 ? (1 - $structureBonus / 100) : 1.0;

                        // Calculate adjusted quantity
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

            // Update reaction quantity and runs directly in $steps via reference
            if ($totalNeeded > 0) {
                $outputPerRun = $reaction['outputPerRun'] ?? 200;
                $steps[$reactionKey]['quantity'] = $totalNeeded;
                $steps[$reactionKey]['runs'] = (int) ceil($totalNeeded / $outputPerRun);
            }
        }
        unset($reaction);
    }

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

        // Only match jobs started after project start date (with 1 day buffer for prep)
        $projectStartDate = $project->getEffectiveJobsStartDate()->modify('-1 day');

        foreach ($project->getSteps() as $step) {
            if ($step->isPurchased()) {
                continue;
            }
            // Skip copy steps for ESI matching (copies don't appear as industry jobs the same way)
            if ($step->getActivityType() === 'copy') {
                continue;
            }
            // Skip steps with manual job data (user override)
            if ($step->isManualJobData()) {
                continue;
            }

            // Clear previous matching data
            $step->setEsiJobId(null);
            $step->setEsiJobCost(null);
            $step->setEsiJobStatus(null);
            $step->setEsiJobEndDate(null);
            $step->setEsiJobRuns(null);
            $step->setEsiJobCharacterName(null);
            $step->setEsiJobIds([]);
            $step->setEsiJobsCount(null);
            $step->setEsiJobsTotalRuns(null);
            $step->setEsiJobsActiveRuns(null);
            $step->setEsiJobsDeliveredRuns(null);
            $step->setSimilarJobs([]);

            $jobs = $this->jobRepository->findManufacturingJobsByBlueprint(
                $step->getBlueprintTypeId(),
                $characterIds,
                $step->getRuns(),
                $projectStartDate,
            );

            if (empty($jobs)) {
                // Even if no exact matches, look for similar jobs with different runs
                $similarJobs = $this->jobRepository->findSimilarJobsWithDifferentRuns(
                    $step->getBlueprintTypeId(),
                    $characterIds,
                    $step->getRuns(),
                    $projectStartDate,
                );

                if (!empty($similarJobs)) {
                    $similarJobsData = [];
                    foreach ($similarJobs as $similarJob) {
                        $similarJobsData[] = [
                            'characterName' => $similarJob->getCharacter()->getName(),
                            'runs' => $similarJob->getRuns(),
                            'jobId' => $similarJob->getJobId(),
                            'status' => $similarJob->getStatus(),
                        ];
                    }
                    $step->setSimilarJobs($similarJobsData);
                }
                continue;
            }

            // Aggregate all matching jobs
            $jobIds = [];
            $totalCost = 0.0;
            $totalRuns = 0;
            $activeRuns = 0;
            $deliveredRuns = 0;
            $characters = [];
            $latestEndDate = null;
            $hasActive = false;

            foreach ($jobs as $job) {
                $jobIds[] = $job->getJobId();
                $totalCost += $job->getCost() ?? 0;
                $totalRuns += $job->getRuns();
                $characters[$job->getCharacter()->getName()] = true;

                if ($job->getStatus() === 'active') {
                    $activeRuns += $job->getRuns();
                    $hasActive = true;
                    // Track latest end date for active jobs
                    if ($latestEndDate === null || $job->getEndDate() > $latestEndDate) {
                        $latestEndDate = $job->getEndDate();
                    }
                } else {
                    $deliveredRuns += $job->getRuns();
                }
            }

            // Set primary job info (first job for backward compatibility)
            $firstJob = $jobs[0];
            $step->setEsiJobId($firstJob->getJobId());
            $step->setEsiJobCost($totalCost);
            $step->setEsiJobRuns($firstJob->getRuns());
            $step->setEsiJobCharacterName(implode(', ', array_keys($characters)));
            $step->setEsiJobEndDate($latestEndDate ?? $firstJob->getEndDate());

            // Determine overall status
            if ($hasActive) {
                $step->setEsiJobStatus('active');
            } else {
                $step->setEsiJobStatus('delivered');
            }

            // Set aggregated data
            $step->setEsiJobIds($jobIds);
            $step->setEsiJobsCount(count($jobs));
            $step->setEsiJobsTotalRuns($totalRuns);
            $step->setEsiJobsActiveRuns($activeRuns);
            $step->setEsiJobsDeliveredRuns($deliveredRuns);

            // Find similar jobs with different run count (for warning)
            $similarJobs = $this->jobRepository->findSimilarJobsWithDifferentRuns(
                $step->getBlueprintTypeId(),
                $characterIds,
                $step->getRuns(),
                $projectStartDate,
            );

            if (!empty($similarJobs)) {
                $similarJobsData = [];
                foreach ($similarJobs as $similarJob) {
                    $similarJobsData[] = [
                        'characterName' => $similarJob->getCharacter()->getName(),
                        'runs' => $similarJob->getRuns(),
                        'jobId' => $similarJob->getJobId(),
                        'status' => $similarJob->getStatus(),
                    ];
                }
                $step->setSimilarJobs($similarJobsData);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Get a shopping list of raw materials (non-buildable) from the production tree.
     */
    public function getShoppingList(IndustryProject $project): array
    {
        try {
            $user = $project->getUser();
            $excludedTypeIds = $this->blacklistService->resolveBlacklistedTypeIds($user);
            $tree = $this->treeService->buildProductionTree(
                $project->getProductTypeId(),
                $project->getRuns(),
                $project->getMeLevel(),
                $excludedTypeIds,
                $user,
            );
        } catch (\RuntimeException) {
            return [];
        }

        // Collect purchased manufacturing/reaction step product type IDs to skip their sub-trees
        // BPC (copy) steps being purchased just means the copy was bought, not the product itself
        $purchasedTypeIds = [];
        foreach ($project->getSteps() as $step) {
            if ($step->isPurchased() && $step->getActivityType() !== 'copy') {
                $purchasedTypeIds[] = $step->getProductTypeId();
            }
        }

        $rawMaterials = [];
        $this->collectRawMaterials($rawMaterials, $tree, $purchasedTypeIds);

        // Sort by name
        usort($rawMaterials, fn (array $a, array $b) => strcasecmp($a['typeName'], $b['typeName']));

        return $rawMaterials;
    }

    private function collectRawMaterials(array &$materials, array $node, array $purchasedTypeIds): void
    {
        foreach ($node['materials'] as $material) {
            $typeId = $material['typeId'];

            // If this material's production step is marked as purchased, treat it as a raw purchase
            if (in_array($typeId, $purchasedTypeIds, true)) {
                $this->addToMaterialList($materials, $typeId, $material['typeName'], $material['quantity']);
                continue;
            }

            if (($material['isBuildable'] ?? false) && isset($material['blueprint'])) {
                // Recurse into the sub-tree
                $this->collectRawMaterials($materials, $material['blueprint'], $purchasedTypeIds);
            } else {
                // Raw material
                $this->addToMaterialList($materials, $typeId, $material['typeName'], $material['quantity']);
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

    public function getProjectSummary(IndustryProject $project): array
    {
        $jobsCost = $project->getJobsCost();
        $totalCost = $project->getTotalCost();
        $profit = $project->getProfit();
        $profitPercent = $project->getProfitPercent();

        return [
            'id' => $project->getId()->toRfc4122(),
            'productTypeName' => $project->getProductTypeName(),
            'productTypeId' => $project->getProductTypeId(),
            'runs' => $project->getRuns(),
            'meLevel' => $project->getMeLevel(),
            'maxJobDurationDays' => $project->getMaxJobDurationDays(),
            'status' => $project->getStatus(),
            'personalUse' => $project->isPersonalUse(),
            'bpoCost' => $project->getBpoCost(),
            'materialCost' => $project->getMaterialCost(),
            'transportCost' => $project->getTransportCost(),
            'jobsCost' => $jobsCost,
            'taxAmount' => $project->getTaxAmount(),
            'sellPrice' => $project->getSellPrice(),
            'totalCost' => $totalCost,
            'profit' => $profit,
            'profitPercent' => $profitPercent !== null ? round($profitPercent, 2) : null,
            'notes' => $project->getNotes(),
            'createdAt' => $project->getCreatedAt()->format('c'),
            'jobsStartDate' => $project->getJobsStartDate()?->format('c'),
            'completedAt' => $project->getCompletedAt()?->format('c'),
        ];
    }

    public function serializeStep(IndustryProjectStep $step): array
    {
        return [
            'id' => $step->getId()->toRfc4122(),
            'blueprintTypeId' => $step->getBlueprintTypeId(),
            'productTypeId' => $step->getProductTypeId(),
            'productTypeName' => $step->getProductTypeName(),
            'quantity' => $step->getQuantity(),
            'runs' => $step->getRuns(),
            'depth' => $step->getDepth(),
            'activityType' => $step->getActivityType(),
            'sortOrder' => $step->getSortOrder(),
            'purchased' => $step->isPurchased(),
            'esiJobId' => $step->getEsiJobId(),
            'esiJobCost' => $step->getEsiJobCost(),
            'esiJobStatus' => $step->getEsiJobStatus(),
            'esiJobEndDate' => $step->getEsiJobEndDate()?->format('c'),
            'esiJobRuns' => $step->getEsiJobRuns(),
            'esiJobCharacterName' => $step->getEsiJobCharacterName(),
            'esiJobsCount' => $step->getEsiJobsCount(),
            'esiJobsTotalRuns' => $step->getEsiJobsTotalRuns(),
            'esiJobsActiveRuns' => $step->getEsiJobsActiveRuns(),
            'esiJobsDeliveredRuns' => $step->getEsiJobsDeliveredRuns(),
            'manualJobData' => $step->isManualJobData(),
            'recommendedStructureName' => $step->getRecommendedStructureName(),
            'structureBonus' => $step->getStructureBonus(),
            'structureTimeBonus' => $step->getStructureTimeBonus(),
            'timePerRun' => $step->getTimePerRun(),
            'estimatedDurationDays' => $step->getEstimatedDurationDays(),
            'splitGroupId' => $step->getSplitGroupId(),
            'splitIndex' => $step->getSplitIndex(),
            'totalGroupRuns' => $step->getTotalGroupRuns(),
            'isSplit' => $step->isSplit(),
            'similarJobs' => $step->getSimilarJobs(),
        ];
    }

    /**
     * Add time data from SDE to each step.
     * Calculates adjusted time per run including TE bonus and structure time bonus.
     *
     * For intermediate blueprints, assumes TE 20 (like ME 10).
     * Structure time bonuses are calculated based on the structure's type and rigs.
     */
    private function addTimeDataToSteps(array &$steps, User $user): void
    {
        // Default TE for intermediate blueprints
        $intermediateTE = $this->bonusService->getDefaultIntermediateTE();

        foreach ($steps as &$step) {
            $activityId = match ($step['activityType']) {
                'reaction' => self::ACTIVITY_REACTION,
                'copy' => 5, // Copying activity
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
                $step['structureTimeBonus'] = null;
                continue;
            }

            // Get structure time bonus for this step
            $isReaction = $step['activityType'] === 'reaction';
            $timeBonusData = $this->bonusService->findBestStructureForProductTimeBonus(
                $user,
                $step['productTypeId'],
                $isReaction
            );
            $structureTimeBonus = $timeBonusData['bonus'];
            $step['structureTimeBonus'] = $structureTimeBonus;

            // Calculate adjusted time per run
            // For depth 0 (final product), TE might be different, but we use intermediate TE for all
            // since we don't have per-blueprint TE stored
            $teLevel = $intermediateTE;

            $adjustedTimePerRun = $this->bonusService->calculateAdjustedTimePerRun(
                $baseTimePerRun,
                $teLevel,
                $structureTimeBonus
            );

            $step['timePerRun'] = $adjustedTimePerRun;
        }
        unset($step);
    }

    /**
     * Split steps that exceed the max job duration.
     * Returns a new array with split steps.
     */
    private function splitLongJobs(array $steps, float $maxDurationDays): array
    {
        $result = [];
        $maxDurationSeconds = $maxDurationDays * self::SECONDS_PER_DAY;

        foreach ($steps as $step) {
            $timePerRun = $step['timePerRun'] ?? null;

            // Skip if no time data or if step is a copy (copies are usually fast)
            if ($timePerRun === null || $step['activityType'] === 'copy') {
                $result[] = $step;
                continue;
            }

            $totalRuns = $step['runs'];
            $totalDuration = $timePerRun * $totalRuns;

            // Check if splitting is needed
            if ($totalDuration <= $maxDurationSeconds) {
                $result[] = $step;
                continue;
            }

            // Calculate max runs per job based on max duration
            $maxRunsPerJob = max(1, (int) floor($maxDurationSeconds / $timePerRun));

            // Split into multiple steps
            $splitGroupId = Uuid::v4()->toRfc4122();
            $remainingRuns = $totalRuns;
            $splitIndex = 0;
            $outputPerRun = $step['outputPerRun'] ?? 1;

            while ($remainingRuns > 0) {
                $runsForThisJob = min($maxRunsPerJob, $remainingRuns);
                $quantityForThisJob = $runsForThisJob * $outputPerRun;

                $splitStep = $step;
                $splitStep['runs'] = $runsForThisJob;
                $splitStep['quantity'] = $quantityForThisJob;
                $splitStep['splitGroupId'] = $splitGroupId;
                $splitStep['splitIndex'] = $splitIndex;
                $splitStep['totalGroupRuns'] = $totalRuns;

                $result[] = $splitStep;

                $remainingRuns -= $runsForThisJob;
                $splitIndex++;
            }
        }

        return $result;
    }
}
