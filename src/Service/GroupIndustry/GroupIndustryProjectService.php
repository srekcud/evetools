<?php

declare(strict_types=1);

namespace App\Service\GroupIndustry;

use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectItem;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\Sde\IndustryActivityProduct;
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
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\Plan;
use App\Industry\Domain\PlanTarget;
use App\Industry\Domain\ProductionPlanner;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Repository\GroupIndustryContributionRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Service\Industry\InventionService;
use App\Service\JitaMarketService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @phpstan-type JobLine array{typeId: int, typeName: string, runs: int, meLevel: int, teLevel: int, activityType: string, jobGroup: string, parentTypeId: int|null, outputPerRun: int}
 */
class GroupIndustryProjectService
{
    public const int MAX_BOM_LINES = 5000;

    public function __construct(
        private readonly ProductionPlanning $productionPlanning,
        private readonly UserProductionStructures $userStructures,
        private readonly JitaMarketService $jitaMarketService,
        private readonly EntityManagerInterface $entityManager,
        private readonly InventionService $inventionService,
        private readonly IndustryActivityProductRepository $activityProductRepository,
        private readonly IndustryActivityRepository $activityRepository,
        private readonly GroupIndustryContributionRepository $contributionRepository,
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

        $this->buildBom($project, $owner);

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    public function buildBom(GroupIndustryProject $project, User $owner): void
    {
        if ($project->isCompleted()) {
            throw new \RuntimeException('The BOM of a completed project cannot be rebuilt.');
        }

        $plan = $this->planOf($project, $owner);
        $producers = $this->producersOf($plan);
        $typeNames = $this->sdeTypeNames(array_values(array_unique([
            ...array_map(static fn (Job $job): int => $job->productTypeId, $plan->jobs),
            ...array_keys($plan->leaves),
        ])));

        $materialAggregation = [];
        foreach ($plan->leaves as $typeId => $quantity) {
            $materialAggregation[$typeId] = ['typeName' => $typeNames[$typeId], 'quantity' => $quantity->value];
        }
        $jobAggregation = $this->jobLinesOf($plan, $producers, $typeNames);

        $this->replaceT2BlueprintCopiesByInventions($materialAggregation, $jobAggregation);

        $bomLineCount = count($materialAggregation) + count($jobAggregation);
        if ($bomLineCount > self::MAX_BOM_LINES) {
            throw new \RuntimeException(sprintf('The production plan has %d BOM lines, above the limit of %d BOM lines per project.', $bomLineCount, self::MAX_BOM_LINES));
        }

        $materialTypeIds = array_keys($materialAggregation);
        $prices = !empty($materialTypeIds)
            ? $this->jitaMarketService->getCheapestPercentilePrices($materialTypeIds)
            : [];

        $plannedLines = [];

        foreach ($materialAggregation as $typeId => $matData) {
            $bomItem = new GroupIndustryBomItem();
            $bomItem->setTypeId($typeId);
            $bomItem->setTypeName($matData['typeName']);
            $bomItem->setRequiredQuantity($matData['quantity']);
            $bomItem->setIsJob(false);
            $bomItem->setEstimatedPrice($prices[$typeId] ?? null);
            $plannedLines[] = $bomItem;
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
            $plannedLines[] = $bomItem;
        }

        $this->reconcileBomLines($project, $plannedLines);
    }

    /**
     * @param list<GroupIndustryBomItem> $plannedLines
     */
    private function reconcileBomLines(GroupIndustryProject $project, array $plannedLines): void
    {
        $contributedLineIds = $this->contributedLineIdsOf($project);
        $carriesContribution = static fn (GroupIndustryBomItem $line): bool => $line->getId() !== null && isset($contributedLineIds[$line->getId()->toRfc4122()]);

        $existingByKey = $this->groupByLineKey(array_values($project->getBomItems()->toArray()));
        foreach ($existingByKey as &$existingLines) {
            usort($existingLines, static fn (GroupIndustryBomItem $a, GroupIndustryBomItem $b): int => $b->getRuns() <=> $a->getRuns()
                ?: $carriesContribution($b) <=> $carriesContribution($a)
                ?: (string) $a->getId() <=> (string) $b->getId());
        }
        unset($existingLines);

        foreach ($this->groupByLineKey($plannedLines) as $key => $plannedGroup) {
            usort($plannedGroup, static fn (GroupIndustryBomItem $a, GroupIndustryBomItem $b): int => $b->getRuns() <=> $a->getRuns());

            foreach ($plannedGroup as $rank => $plannedLine) {
                if (!isset($existingByKey[$key][$rank])) {
                    $project->addBomItem($plannedLine);
                    continue;
                }

                $this->updateLineInPlace($existingByKey[$key][$rank], $plannedLine);
                unset($existingByKey[$key][$rank]);
            }
        }

        foreach ($existingByKey as $unmatchedLines) {
            foreach ($unmatchedLines as $unmatchedLine) {
                if (!$carriesContribution($unmatchedLine)) {
                    $project->removeBomItem($unmatchedLine);
                }
            }
        }
    }

    /**
     * @return array<string, int>
     */
    private function contributedLineIdsOf(GroupIndustryProject $project): array
    {
        if ($project->getBomItems()->isEmpty()) {
            return [];
        }

        return array_flip($this->contributionRepository->findContributedBomItemIds($project));
    }

    /**
     * @param list<GroupIndustryBomItem> $lines
     * @return array<string, list<GroupIndustryBomItem>>
     */
    private function groupByLineKey(array $lines): array
    {
        $groups = [];
        foreach ($lines as $line) {
            $groups[$this->lineKeyOf($line)][] = $line;
        }

        return $groups;
    }

    private function lineKeyOf(GroupIndustryBomItem $line): string
    {
        if (!$line->isJob()) {
            return \sprintf('material:%d', $line->getTypeId());
        }

        return \sprintf(
            'job:%d:%s:%s:%d:%d',
            $line->getTypeId(),
            $line->getActivityType(),
            $line->getJobGroup(),
            $line->getMeLevel(),
            $line->getTeLevel(),
        );
    }

    private function updateLineInPlace(GroupIndustryBomItem $line, GroupIndustryBomItem $plannedLine): void
    {
        $line->setTypeName($plannedLine->getTypeName());
        $line->setRequiredQuantity($plannedLine->getRequiredQuantity());

        if ($line->isJob()) {
            $line->setRuns($plannedLine->getRuns());
            $line->setParentTypeId($plannedLine->getParentTypeId());

            return;
        }

        $line->setEstimatedPrice($plannedLine->getEstimatedPrice());
    }

    private function planOf(GroupIndustryProject $project, User $owner): Plan
    {
        $blacklistedTypeIds = $this->resolveBlacklistedTypeIds(
            $project->getBlacklistGroupIds(),
            $project->getBlacklistTypeIds(),
        );

        try {
            return $this->productionPlanning->planOf(new ProductionPlanRequest(
                $this->targetsOf($project),
                $this->userStructures->configuredBy($this->idOf($owner)),
                FavoriteSystems::none(),
                $blacklistedTypeIds,
            ));
        } catch (\DomainException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @return list<PlanTarget>
     */
    private function targetsOf(GroupIndustryProject $project): array
    {
        $targets = [];
        foreach ($project->getItems() as $item) {
            $isReaction = $this->producerOf($item->getTypeId())->getActivityId() === IndustryActivityType::Reaction->value;
            $materialEfficiency = $isReaction ? ProductionPlanner::REACTION_EFFICIENCY_LEVEL : $item->getMeLevel();
            $timeEfficiency = $isReaction ? ProductionPlanner::REACTION_EFFICIENCY_LEVEL : $item->getTeLevel();
            $key = $item->getTypeId() . ':' . $materialEfficiency . ':' . $timeEfficiency;
            $runs = ($targets[$key]['runs'] ?? 0) + $item->getRuns();
            $targets[$key] = ['typeId' => $item->getTypeId(), 'runs' => $runs, 'me' => $materialEfficiency, 'te' => $timeEfficiency];
        }

        return array_values(array_map(
            static fn (array $target): PlanTarget => new PlanTarget(
                $target['typeId'],
                new Runs($target['runs']),
                new BlueprintEfficiency(new MaterialEfficiency($target['me']), new TimeEfficiency($target['te'])),
            ),
            $targets,
        ));
    }

    /**
     * @return array<int, IndustryActivityProduct> by product typeId
     */
    private function producersOf(Plan $plan): array
    {
        $producers = [];
        foreach ($plan->jobs as $job) {
            $producers[$job->productTypeId] ??= $this->producerOf($job->productTypeId);
        }

        return $producers;
    }

    private function producerOf(int $productTypeId): IndustryActivityProduct
    {
        return $this->activityProductRepository->findBlueprintForProduct($productTypeId, IndustryActivityType::Manufacturing->value)
            ?? $this->activityProductRepository->findBlueprintForProduct($productTypeId, IndustryActivityType::Reaction->value)
            ?? throw new \RuntimeException("No blueprint or reaction found for product type ID {$productTypeId}");
    }

    /**
     * @param array<int, IndustryActivityProduct> $producers
     * @param array<int, string>                  $typeNames
     * @return array<int|string, JobLine>
     */
    private function jobLinesOf(Plan $plan, array $producers, array $typeNames): array
    {
        $componentJobIds = [];
        foreach ($plan->intermediates as $intermediate) {
            foreach ($intermediate->jobs as $job) {
                $componentJobIds[spl_object_id($job)] = true;
            }
        }

        $firstConsumerByMaterial = [];
        foreach ($plan->jobs as $consumer) {
            foreach (array_keys($consumer->materials) as $materialTypeId) {
                $firstConsumerByMaterial[$materialTypeId] ??= $consumer->productTypeId;
            }
        }

        $lines = [];
        foreach ($plan->jobs as $job) {
            $isComponent = isset($componentJobIds[spl_object_id($job)]);
            $lines[] = [
                'typeId' => $job->productTypeId,
                'typeName' => $typeNames[$job->productTypeId],
                'runs' => $job->runs->value,
                'meLevel' => $job->materialEfficiency->value,
                'teLevel' => $job->timeEfficiency->value,
                'activityType' => $this->activityTypeOf($job->activity),
                'jobGroup' => $isComponent ? 'component' : 'final',
                'parentTypeId' => $isComponent
                    ? $firstConsumerByMaterial[$job->productTypeId] ?? throw new \LogicException(\sprintf('Intermediate %d is consumed by no job of the plan.', $job->productTypeId))
                    : null,
                'outputPerRun' => $producers[$job->productTypeId]->getQuantity(),
            ];
        }

        return $lines + $this->copyLinesOf($lines, $producers);
    }

    /**
     * @param list<JobLine>                       $jobLines
     * @param array<int, IndustryActivityProduct> $producers
     * @return array<string, JobLine>
     */
    private function copyLinesOf(array $jobLines, array $producers): array
    {
        $blueprintTypeIds = array_map(static fn (IndustryActivityProduct $producer): int => $producer->getTypeId(), $producers);
        $copying = IndustryActivityType::Copying->value;
        $copyActivities = $this->activityRepository->findByTypeIdsAndActivityIds(array_values($blueprintTypeIds), [$copying]);

        $copyLines = [];
        foreach ($jobLines as $jobLine) {
            $productTypeId = $jobLine['typeId'];
            if (!isset($copyActivities[$blueprintTypeIds[$productTypeId] . '-' . $copying])) {
                continue;
            }
            $copyKey = $productTypeId . ':copy';
            if (isset($copyLines[$copyKey])) {
                $copyLines[$copyKey]['runs'] += $jobLine['runs'];
                continue;
            }
            $copyLines[$copyKey] = [
                'typeId' => $productTypeId,
                'typeName' => $jobLine['typeName'],
                'runs' => $jobLine['runs'],
                'meLevel' => 0,
                'teLevel' => 0,
                'activityType' => 'copy',
                'jobGroup' => 'blueprint',
                'parentTypeId' => $jobLine['parentTypeId'],
                'outputPerRun' => $jobLine['outputPerRun'],
            ];
        }

        return $copyLines;
    }

    private function activityTypeOf(ActivityKind $activity): string
    {
        return match ($activity) {
            ActivityKind::Manufacturing => 'manufacturing',
            ActivityKind::Reaction => 'reaction',
            ActivityKind::Copying, ActivityKind::Invention => throw new \LogicException(\sprintf('A plan has no %s job.', $activity->name)),
        };
    }

    /**
     * @param array<int, array{typeName: string, quantity: int}> $materialAggregation
     * @param array<int|string, JobLine> $jobAggregation
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
