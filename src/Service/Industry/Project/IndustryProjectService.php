<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectProduct;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Exception\InventionProbabilityMissingException;
use App\Exception\TypeMissingFromSdeException;
use App\Industry\Application\Decryptors;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\OwnedBlueprintUse;
use App\Industry\Application\PlannedProduction;
use App\Industry\Application\ProductionPlanning;
use App\Industry\Application\ProductionPlanRequest;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureChoice;
use App\Industry\Application\StructureChoiceStatus;
use App\Industry\Application\StructureSelectionPolicy;
use App\Industry\Application\UserProductionStructures;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintCatalog;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\Decryptor;
use App\Industry\Domain\Intermediate;
use App\Industry\Domain\InventionAttempts;
use App\Industry\Domain\Isk;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\Plan;
use App\Industry\Domain\PlanTarget;
use App\Industry\Domain\ProductionPlanner;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Service\Industry\Blueprint\OriginalBlueprintFinder;
use App\Service\Industry\CourierHubSettings;
use App\Service\Industry\InventionService;
use App\Service\Industry\PriceSource;
use App\Service\JitaMarketService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @phpstan-type JobLine array{typeId: int, typeName: string, runs: int, meLevel: int, teLevel: int, activityType: string, jobGroup: string, parentTypeId: int|null, outputPerRun: int, plannedStructureId: string|null, plannedStructureStatus: string|null, surplusQuantity: int|null}
 */
class IndustryProjectService
{
    public const int MAX_BOM_LINES = 5000;

    private const string COPY_LINE_KEY_FORMAT = '%d:copy:%s';
    private const string COPY_LINE_INDEX_PATTERN = '\d+';

    public function __construct(
        private readonly ProductionPlanning $productionPlanning,
        private readonly UserProductionStructures $userStructures,
        private readonly JitaMarketService $jitaMarketService,
        private readonly EntityManagerInterface $entityManager,
        private readonly InventionService $inventionService,
        private readonly IndustryActivityProductRepository $activityProductRepository,
        private readonly IndustryActivityRepository $activityRepository,
        private readonly IndustryProjectContributionRepository $contributionRepository,
        private readonly IndustryUserSettingsRepository $settingsRepository,
        private readonly OriginalBlueprintFinder $originalBlueprintFinder,
        private readonly Decryptors $decryptors,
        private readonly BlueprintCatalog $blueprintCatalog,
    ) {
    }

