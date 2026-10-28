<?php

declare(strict_types=1);

namespace App\Service\GroupIndustry;

use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectItem;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Enum\IndustryActivityType;
use App\Exception\InventionProbabilityMissingException;
use App\Exception\InventionT1ProductMissingException;
use App\Exception\TypeMissingFromSdeException;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\ProductionPlanning;
use App\Industry\Application\ProductionPlanRequest;
use App\Industry\Application\UserProductionStructures;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\PlanTarget;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Service\Industry\InventionService;
use App\Service\JitaMarketService;
use Doctrine\ORM\EntityManagerInterface;

class GroupIndustryProjectService
{
    public function __construct(
        private readonly ProductionPlanning $productionPlanning,
        private readonly UserProductionStructures $userStructures,
        private readonly JitaMarketService $jitaMarketService,
        private readonly EntityManagerInterface $entityManager,
        private readonly InventionService $inventionService,
        private readonly IndustryActivityProductRepository $activityProductRepository,
        private readonly IndustryActivityRepository $activityRepository,
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

        /** @var array<string, array{typeId: int, typeName: string, runs: int, meLevel: int, teLevel: int, activityType: string, jobGroup: string, parentTypeId: int|null, blueprintTypeId: int, outputPerRun: int}> $jobAggregation */
        $jobAggregation = [];

        $targets = [];
        foreach ($project->getItems() as $item) {
            $targets[] = new PlanTarget(
                $item->getTypeId(),
                new Runs($item->getRuns()),
                new BlueprintEfficiency(new MaterialEfficiency($item->getMeLevel()), new TimeEfficiency($item->getTeLevel())),
            );
        }

        try {
            $this->productionPlanning->planOf(new ProductionPlanRequest(
                $targets,
                $this->userStructures->configuredBy($this->idOf($owner)),
                FavoriteSystems::none(),
                $blacklistedTypeIds,
            ));
        } catch (\DomainException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        $this->replaceT2BlueprintCopiesByInventions($materialAggregation, $jobAggregation);

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

        foreach ($jobAggregation as $jobData) {
            $bomItem = new GroupIndustryBomItem();
            $bomItem->setTypeId($jobData['typeId']);
            $bomItem->setTypeName($jobData['typeName']);
            $bomItem->setRequiredQuantity(
                in_array($jobData['activityType'], ['manufacturing', 'reaction'], true) ? $jobData['runs'] * $jobData['outputPerRun'] : $jobData['runs'],
            );
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
     * @param array<int, array{typeName: string, quantity: int}> $materialAggregation
     * @param array<string, array{typeId: int, typeName: string, runs: int, meLevel: int, teLevel: int, activityType: string, jobGroup: string, parentTypeId: int|null, blueprintTypeId: int, outputPerRun: int}> $jobAggregation
     */
    private function replaceT2BlueprintCopiesByInventions(array &$materialAggregation, array &$jobAggregation): void
    {
        $manufacturingRunsByProduct = [];
        $firstManufacturingJobByProduct = [];
        foreach ($jobAggregation as $job) {
            if ($job['activityType'] !== 'manufacturing') {
                continue;
            }
            if (isset($firstManufacturingJobByProduct[$job['typeId']])) {
                $manufacturingRunsByProduct[$job['typeId']] += $job['runs'];
            } else {
                $firstManufacturingJobByProduct[$job['typeId']] = $job;
                $manufacturingRunsByProduct[$job['typeId']] = $job['runs'];
            }
        }

        $inventionsByProduct = [];
        $inventionTypeIds = [];
        foreach (array_keys($firstManufacturingJobByProduct) as $productTypeId) {
            $invention = $this->inventionService->getInventionData($productTypeId);
            if ($invention === null) {
                continue;
            }
            if ($invention['probability'] === null || $invention['probability'] <= 0 || $invention['baseRuns'] <= 0) {
                throw new InventionProbabilityMissingException($productTypeId, $invention['t1BlueprintTypeId']);
            }
            $t1ProductTypeId = $this->t1ProductTypeIdOf($invention['t1BlueprintTypeId']);
            $inventionsByProduct[$productTypeId] = ['recipe' => $invention, 'baseProbability' => $invention['probability'], 't1ProductTypeId' => $t1ProductTypeId];
            $inventionTypeIds[] = $t1ProductTypeId;
            foreach ($invention['materials'] as $datacore) {
                $inventionTypeIds[] = $datacore['typeId'];
            }
        }

        if ($inventionsByProduct === []) {
            return;
        }

        $typeNames = $this->sdeTypeNames($inventionTypeIds);

        foreach ($inventionsByProduct as $productTypeId => ['recipe' => $invention, 'baseProbability' => $baseProbability, 't1ProductTypeId' => $t1ProductTypeId]) {
            $firstManufacturingJob = $firstManufacturingJobByProduct[$productTypeId];
            $attempts = (int) ceil($manufacturingRunsByProduct[$productTypeId] / ($invention['baseRuns'] * $baseProbability));

            unset($jobAggregation[$productTypeId . ':copy']);
            $jobAggregation[$productTypeId . ':invention'] = [
                'typeId' => $productTypeId,
                'typeName' => $firstManufacturingJob['typeName'],
                'runs' => $attempts,
                'meLevel' => 0,
                'teLevel' => 0,
                'activityType' => 'invention',
                'jobGroup' => 'blueprint',
                'parentTypeId' => $firstManufacturingJob['parentTypeId'],
                'blueprintTypeId' => $invention['t1BlueprintTypeId'],
                'outputPerRun' => 1,
            ];

            $t1CopyKey = $t1ProductTypeId . ':copy:invention';
            if (isset($jobAggregation[$t1CopyKey])) {
                $jobAggregation[$t1CopyKey]['runs'] += $attempts;
            } else {
                $jobAggregation[$t1CopyKey] = [
                    'typeId' => $t1ProductTypeId,
                    'typeName' => $typeNames[$t1ProductTypeId],
                    'runs' => $attempts,
                    'meLevel' => 0,
                    'teLevel' => 0,
                    'activityType' => 'copy',
                    'jobGroup' => 'blueprint',
                    'parentTypeId' => $firstManufacturingJob['parentTypeId'],
                    'blueprintTypeId' => $invention['t1BlueprintTypeId'],
                    'outputPerRun' => 1,
                ];
            }

            foreach ($invention['materials'] as $datacore) {
                $quantity = $datacore['quantity'] * $attempts;
                if (isset($materialAggregation[$datacore['typeId']])) {
                    $materialAggregation[$datacore['typeId']]['quantity'] += $quantity;
                } else {
                    $materialAggregation[$datacore['typeId']] = [
                        'typeName' => $typeNames[$datacore['typeId']],
                        'quantity' => $quantity,
                    ];
                }
            }
        }
    }

    /**
     * @param list<int> $typeIds
     * @return array<int, string>
     */
    private function sdeTypeNames(array $typeIds): array
    {
        $typeNames = [];
        foreach ($this->entityManager->getRepository(InvType::class)->findBy(['typeId' => $typeIds]) as $type) {
            $typeNames[$type->getTypeId()] = $type->getTypeName();
        }

        $missingTypeIds = array_values(array_diff($typeIds, array_keys($typeNames)));
        if ($missingTypeIds !== []) {
            throw new TypeMissingFromSdeException($missingTypeIds);
        }

        return $typeNames;
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

    private function idOf(User $owner): string
    {
        $ownerId = $owner->getId() ?? throw new \LogicException('A user without id has no configured structure.');

        return $ownerId->toRfc4122();
    }

    private function t1ProductTypeIdOf(int $t1BlueprintTypeId): int
    {
        $manufacturing = IndustryActivityType::Manufacturing->value;
        $products = $this->activityProductRepository->findProductsForBlueprints([$t1BlueprintTypeId], [$manufacturing]);
        $product = $products[$t1BlueprintTypeId . '-' . $manufacturing] ?? null;
        if ($product === null) {
            throw new InventionT1ProductMissingException($t1BlueprintTypeId);
        }

        return $product->getProductTypeId();
    }
}
