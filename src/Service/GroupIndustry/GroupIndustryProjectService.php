<?php

declare(strict_types=1);

namespace App\Service\GroupIndustry;

use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectItem;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Repository\Sde\IndustryBlueprintRepository;
use App\Service\Industry\IndustryTreeService;
use App\Service\JitaMarketService;
use Doctrine\ORM\EntityManagerInterface;

class GroupIndustryProjectService
{
    public function __construct(
        private readonly IndustryTreeService $treeService,
        private readonly JitaMarketService $jitaMarketService,
        private readonly EntityManagerInterface $entityManager,
        private readonly IndustryBlueprintRepository $blueprintRepository,
    ) {
    }

    public function createProject(User $owner, CreateProjectData $data): GroupIndustryProject
    {
        $project = new GroupIndustryProject();
        $project->setOwner($owner);
        $project->setName($data->name !== '' ? $data->name : null);
        $project->setContainerName($data->containerName);
        $project->setLineRentalRatesOverride($data->lineRentalRatesOverride);
        $project->setBlacklistGroupIds($data->blacklistGroupIds);
        $project->setBlacklistTypeIds($data->blacklistTypeIds);
        $project->setBrokerFeePercent($data->brokerFeePercent);
        $project->setSalesTaxPercent($data->salesTaxPercent);

        // Add owner as first member (accepted)
        $ownerMember = new GroupIndustryProjectMember();
        $ownerMember->setUser($owner);
        $ownerMember->setRole(GroupMemberRole::Owner);
        $ownerMember->setStatus(GroupMemberStatus::Accepted);
        $project->addMember($ownerMember);

        // Create project items
        foreach ($data->items as $index => $itemData) {
            $item = new GroupIndustryProjectItem();
            $item->setTypeId($itemData['typeId']);
            $item->setTypeName($itemData['typeName']);
            $item->setMeLevel($itemData['meLevel']);
            $item->setTeLevel($itemData['teLevel']);
            $item->setRuns($itemData['runs']);
            $item->setSortOrder($index);
            $project->addItem($item);
        }

        // Generate BOM from production trees
        $this->buildBom($project, $owner);

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    /**
     * Build the Bill of Materials for a project by calling IndustryTreeService
     * for each item, then flattening and aggregating the results.
     */
    public function buildBom(GroupIndustryProject $project, User $owner): void
    {
        $blacklistedTypeIds = $this->resolveBlacklistedTypeIds(
            $project->getBlacklistGroupIds(),
            $project->getBlacklistTypeIds(),
        );

        // Aggregation maps: materials by typeId, jobs by (typeId, activityType)
        /** @var array<int, array{typeName: string, quantity: int}> $materialAggregation */
        $materialAggregation = [];

        /** @var array<string, array{typeId: int, typeName: string, runs: int, meLevel: int, teLevel: int, activityType: string, jobGroup: string, parentTypeId: int|null, blueprintTypeId: int}> $jobAggregation */
        $jobAggregation = [];

        foreach ($project->getItems() as $item) {
            $tree = $this->treeService->buildProductionTree(
                productTypeId: $item->getTypeId(),
                runs: $item->getRuns(),
                finalMe: $item->getMeLevel(),
                excludedTypeIds: $blacklistedTypeIds,
                user: $owner,
            );

            $this->flattenTree(
                $tree,
                $materialAggregation,
                $jobAggregation,
                parentTypeId: null,
            );
        }

        // Create material BOM items
        $materialTypeIds = array_keys($materialAggregation);
        $prices = !empty($materialTypeIds)
            ? $this->jitaMarketService->getCheapestPercentilePrices($materialTypeIds)
            : [];

        foreach ($materialAggregation as $typeId => $matData) {
            $bomItem = new GroupIndustryBomItem();
            $bomItem->setTypeId($typeId);
            $bomItem->setTypeName($matData['typeName']);
            $bomItem->setRequiredQuantity($matData['quantity']);
            $bomItem->setIsJob(false);
            $bomItem->setEstimatedPrice($prices[$typeId] ?? null);
            $project->addBomItem($bomItem);
        }

        // Split jobs that exceed their blueprint's maxProductionLimit
        $splitJobs = $this->splitJobsByMaxProductionLimit($jobAggregation);

        // Create job BOM items
        foreach ($splitJobs as $jobData) {
            $bomItem = new GroupIndustryBomItem();
            $bomItem->setTypeId($jobData['typeId']);
            $bomItem->setTypeName($jobData['typeName']);
            $bomItem->setRequiredQuantity($jobData['runs']);
            $bomItem->setIsJob(true);
            $bomItem->setJobGroup($jobData['jobGroup']);
            $bomItem->setActivityType($jobData['activityType']);
            $bomItem->setMeLevel($jobData['meLevel']);
            $bomItem->setTeLevel($jobData['teLevel']);
            $bomItem->setRuns($jobData['runs']);
            $bomItem->setParentTypeId($jobData['parentTypeId']);
            $project->addBomItem($bomItem);
        }
    }

    /**
     * Recursively flatten a production tree into materials and jobs.
     *
     * @param array<string, mixed> $tree
     * @param array<int, array{typeName: string, quantity: int}> $materialAggregation
     * @param array<string, array{typeId: int, typeName: string, runs: int, meLevel: int, teLevel: int, activityType: string, jobGroup: string, parentTypeId: int|null, blueprintTypeId: int}> $jobAggregation
     */
    private function flattenTree(
        array $tree,
        array &$materialAggregation,
        array &$jobAggregation,
        ?int $parentTypeId,
    ): void {
        $productTypeId = $tree['productTypeId'];
        $blueprintTypeId = $tree['blueprintTypeId'];
        $depth = $tree['depth'];
        $activityType = $tree['activityType'];

        // Determine job group based on depth
        $jobGroup = $depth === 0 ? 'final' : 'component';

        // Register this node as a job
        $jobKey = $productTypeId . ':' . $activityType;
        if (isset($jobAggregation[$jobKey])) {
            // Aggregate runs for the same product + activity
            $jobAggregation[$jobKey]['runs'] += $tree['runs'];
        } else {
            $meLevel = $tree['depth'] === 0 ? 0 : 10;
            // Reactions have no ME
            if ($activityType === 'reaction') {
                $meLevel = 0;
            }

            $jobAggregation[$jobKey] = [
                'typeId' => $productTypeId,
                'typeName' => $tree['productTypeName'],
                'runs' => $tree['runs'],
                'meLevel' => $meLevel,
                'teLevel' => $depth === 0 ? 0 : ($activityType === 'reaction' ? 0 : 20),
                'activityType' => $activityType,
                'jobGroup' => $jobGroup,
                'parentTypeId' => $parentTypeId,
                'blueprintTypeId' => $blueprintTypeId,
            ];
        }

        // Register copy step if needed
        if ($tree['hasCopy']) {
            $copyKey = $productTypeId . ':copy';
            if (!isset($jobAggregation[$copyKey])) {
                $jobAggregation[$copyKey] = [
                    'typeId' => $productTypeId,
                    'typeName' => $tree['productTypeName'],
                    'runs' => $tree['runs'],
                    'meLevel' => 0,
                    'teLevel' => 0,
                    'activityType' => 'copy',
                    'jobGroup' => 'blueprint',
                    'parentTypeId' => $parentTypeId,
                    'blueprintTypeId' => $blueprintTypeId,
                ];
            } else {
                $jobAggregation[$copyKey]['runs'] += $tree['runs'];
            }
        }

        // Process materials
        foreach ($tree['materials'] as $material) {
            if ($material['isBuildable'] && isset($material['blueprint'])) {
                // Intermediate node: recurse into sub-tree
                $this->flattenTree(
                    $material['blueprint'],
                    $materialAggregation,
                    $jobAggregation,
                    parentTypeId: $productTypeId,
                );
            } else {
                // Leaf material: aggregate by typeId
                $matTypeId = $material['typeId'];
                if (isset($materialAggregation[$matTypeId])) {
                    $materialAggregation[$matTypeId]['quantity'] += $material['quantity'];
                } else {
                    $materialAggregation[$matTypeId] = [
                        'typeName' => $material['typeName'],
                        'quantity' => $material['quantity'],
                    ];
                }
            }
        }
    }

    /**
     * Split aggregated jobs that exceed their blueprint's maxProductionLimit into multiple entries.
     *
     * @param array<string, array{typeId: int, typeName: string, runs: int, meLevel: int, teLevel: int, activityType: string, jobGroup: string, parentTypeId: int|null, blueprintTypeId: int}> $jobAggregation
     * @return list<array{typeId: int, typeName: string, runs: int, meLevel: int, teLevel: int, activityType: string, jobGroup: string, parentTypeId: int|null, blueprintTypeId: int}>
     */
    private function splitJobsByMaxProductionLimit(array $jobAggregation): array
    {
        // Copy jobs are not production jobs -- no splitting needed
        $productionJobs = [];
        $copyJobs = [];
        foreach ($jobAggregation as $job) {
            if ($job['activityType'] === 'copy') {
                $copyJobs[] = $job;
            } else {
                $productionJobs[] = $job;
            }
        }

        if (empty($productionJobs)) {
            return array_values($jobAggregation);
        }

        // Batch-load all blueprint maxProductionLimit values
        $blueprintTypeIds = array_unique(array_column($productionJobs, 'blueprintTypeId'));
        $blueprints = $this->blueprintRepository->findBy(['typeId' => $blueprintTypeIds]);
        $maxRunsByBlueprint = [];
        foreach ($blueprints as $bp) {
            $maxRunsByBlueprint[$bp->getTypeId()] = $bp->getMaxProductionLimit();
        }

        $result = [];
        foreach ($productionJobs as $job) {
            $maxRuns = $maxRunsByBlueprint[$job['blueprintTypeId']] ?? null;

            // Default limits: reactions typically 500, manufacturing typically very high
            if ($maxRuns === null) {
                $maxRuns = $job['activityType'] === 'reaction' ? 500 : 10000;
            }

            if ($job['runs'] <= $maxRuns) {
                $result[] = $job;
                continue;
            }

            // Split into multiple entries
            $remaining = $job['runs'];
            while ($remaining > 0) {
                $runsForThisJob = min($remaining, $maxRuns);
                $splitJob = $job;
                $splitJob['runs'] = $runsForThisJob;
                $result[] = $splitJob;
                $remaining -= $runsForThisJob;
            }
        }

        // Append copy jobs at the end (no splitting)
        foreach ($copyJobs as $copyJob) {
            $result[] = $copyJob;
        }

        return $result;
    }

    /**
     * Resolve blacklisted group IDs and type IDs into a flat array of type IDs.
     * Same logic as IndustryBlacklistService but using project-level data instead of user data.
     *
     * @param int[] $groupIds
     * @param int[] $typeIds
     * @return list<int>
     */
    private function resolveBlacklistedTypeIds(array $groupIds, array $typeIds): array
    {
        $resolved = $typeIds;

        if (!empty($groupIds)) {
            $conn = $this->entityManager->getConnection();
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
            $rows = $conn->fetchAllAssociative(
                "SELECT type_id FROM sde_inv_types WHERE group_id IN ({$placeholders}) AND published = true",
                array_values($groupIds),
            );
            foreach ($rows as $row) {
                $resolved[] = (int) $row['type_id'];
            }
        }

        return array_values(array_unique($resolved));
    }
}