    public function createProject(User $owner, CreateProjectData $data): IndustryProject
    {
        $project = new IndustryProject();
        $project->setOwner($owner);
        $project->setName($data->name !== '' ? $data->name : null);
        $project->setContainerNames($data->containerNames);
        $project->setLineRentalRatesOverride($data->lineRentalRatesOverride);
        $project->setBlacklistGroupIds($data->blacklistGroupIds);
        $project->setBlacklistTypeIds($data->blacklistTypeIds);
        $project->setBrokerFeePercent($data->brokerFeePercent);
        $project->setSalesTaxPercent($data->salesTaxPercent);
        $project->setPrivate($data->private);
        $project->setPersonalUse($data->personalUse);
        $project->setAcceptsOutsideFavoriteSystem($data->acceptsOutsideFavoriteSystem);
        $project->setExcludesInvention($data->excludesInvention);
        $project->setProducerCharacterIds($data->producerCharacterIds);
        $project->setMinimumOutsideSaving($data->minimumOutsideSaving);
        $project->setTemplate($data->template);
        $project->setSellStructure($data->sellStructure?->id, $data->sellStructure?->name);

        $project->enrolOwner();

        // Create project items
        foreach ($data->items as $index => $itemData) {
            $item = new IndustryProjectProduct();
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

    public static function bomPriceSource(): PriceSource
    {
        return PriceSource::jitaSellPercentile(JitaMarketService::CHEAPEST_PERCENTILE * PriceSource::PERCENT_PER_FRACTION);
    }

    public function buildBom(IndustryProject $project, User $owner, bool $refuseToDropWorkedLines = false): void
    {
        if ($project->isCompleted()) {
            throw new \RuntimeException('The BOM of a completed project cannot be rebuilt.');
        }

        $stockByTypeId = $this->contributionRepository->findIntermediateStockByTypeId($project);
        $ownedBlueprints = $this->ownedBlueprintsOf($owner);
        $plannedProduction = $this->plannedProductionOf($project, $owner, $stockByTypeId, $ownedBlueprints);
        $plan = $plannedProduction->plan;
        $producers = $this->producersOf($plan);
        $coveredIntermediateTypeIds = array_keys(array_filter($plan->intermediates, static fn (Intermediate $intermediate): bool => [] === $intermediate->jobs));
        $typeNames = $this->sdeTypeNames(array_values(array_unique([
            ...array_map(static fn (Job $job): int => $job->productTypeId, $plan->jobs),
            ...array_keys($plan->leaves),
            ...$coveredIntermediateTypeIds,
        ])));

        $materialAggregation = [];
        foreach ($plan->leaves as $typeId => $quantity) {
            $materialAggregation[$typeId] = ['typeName' => $typeNames[$typeId], 'quantity' => $quantity->value];
        }
        $jobAggregation = $this->jobLinesOf($plannedProduction, $producers, $typeNames, $owner);

        $this->replaceT2BlueprintCopiesByInventions($materialAggregation, $jobAggregation, $plannedProduction->copyStructureChoice ?? throw new \LogicException('A planned production has no copy structure choice.'), $project->declaredRunsByProductTypeId(), $project->isExcludesInvention(), $project->inventionDecryptorsByProductTypeId());

        $consumedStockByTypeId = $this->consumedStockByTypeId($project, $plan, $stockByTypeId);
        $coveredLines = $this->coveredLinesOf($project, $plan, $coveredIntermediateTypeIds, $stockByTypeId, $consumedStockByTypeId, $ownedBlueprints, $typeNames);

        $bomLineCount = count($materialAggregation) + count($jobAggregation) + count($coveredLines);
        if ($bomLineCount > self::MAX_BOM_LINES) {
            throw new \RuntimeException(sprintf('The production plan has %d BOM lines, above the limit of %d BOM lines per project.', $bomLineCount, self::MAX_BOM_LINES));
        }

        $materialTypeIds = array_keys($materialAggregation);
        $prices = !empty($materialTypeIds)
            ? $this->jitaMarketService->getCheapestPercentilePrices($materialTypeIds)
            : [];

        $plannedLines = [];

        foreach ($materialAggregation as $typeId => $matData) {
            $bomItem = new IndustryProjectBomLine();
            $bomItem->setTypeId($typeId);
            $bomItem->setTypeName($matData['typeName']);
            $bomItem->setRequiredQuantity($matData['quantity']);
            $bomItem->setIsJob(false);
            $bomItem->setEstimatedPrice($prices[$typeId] ?? null);
            $plannedLines[] = $bomItem;
        }

        foreach ($jobAggregation as $jobData) {
            $bomItem = new IndustryProjectBomLine();
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
            $bomItem->setPlannedStructureId($jobData['plannedStructureId']);
            $bomItem->setPlannedStructureStatus($jobData['plannedStructureStatus']);
            $bomItem->setSurplusQuantity($jobData['surplusQuantity']);
            $plannedLines[] = $bomItem;
        }

        $this->addConsumedStockToTheLargestJobLines($plannedLines, $consumedStockByTypeId);

        $this->reconcileBomLines($project, [...$plannedLines, ...$coveredLines], $refuseToDropWorkedLines);
    }

    /**
     * @param list<IndustryProjectBomLine> $plannedLines
     */
    private function reconcileBomLines(IndustryProject $project, array $plannedLines, bool $refuseToDropWorkedLines): void
    {
        $contributedLineIds = $this->contributedLineIdsOf($project);
        $carriesContribution = static fn (IndustryProjectBomLine $line): bool => isset($contributedLineIds[$line->getId()->toRfc4122()]);

        $existingByKey = $this->groupByLineKey(array_values($project->getBomItems()->toArray()));
        foreach ($existingByKey as &$existingLines) {
            usort($existingLines, static fn (IndustryProjectBomLine $a, IndustryProjectBomLine $b): int => $b->getRuns() <=> $a->getRuns()
                ?: $carriesContribution($b) <=> $carriesContribution($a)
                ?: (string) $a->getId() <=> (string) $b->getId());
        }
        unset($existingLines);

        $plannedByKey = $this->groupByLineKey($plannedLines);
        foreach ($plannedByKey as &$plannedGroup) {
            usort($plannedGroup, static fn (IndustryProjectBomLine $a, IndustryProjectBomLine $b): int => $b->getRuns() <=> $a->getRuns());
        }
        unset($plannedGroup);

        if ($refuseToDropWorkedLines) {
            $this->assertNoWorkedLineIsDropped($existingByKey, $plannedByKey, $carriesContribution);
            $this->assertNoLineIsCutBelowItsProgress($existingByKey, $plannedByKey);
        }

        foreach ($plannedByKey as $key => $plannedGroup) {
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
     * @param array<string, list<IndustryProjectBomLine>> $existingByKey
     * @param array<string, list<IndustryProjectBomLine>> $plannedByKey
     * @param callable(IndustryProjectBomLine): bool $carriesContribution
     */
    private function assertNoWorkedLineIsDropped(array $existingByKey, array $plannedByKey, callable $carriesContribution): void
    {
        foreach ($existingByKey as $key => $existingLines) {
            foreach (array_slice($existingLines, \count($plannedByKey[$key] ?? [])) as $droppedLine) {
                if (!$droppedLine->getJobMatches()->isEmpty() || !$droppedLine->getPurchases()->isEmpty() || null !== $droppedLine->getMark() || $carriesContribution($droppedLine)) {
                    throw new \RuntimeException(\sprintf('The line "%s" carries a linked job, a purchase or a contribution and cannot be removed from the plan.', $droppedLine->getTypeName()));
                }
            }
        }
    }

    /**
     * @param array<string, list<IndustryProjectBomLine>> $existingByKey
     * @param array<string, list<IndustryProjectBomLine>> $plannedByKey
     */
    private function assertNoLineIsCutBelowItsProgress(array $existingByKey, array $plannedByKey): void
    {
        foreach ($plannedByKey as $key => $plannedGroup) {
            foreach ($plannedGroup as $rank => $plannedLine) {
                $existingLine = $existingByKey[$key][$rank] ?? null;
                if (null === $existingLine) {
                    continue;
                }

                if (!$existingLine->isJob() && $existingLine->getFulfilledQuantity() > $plannedLine->getRequiredQuantity()) {
                    throw new \RuntimeException(\sprintf('The line "%s" has already received %d, more than the %d the plan now needs.', $existingLine->getTypeName(), $existingLine->getFulfilledQuantity(), $plannedLine->getRequiredQuantity()));
                }

                $linkedRuns = array_sum(array_map(static fn (IndustryProjectJobMatch $match): int => $match->getRuns(), $existingLine->getJobMatches()->toArray()));
                if ($existingLine->isJob() && $linkedRuns > $plannedLine->getRuns()) {
                    throw new \RuntimeException(\sprintf('The line "%s" has %d runs linked to ESI jobs, more than the %d the plan now needs.', $existingLine->getTypeName(), $linkedRuns, $plannedLine->getRuns()));
                }

                $markedRuns = $existingLine->getMark()?->getRuns();
                if ($existingLine->isJob() && null !== $markedRuns && $markedRuns > $plannedLine->getRuns()) {
                    throw new \RuntimeException(\sprintf('The line "%s" has %d runs marked, more than the %d the plan now needs.', $existingLine->getTypeName(), $markedRuns, $plannedLine->getRuns()));
                }
            }
        }
    }

    /**
     * @return array<string, int>
     */
    private function contributedLineIdsOf(IndustryProject $project): array
    {
        if ($project->getBomItems()->isEmpty()) {
            return [];
        }

        return array_flip($this->contributionRepository->findContributedBomItemIds($project));
    }

    /**
     * @param list<IndustryProjectBomLine> $lines
     * @return array<string, list<IndustryProjectBomLine>>
     */
    private function groupByLineKey(array $lines): array
    {
        $groups = [];
        foreach ($lines as $line) {
            $groups[$this->lineKeyOf($line)][] = $line;
        }

        return $groups;
    }

    private function lineKeyOf(IndustryProjectBomLine $line): string
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

    private function updateLineInPlace(IndustryProjectBomLine $line, IndustryProjectBomLine $plannedLine): void
    {
        $line->setTypeName($plannedLine->getTypeName());
        $line->setRequiredQuantity($plannedLine->getRequiredQuantity());

        if ($line->isJob()) {
            $line->setRuns($plannedLine->getRuns());
            $line->setParentTypeId($plannedLine->getParentTypeId());
            $line->setPlannedStructureId($plannedLine->getPlannedStructureId());
            $line->setPlannedStructureStatus($plannedLine->getPlannedStructureStatus());
            $line->setSurplusQuantity($plannedLine->getSurplusQuantity());

            return;
        }

        $line->setEstimatedPrice($plannedLine->getEstimatedPrice());
    }

    public function planOf(IndustryProject $project, User $owner): PlannedProduction
    {
        return $this->plannedProductionOf(
            $project,
            $owner,
            $this->contributionRepository->findIntermediateStockByTypeId($project),
            $this->ownedBlueprintsOf($owner),
        );
    }

    /**
     * @param array<int, int>               $stockByTypeId
     * @param array<int, OwnedBlueprintUse> $ownedBlueprints by product typeId
     */
    private function plannedProductionOf(IndustryProject $project, User $owner, array $stockByTypeId, array $ownedBlueprints): PlannedProduction
    {
        return $this->plannedProductionWithBlacklistOf(
            $project,
            $owner,
            $stockByTypeId,
            $ownedBlueprints,
            array_values($project->getBlacklistTypeIds()),
        );
    }

    /**
     * @param list<int> $boughtTypeIds types bought on top of the imposed purchases of the project groups, replacing the blacklisted types of the project
     */
    public function planWithPurchasesOf(IndustryProject $project, User $owner, array $boughtTypeIds): PlannedProduction
    {
        return $this->plannedProductionWithBlacklistOf(
            $project,
            $owner,
            $this->contributionRepository->findIntermediateStockByTypeId($project),
            $this->ownedBlueprintsOf($owner),
            $boughtTypeIds,
        );
    }

    /**
     * @param array<int, int>               $stockByTypeId
     * @param array<int, OwnedBlueprintUse> $ownedBlueprints by product typeId
     * @param list<int>                     $blacklistTypeIds
     */
    private function plannedProductionWithBlacklistOf(IndustryProject $project, User $owner, array $stockByTypeId, array $ownedBlueprints, array $blacklistTypeIds): PlannedProduction
    {
        $blacklistedTypeIds = $this->resolveBlacklistedTypeIds($project->getBlacklistGroupIds(), $blacklistTypeIds);

        $structures = $this->structuresOf($owner);

        try {
            return $this->productionPlanning->planWithStructuresOf(new ProductionPlanRequest(
                $this->targetsOf($project, $stockByTypeId),
                $structures,
                $this->favoriteSystemsOf($owner),
                $blacklistedTypeIds,
                array_map(static fn (int $stock): Quantity => new Quantity($stock), $stockByTypeId),
                new StructureSelectionPolicy($project->isAcceptsOutsideFavoriteSystem(), new Isk($project->getMinimumOutsideSaving())),
                $ownedBlueprints,
                $this->workStructuresOf($project, $structures),
            ));
        } catch (\DomainException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param list<ProductionStructure> $structures
     *
     * @return array<string, ProductionStructure> by WorkType value, without the structures the owner no longer has
     */
    private function workStructuresOf(IndustryProject $project, array $structures): array
    {
        $structuresById = [];
        foreach ($structures as $structure) {
            $structuresById[$structure->id] = $structure;
        }

        $workStructures = [];
        foreach ($project->getWorkStations() as $workType => $choice) {
            if (isset($structuresById[$choice['structureId']])) {
                $workStructures[$workType] = $structuresById[$choice['structureId']];
            }
        }

        return $workStructures;
    }

    /**
     * @return array<int, OwnedBlueprintUse> by product typeId
     */
    public function ownedBlueprintsOf(User $owner): array
    {
        $usesByBlueprint = $this->originalBlueprintFinder->bestUsesOwnedBy($owner);
        if ([] === $usesByBlueprint) {
            return [];
        }

        $products = $this->activityProductRepository->findProductsForBlueprints(
            array_keys($usesByBlueprint),
            [IndustryActivityType::Manufacturing->value, IndustryActivityType::Reaction->value],
        );

        $usesByProduct = [];
        foreach ($products as $product) {
            $use = $usesByBlueprint[$product->getTypeId()];
            $productTypeId = $product->getProductTypeId();
            if (!isset($usesByProduct[$productTypeId]) || $use->isBetterThan($usesByProduct[$productTypeId])) {
                $usesByProduct[$productTypeId] = $use;
            }
        }

        return $usesByProduct;
    }

    /**
     * @return list<ProductionStructure>
     */
    public function structuresOf(User $owner): array
    {
        return $this->userStructures->configuredBy($this->idOf($owner));
    }

    public function favoriteSystemsOf(User $owner): FavoriteSystems
    {
        $settings = $this->settingsRepository->findOneBy(['user' => $owner]);

        return new FavoriteSystems(
            $settings?->getFavoriteManufacturingSystemId(),
            $settings?->getFavoriteReactionSystemId(),
            CourierHubSettings::resolve($settings),
        );
    }

    /**
     * @param array<int, int> $stockByTypeId
     * @return list<PlanTarget>
     */
    private function targetsOf(IndustryProject $project, array $stockByTypeId): array
    {
        $targets = [];
        foreach ($project->getItems() as $item) {
            $runs = $this->runsLeftAfterStock($item, $stockByTypeId);
            if (0 === $runs) {
                continue;
            }
            $targets[] = new PlanTarget($item->getTypeId(), new Runs($runs), $this->targetEfficiencyOf($item));
        }

        return $targets;
    }

    private function targetEfficiencyOf(IndustryProjectProduct $item): BlueprintEfficiency
    {
        $isReaction = $this->activityProductRepository->producerOf($item->getTypeId())->getActivityId() === IndustryActivityType::Reaction->value;
        $materialEfficiency = $isReaction ? ProductionPlanner::REACTION_EFFICIENCY_LEVEL : $item->getMeLevel();
        $timeEfficiency = $isReaction ? ProductionPlanner::REACTION_EFFICIENCY_LEVEL : $item->getTeLevel();

        return new BlueprintEfficiency(new MaterialEfficiency($materialEfficiency), new TimeEfficiency($timeEfficiency));
    }

    /**
     * @param array<int, int> $stockByTypeId
     */
    private function runsLeftAfterStock(IndustryProjectProduct $item, array $stockByTypeId): int
    {
        $unitsPerRun = $this->activityProductRepository->producerOf($item->getTypeId())->getQuantity();
        $stock = isset($stockByTypeId[$item->getTypeId()]) ? $stockByTypeId[$item->getTypeId()] : 0;

        return max(0, (int) ceil(($item->getRuns() * $unitsPerRun - $stock) / $unitsPerRun));
    }

    /**
     * @return array<int, IndustryActivityProduct> by product typeId
     */
    private function producersOf(Plan $plan): array
    {
        $producers = [];
        foreach ($plan->jobs as $job) {
            $producers[$job->productTypeId] ??= $this->activityProductRepository->producerOf($job->productTypeId);
        }

        return $producers;
    }

    /**
     * @param array<int, IndustryActivityProduct> $producers
     * @param array<int, string>                  $typeNames
     * @return array<int|string, JobLine>
     */
    private function jobLinesOf(PlannedProduction $plannedProduction, array $producers, array $typeNames, User $owner): array
    {
        $plan = $plannedProduction->plan;
        $componentJobIds = [];
        foreach ($plan->intermediates as $intermediate) {
            foreach ($intermediate->jobs as $job) {
                $componentJobIds[spl_object_id($job)] = true;
            }
        }

        $firstConsumerByMaterial = $this->firstConsumerByMaterialOf($plan);

        $lines = [];
        foreach ($plan->jobs as $job) {
            $isComponent = isset($componentJobIds[spl_object_id($job)]);
            $structureChoice = $plannedProduction->structureChoiceFor($job->productTypeId, $job->activity)
                ?? throw new \LogicException(\sprintf('Job of product %d has no structure choice.', $job->productTypeId));
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
                'plannedStructureId' => $structureChoice->structure?->id,
                'plannedStructureStatus' => $this->plannedStructureStatusOf($structureChoice->status),
                'surplusQuantity' => $isComponent ? $plan->intermediates[$job->productTypeId]->surplus->value : null,
            ];
        }

        return $lines + $this->copyLinesOf($lines, $producers, $owner, $plannedProduction->copyStructureChoice ?? throw new \LogicException('A planned production has no copy structure choice.'));
    }

    /**
     * @return array<int, int> consumer product typeId by material typeId
     */
    private function firstConsumerByMaterialOf(Plan $plan): array
    {
        $firstConsumerByMaterial = [];
        foreach ($plan->jobs as $consumer) {
            foreach (array_keys($consumer->materials) as $materialTypeId) {
                $firstConsumerByMaterial[$materialTypeId] ??= $consumer->productTypeId;
            }
        }

        return $firstConsumerByMaterial;
    }

    /**
     * @param array<int, int> $stockByTypeId
     * @return array<int, int> by typeId
     */
    private function consumedStockByTypeId(IndustryProject $project, Plan $plan, array $stockByTypeId): array
    {
        $finalDemandByTypeId = [];
        foreach ($project->getItems() as $item) {
            $demand = $item->getRuns() * $this->activityProductRepository->producerOf($item->getTypeId())->getQuantity();
            $finalDemandByTypeId[$item->getTypeId()] = isset($finalDemandByTypeId[$item->getTypeId()]) ? $finalDemandByTypeId[$item->getTypeId()] + $demand : $demand;
        }

        $consumedStockByTypeId = [];
        foreach ($finalDemandByTypeId as $typeId => $demand) {
            if (isset($stockByTypeId[$typeId])) {
                $consumedStockByTypeId[$typeId] = min($stockByTypeId[$typeId], $demand);
            }
        }
        foreach ($plan->intermediates as $typeId => $intermediate) {
            $consumedStockByTypeId[$typeId] = $intermediate->consumedStock->value;
        }

        return $consumedStockByTypeId;
    }

    /**
     * @param list<IndustryProjectBomLine> $plannedLines
     * @param array<int, int>              $consumedStockByTypeId
     */
    private function addConsumedStockToTheLargestJobLines(array $plannedLines, array $consumedStockByTypeId): void
    {
        $largestLineByTypeId = [];
        foreach ($plannedLines as $line) {
            if (!$line->isManufacturingOrReactionJob() || $line->isCoveredByStock() || !isset($consumedStockByTypeId[$line->getTypeId()])) {
                continue;
            }
            $largest = $largestLineByTypeId[$line->getTypeId()] ?? null;
            if (null === $largest || $line->getRuns() > $largest->getRuns()) {
                $largestLineByTypeId[$line->getTypeId()] = $line;
            }
        }

        foreach ($largestLineByTypeId as $typeId => $line) {
            $line->setRequiredQuantity($line->getRequiredQuantity() + $consumedStockByTypeId[$typeId]);
        }
    }

    /**
     * @param list<int>                     $coveredIntermediateTypeIds
     * @param array<int, int>               $stockByTypeId
     * @param array<int, int>               $consumedStockByTypeId
     * @param array<int, OwnedBlueprintUse> $ownedBlueprints         by product typeId
     * @param array<int, string>            $typeNames
     * @return list<IndustryProjectBomLine>
     */
    private function coveredLinesOf(IndustryProject $project, Plan $plan, array $coveredIntermediateTypeIds, array $stockByTypeId, array $consumedStockByTypeId, array $ownedBlueprints, array $typeNames): array
    {
        $firstConsumerByMaterial = $this->firstConsumerByMaterialOf($plan);

        $lines = [];
        foreach ($coveredIntermediateTypeIds as $typeId) {
            $efficiency = $this->isReaction($typeId)
                ? new BlueprintEfficiency(new MaterialEfficiency(ProductionPlanner::REACTION_EFFICIENCY_LEVEL), new TimeEfficiency(ProductionPlanner::REACTION_EFFICIENCY_LEVEL))
                : (isset($ownedBlueprints[$typeId])
                    ? $ownedBlueprints[$typeId]->efficiency
                    : new BlueprintEfficiency(new MaterialEfficiency(ProductionPlanner::MANUFACTURED_INTERMEDIATE_ME), new TimeEfficiency(ProductionPlanner::MANUFACTURED_INTERMEDIATE_TE)));
            $lines[] = $this->coveredLineOf(
                $typeId,
                $typeNames[$typeId],
                'component',
                $efficiency,
                $consumedStockByTypeId[$typeId],
            )
                ->setParentTypeId($firstConsumerByMaterial[$typeId] ?? throw new \LogicException(\sprintf('Intermediate %d is consumed by no job of the plan.', $typeId)))
                ->setSurplusQuantity(0);
        }

        foreach ($project->getItems() as $item) {
            if (0 !== $this->runsLeftAfterStock($item, $stockByTypeId)) {
                continue;
            }
            $efficiency = !$this->isReaction($item->getTypeId()) && isset($ownedBlueprints[$item->getTypeId()])
                ? $ownedBlueprints[$item->getTypeId()]->efficiency
                : $this->targetEfficiencyOf($item);
            $lines[] = $this->coveredLineOf($item->getTypeId(), $item->getTypeName(), 'final', $efficiency, $consumedStockByTypeId[$item->getTypeId()]);
        }

        return $lines;
    }

    private function coveredLineOf(int $typeId, string $typeName, string $jobGroup, BlueprintEfficiency $efficiency, int $consumedStock): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity($consumedStock)
            ->setIsJob(true)
            ->setJobGroup($jobGroup)
            ->setActivityType($this->isReaction($typeId) ? 'reaction' : 'manufacturing')
            ->setMeLevel($efficiency->materialEfficiency->value)
            ->setTeLevel($efficiency->timeEfficiency->value)
            ->setRuns(0)
            ->setPlannedStructureId(null)
            ->setPlannedStructureStatus(null);
    }

    private function isReaction(int $typeId): bool
    {
        return $this->activityProductRepository->producerOf($typeId)->getActivityId() === IndustryActivityType::Reaction->value;
    }

    /**
     * @param list<JobLine>                       $jobLines
     * @param array<int, IndustryActivityProduct> $producers
     * @return array<string, JobLine>
     */
    private function copyLinesOf(array $jobLines, array $producers, User $owner, StructureChoice $copyStructureChoice): array
    {
        $ownedOriginalTypeIds = array_flip($this->originalBlueprintFinder->typeIdsOwnedBy($owner));
        $blueprintTypeIds = array_map(static fn (IndustryActivityProduct $producer): int => $producer->getTypeId(), $producers);
        $copying = IndustryActivityType::Copying->value;
        $copyActivities = $this->activityRepository->findByTypeIdsAndActivityIds(array_values($blueprintTypeIds), [$copying]);

        $jobLinesByProduct = [];
        foreach ($jobLines as $jobLine) {
            $productTypeId = $jobLine['typeId'];
            if (!isset($copyActivities[$blueprintTypeIds[$productTypeId] . '-' . $copying]) || isset($ownedOriginalTypeIds[$blueprintTypeIds[$productTypeId]])) {
                continue;
            }
            $jobLinesByProduct[$productTypeId][] = $jobLine;
        }

        $copyLines = [];
        foreach ($jobLinesByProduct as $productTypeId => $productJobLines) {
            $copies = $this->copiesFirstFitDecreasing($productJobLines, $this->blueprintCopyMaxRunsOf($productTypeId, $blueprintTypeIds[$productTypeId]));
            foreach ($copies as $copyIndex => ['runs' => $copyRuns, 'firstJobLine' => $firstJobLine]) {
                $copyLines[self::copyLineKeyOf($productTypeId, $copyIndex)] = [
                    'typeId' => $productTypeId,
                    'typeName' => $firstJobLine['typeName'],
                    'runs' => $copyRuns,
                    'meLevel' => 0,
                    'teLevel' => 0,
                    'activityType' => 'copy',
                    'jobGroup' => 'blueprint',
                    'parentTypeId' => $firstJobLine['parentTypeId'],
                    'outputPerRun' => $firstJobLine['outputPerRun'],
                    ...$this->plannedStructureOf($copyStructureChoice),
                    'surplusQuantity' => null,
                ];
            }
        }

        return $copyLines;
    }

    /**
     * @param non-empty-list<JobLine> $productJobLines
     * @return list<array{runs: int, firstJobLine: JobLine}>
     */
    private function copiesFirstFitDecreasing(array $productJobLines, int $blueprintCopyMaxRuns): array
    {
        usort($productJobLines, static fn (array $a, array $b): int => $b['runs'] <=> $a['runs']);

        $copies = [];
        foreach ($productJobLines as $jobLine) {
            $fittingCopyIndex = null;
            foreach ($copies as $copyIndex => $copy) {
                if ($copy['runs'] + $jobLine['runs'] <= $blueprintCopyMaxRuns) {
                    $fittingCopyIndex = $copyIndex;
                    break;
                }
            }
            if (null === $fittingCopyIndex) {
                $copies[] = ['runs' => $jobLine['runs'], 'firstJobLine' => $jobLine];
                continue;
            }
            $copies[$fittingCopyIndex] = ['runs' => $copies[$fittingCopyIndex]['runs'] + $jobLine['runs'], 'firstJobLine' => $copies[$fittingCopyIndex]['firstJobLine']];
        }

        return $copies;
    }

    private function blueprintCopyMaxRunsOf(int $productTypeId, int $blueprintTypeId): int
    {
        $recipe = $this->blueprintCatalog->recipeFor($productTypeId)
            ?? throw new \UnexpectedValueException(\sprintf('Blueprint %d has no recipe in the SDE: its blueprint copy max runs is unknown.', $blueprintTypeId));

        return $recipe->maxProductionLimit->value;
    }

    private static function copyLineKeyOf(int $productTypeId, int $copyIndex): string
    {
        return \sprintf(self::COPY_LINE_KEY_FORMAT, $productTypeId, $copyIndex);
    }

    private static function t1InventionCopyLineKeyOf(int $t1ProductTypeId, int $copyIndex): string
    {
        return \sprintf('%d:copy:invention:%d', $t1ProductTypeId, $copyIndex);
    }

    /**
     * @param array<int|string, JobLine> $jobAggregation
     */
    private static function dropCopyLinesOf(array &$jobAggregation, int $productTypeId): void
    {
        foreach (array_keys($jobAggregation) as $key) {
            if (1 === preg_match(\sprintf('/^%s$/', \sprintf(self::COPY_LINE_KEY_FORMAT, $productTypeId, self::COPY_LINE_INDEX_PATTERN)), (string) $key)) {
                unset($jobAggregation[$key]);
            }
        }
    }

    /**
     * @return array{plannedStructureId: string|null, plannedStructureStatus: string}
     */
    private function plannedStructureOf(StructureChoice $choice): array
    {
        return [
            'plannedStructureId' => $choice->structure?->id,
            'plannedStructureStatus' => $this->plannedStructureStatusOf($choice->status),
        ];
    }

    private function plannedStructureStatusOf(StructureChoiceStatus $status): string
    {
        return match ($status) {
            StructureChoiceStatus::Assigned => 'assigned',
            StructureChoiceStatus::BestInFavoriteSystem => 'best_in_favorite_system',
            StructureChoiceStatus::BestConfigured => 'best_configured',
            StructureChoiceStatus::BestOutsideFavoriteSystem => 'best_outside_favorite_system',
            StructureChoiceStatus::BestByTotalCost => 'best_by_total_cost',
            StructureChoiceStatus::NotConfigured => 'not_configured',
        };
    }

    private function activityTypeOf(ActivityKind $activity): string
    {
        return match ($activity) {
            ActivityKind::Manufacturing => 'manufacturing',
            ActivityKind::Reaction => 'reaction',
            ActivityKind::Copying, ActivityKind::Invention, ActivityKind::ResearchMaterial, ActivityKind::ResearchTime => throw new \LogicException(\sprintf('A plan has no %s job.', $activity->name)),
        };
    }

    /**
     * @param array<int, array{typeName: string, quantity: int}> $materialAggregation
     * @param array<int|string, JobLine> $jobAggregation
     * @param array<int, int> $declaredRunsByProductTypeId
     * @param array<int, int> $decryptorTypeIdByProductTypeId
     */
    private function replaceT2BlueprintCopiesByInventions(array &$materialAggregation, array &$jobAggregation, StructureChoice $copyStructureChoice, array $declaredRunsByProductTypeId, bool $excludesInvention, array $decryptorTypeIdByProductTypeId): void
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
            if ($excludesInvention) {
                self::dropCopyLinesOf($jobAggregation, $productTypeId);
                continue;
            }
            $declaredRuns = \array_key_exists($productTypeId, $declaredRunsByProductTypeId) ? $declaredRunsByProductTypeId[$productTypeId] : 0;
            $runsToInvent = $manufacturingRunsByProduct[$productTypeId] - min($declaredRuns, $manufacturingRunsByProduct[$productTypeId]);
            if ($runsToInvent === 0) {
                self::dropCopyLinesOf($jobAggregation, $productTypeId);
                continue;
            }
            if ($invention['probability'] === null || $invention['probability'] <= 0 || $invention['baseRuns'] <= 0) {
                throw new InventionProbabilityMissingException($productTypeId, $invention['t1BlueprintTypeId']);
            }
            $t1ProductTypeId = $this->t1ProductTypeIdOf($invention['t1BlueprintTypeId']);
            $decryptor = $this->decryptorChosenFor($productTypeId, $decryptorTypeIdByProductTypeId);
            $inventionsByProduct[$productTypeId] = ['recipe' => $invention, 'baseProbability' => $invention['probability'], 't1ProductTypeId' => $t1ProductTypeId, 'runsToInvent' => $runsToInvent, 'decryptor' => $decryptor];
            $inventionTypeIds[] = $t1ProductTypeId ?? $invention['t1BlueprintTypeId'];
            if (null !== $decryptor) {
                $inventionTypeIds[] = $decryptor->typeId;
            }
            foreach ($invention['materials'] as $datacore) {
                $inventionTypeIds[] = $datacore['typeId'];
            }
        }

        if ($inventionsByProduct === []) {
            return;
        }

        $typeNames = $this->sdeTypeNames($inventionTypeIds);

        $t1Copies = [];
        foreach ($inventionsByProduct as $productTypeId => ['recipe' => $invention, 'baseProbability' => $baseProbability, 't1ProductTypeId' => $t1ProductTypeId, 'runsToInvent' => $runsToInvent, 'decryptor' => $decryptor]) {
            $firstManufacturingJob = $firstManufacturingJobByProduct[$productTypeId];
            $attempts = InventionAttempts::needed(new Runs($runsToInvent), $invention['baseRuns'], $baseProbability, $decryptor);

            self::dropCopyLinesOf($jobAggregation, $productTypeId);
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
                'plannedStructureId' => null,
                'plannedStructureStatus' => null,
                'surplusQuantity' => null,
            ];

            if (null !== $t1ProductTypeId) {
                if (isset($t1Copies[$t1ProductTypeId])) {
                    $t1Copies[$t1ProductTypeId]['attempts'] += $attempts;
                } else {
                    $t1Copies[$t1ProductTypeId] = [
                        'attempts' => $attempts,
                        't1BlueprintTypeId' => $invention['t1BlueprintTypeId'],
                        'parentTypeId' => $firstManufacturingJob['parentTypeId'],
                    ];
                }
            }

            $consumed = [];
            if (null === $t1ProductTypeId) {
                $consumed[] = ['typeId' => $invention['t1BlueprintTypeId'], 'quantity' => $attempts];
            }
            foreach ($invention['materials'] as $datacore) {
                $consumed[] = ['typeId' => $datacore['typeId'], 'quantity' => $datacore['quantity'] * $attempts];
            }
            if (null !== $decryptor) {
                $consumed[] = ['typeId' => $decryptor->typeId, 'quantity' => $attempts];
            }
            foreach ($consumed as ['typeId' => $consumedTypeId, 'quantity' => $quantity]) {
                if (isset($materialAggregation[$consumedTypeId])) {
                    $materialAggregation[$consumedTypeId]['quantity'] += $quantity;
                } else {
                    $materialAggregation[$consumedTypeId] = [
                        'typeName' => $typeNames[$consumedTypeId],
                        'quantity' => $quantity,
                    ];
                }
            }
        }

        foreach ($t1Copies as $t1ProductTypeId => ['attempts' => $attempts, 't1BlueprintTypeId' => $t1BlueprintTypeId, 'parentTypeId' => $parentTypeId]) {
            $copyRuns = Runs::splitUnderCap(new Runs($attempts), new Runs($this->blueprintCopyMaxRunsOf($t1ProductTypeId, $t1BlueprintTypeId)));
            foreach ($copyRuns as $copyIndex => $copyRunsOfLine) {
                $jobAggregation[self::t1InventionCopyLineKeyOf($t1ProductTypeId, $copyIndex)] = [
                    'typeId' => $t1ProductTypeId,
                    'typeName' => $typeNames[$t1ProductTypeId],
                    'runs' => $copyRunsOfLine->value,
                    'meLevel' => 0,
                    'teLevel' => 0,
                    'activityType' => 'copy',
                    'jobGroup' => 'blueprint',
                    'parentTypeId' => $parentTypeId,
                    'outputPerRun' => 1,
                    ...$this->plannedStructureOf($copyStructureChoice),
                    'surplusQuantity' => null,
                ];
            }
        }
    }

    /**
     * @param array<int, int> $decryptorTypeIdByProductTypeId
     */
    private function decryptorChosenFor(int $productTypeId, array $decryptorTypeIdByProductTypeId): ?Decryptor
    {
        $decryptorTypeId = $decryptorTypeIdByProductTypeId[$productTypeId] ?? null;
        if (null === $decryptorTypeId) {
            return null;
        }

        return $this->decryptors->ofType($decryptorTypeId)
            ?? throw new \RuntimeException(\sprintf('The decryptor %d chosen for the invention of type %d is not in the SDE.', $decryptorTypeId, $productTypeId));
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

    private function t1ProductTypeIdOf(int $t1BlueprintTypeId): ?int
    {
        $manufacturing = IndustryActivityType::Manufacturing->value;
        $products = $this->activityProductRepository->findProductsForBlueprints([$t1BlueprintTypeId], [$manufacturing]);
        $product = $products[$t1BlueprintTypeId . '-' . $manufacturing] ?? null;

        return $product?->getProductTypeId();
    }
}
