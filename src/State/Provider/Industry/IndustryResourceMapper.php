<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use App\ApiResource\Industry\CharacterSkillResource;
use App\ApiResource\Industry\ProjectResource;
use App\ApiResource\Industry\ProjectStepResource;
use App\ApiResource\Industry\StepPurchaseResource;
use App\ApiResource\Industry\StructureConfigResource;
use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepPurchase;
use App\Entity\IndustryStructureConfig;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CachedIndustryJobRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\InventionService;

class IndustryResourceMapper
{
    /** @var array<string, array<string, array{skills: array<int, int>, name: string, time: int|null}>> */
    /** @var array<string, array{skills: array<int, int>|null, name: string|null, time?: int|null}> */
    private array $bestCharacterCache = [];

    /** @var array<int, array<array{esiJobId: int, runs: int, status: string, characterName: string}>>|null */
    private ?array $similarJobsByBlueprint = null;

    /** @var array<int, bool> */
    private array $isT2Cache = [];

    public function __construct(
        private readonly IndustryCalculationService $calculationService,
        private readonly CachedCharacterSkillRepository $skillRepository,
        private readonly CachedIndustryJobRepository $industryJobRepository,
        private readonly InventionService $inventionService,
    ) {
    }

    public function structureToResource(IndustryStructureConfig $structure): StructureConfigResource
    {
        $resource = new StructureConfigResource();
        $resource->id = $structure->getId()->toRfc4122();
        $resource->name = $structure->getName();
        $resource->locationId = $structure->getLocationId();
        $resource->solarSystemId = $structure->getSolarSystemId();
        $resource->securityType = $structure->getSecurityType();
        $resource->structureType = $structure->getStructureType();
        $resource->rigs = $structure->getRigs();
        $resource->isDefault = $structure->isDefault();
        $resource->isCorporationStructure = $structure->isCorporationStructure();
        $resource->manufacturingMaterialBonus = $structure->getManufacturingMaterialBonus();
        $resource->reactionMaterialBonus = $structure->getReactionMaterialBonus();
        $resource->manufacturingTimeBonus = $structure->getManufacturingTimeBonus();
        $resource->reactionTimeBonus = $structure->getReactionTimeBonus();
        $resource->createdAt = $structure->getCreatedAt()->format('c');

        return $resource;
    }

    /**
     * @param CachedCharacterSkill[] $skills
     */
    public function characterSkillsToResource(Character $character, array $skills): CharacterSkillResource
    {
        $resource = new CharacterSkillResource();
        $resource->characterId = $character->getId()?->toRfc4122() ?? '';
        $resource->characterName = $character->getName();

        $hasEsi = false;
        $lastSync = null;

        foreach ($skills as $skill) {
            match ($skill->getSkillId()) {
                CachedCharacterSkill::SKILL_INDUSTRY => $resource->industry = $skill->getLevel(),
                CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => $resource->advancedIndustry = $skill->getLevel(),
                CachedCharacterSkill::SKILL_REACTIONS => $resource->reactions = $skill->getLevel(),
                default => null,
            };
            if (!$skill->isManual()) {
                $hasEsi = true;
            }
            if ($lastSync === null || $skill->getCachedAt() > $lastSync) {
                $lastSync = $skill->getCachedAt();
            }
        }

        $resource->source = $hasEsi ? 'esi' : (empty($skills) ? 'none' : 'manual');
        $resource->lastSyncAt = $lastSync?->format(\DateTimeInterface::ATOM);

        return $resource;
    }

    public function purchaseToResource(IndustryStepPurchase $purchase): StepPurchaseResource
    {
        $resource = new StepPurchaseResource();
        $resource->id = (string) $purchase->getId();
        $resource->stepId = (string) $purchase->getStep()->getId();
        $resource->typeId = $purchase->getTypeId();
        $resource->typeName = $this->calculationService->resolveTypeName($purchase->getTypeId());
        $resource->quantity = $purchase->getQuantity();
        $resource->unitPrice = $purchase->getUnitPrice();
        $resource->totalPrice = $purchase->getTotalPrice();
        $resource->source = $purchase->getSource();
        $resource->transactionId = $purchase->getTransaction() ? (string) $purchase->getTransaction()->getId() : null;
        $resource->createdAt = $purchase->getCreatedAt()->format('c');

        return $resource;
    }

    public function projectToResource(IndustryProject $project): ProjectResource
    {
        $resource = new ProjectResource();
        $resource->id = (string) $project->getId();
        $resource->productTypeId = $project->getProductTypeId();
        $resource->productTypeName = $this->calculationService->resolveTypeName($project->getProductTypeId());

        $productTypeId = $project->getProductTypeId();
        if (!isset($this->isT2Cache[$productTypeId])) {
            $this->isT2Cache[$productTypeId] = $this->inventionService->isT2($productTypeId);
        }
        $resource->isT2 = $this->isT2Cache[$productTypeId];

        $resource->name = $project->getName();
        $resource->runs = $project->getRuns();
        $resource->meLevel = $project->getMeLevel();
        $resource->teLevel = $project->getTeLevel();
        $resource->maxJobDurationDays = $project->getMaxJobDurationDays();
        $resource->status = $project->getStatus();
        $resource->personalUse = $project->isPersonalUse();
        $resource->bpoCost = $project->getBpoCost();
        $resource->materialCost = $project->getMaterialCost();
        $resource->transportCost = $project->getTransportCost();
        $resource->taxAmount = $project->getTaxAmount();
        $resource->sellPrice = $project->getSellPrice();
        $resource->jobsCost = $project->getJobsCost();
        $resource->estimatedJobCost = $project->getEstimatedJobCost();
        $resource->estimatedMaterialCost = $project->getEstimatedMaterialCost();
        $resource->estimatedSellPrice = $project->getEstimatedSellPrice();
        $resource->estimatedSellPriceSource = $project->getEstimatedSellPriceSource();
        $resource->estimatedTaxAmount = $project->getEstimatedTaxAmount();

        // Calculate total cost and profit using estimated values as fallback
        // when "real" values haven't been filled by the user
        $effectiveTaxAmount = $project->getTaxAmount() ?? $project->getEstimatedTaxAmount() ?? 0.0;
        $effectiveJobsCost = $project->getJobsCost() > 0 ? $project->getJobsCost() : ($project->getEstimatedJobCost() ?? 0.0);
        $effectiveSellPrice = $project->getSellPrice() ?? $project->getEstimatedSellPrice();

        $effectiveMaterialCost = $project->getMaterialCost() ?? $project->getEstimatedMaterialCost() ?? 0.0;

        $resource->totalCost = ($project->getBpoCost() ?? 0)
            + $effectiveMaterialCost
            + ($project->getTransportCost() ?? 0)
            + $effectiveJobsCost
            + $effectiveTaxAmount;

        if ($effectiveSellPrice !== null && !$project->isPersonalUse()) {
            $resource->profit = $effectiveSellPrice - $resource->totalCost;
            $resource->profitPercent = $resource->totalCost > 0
                ? ($resource->profit / $resource->totalCost) * 100
                : null;
        } else {
            $resource->profit = $project->getProfit();
            $resource->profitPercent = $project->getProfitPercent();
        }
        $resource->notes = $project->getNotes();
        $resource->inventionMaterials = $project->getInventionMaterials();
        $resource->jobsStartDate = $project->getJobsStartDate()?->format('c');
        $resource->completedAt = $project->getCompletedAt()?->format('c');
        $resource->createdAt = $project->getCreatedAt()->format('c');

        // Root products from depth-0 steps
        $rootProductsByType = [];
        foreach ($project->getSteps() as $step) {
            if ($step->getDepth() === 0 && $step->getActivityType() !== 'copy') {
                $typeId = $step->getProductTypeId();
                if (isset($rootProductsByType[$typeId])) {
                    $rootProductsByType[$typeId]['runs'] += $step->getRuns();
                    $rootProductsByType[$typeId]['count']++;
                } else {
                    $rootProductsByType[$typeId] = [
                        'typeId' => $typeId,
                        'typeName' => $this->calculationService->resolveTypeName($typeId),
                        'runs' => $step->getRuns(),
                        'meLevel' => $step->getMeLevel(),
                        'teLevel' => $step->getTeLevel(),
                        'count' => 1,
                    ];
                }
            }
        }
        $resource->rootProducts = array_values($rootProductsByType);

        // Display name: custom name, or product name with count
        if ($project->getName() !== null) {
            $resource->displayName = $project->getName();
        } elseif (count($rootProductsByType) === 1) {
            $info = array_values($rootProductsByType)[0];
            $resource->displayName = $info['count'] > 1
                ? $info['typeName'] . ' ×' . $info['count']
                : $info['typeName'];
        } elseif (count($rootProductsByType) > 1) {
            $names = array_map(fn ($p) => $p['typeName'], array_values($rootProductsByType));
            $resource->displayName = implode(' + ', $names);
        } else {
            $resource->displayName = $this->calculationService->getProjectDisplayName($project);
        }

        return $resource;
    }

    public function stepToResource(IndustryProjectStep $step): ProjectStepResource
    {
        $resource = new ProjectStepResource();
        $resource->id = (string) $step->getId();
        $resource->blueprintTypeId = $step->getBlueprintTypeId();
        $resource->productTypeId = $step->getProductTypeId();
        $resource->productTypeName = $this->calculationService->resolveTypeName($step->getProductTypeId());
        $resource->quantity = $step->getQuantity();
        $resource->runs = $step->getRuns();
        $resource->depth = $step->getDepth();
        $resource->activityType = $step->getActivityType();
        $resource->sortOrder = $step->getSortOrder();
        $resource->splitGroupId = $step->getSplitGroupId();
        $resource->splitIndex = $step->getSplitIndex();
        $resource->totalGroupRuns = $step->getTotalGroupRuns();
        $resource->purchased = $step->isPurchased();
        $resource->inStockQuantity = $step->getInStockQuantity();
        $resource->meLevel = $step->getMeLevel();
        $resource->teLevel = $step->getTeLevel();
        $resource->jobMatchMode = $step->getJobMatchMode();

        // Structure config
        $structureConfig = $step->getStructureConfig();
        $resource->structureConfigId = $structureConfig ? (string) $structureConfig->getId() : null;

        // Dynamic calculations
        $structureData = $this->calculationService->getStructureBonusForStep($step);
        $resource->structureConfigName = $structureData['name'];
        $resource->structureMaterialBonus = $structureData['materialBonus']['total'];
        $resource->structureTimeBonus = $structureData['timeBonus'];

        // Calculate time with best character's skills
        $bestChar = $this->findBestCharacterForStep($step);
        $resource->timePerRun = $this->calculationService->calculateTimePerRun($step, $bestChar['skills'] ?? null);
        $resource->recommendedCharacterName = $bestChar['name'] ?? null;

        // Job matches
        $resource->jobMatches = [];
        $currentLocationId = $structureConfig?->getLocationId();
        $unconfiguredFacilityName = null;
        foreach ($step->getJobMatches() as $match) {
            $resource->jobMatches[] = [
                'id' => (string) $match->getId(),
                'esiJobId' => $match->getEsiJobId(),
                'cost' => $match->getCost(),
                'status' => $match->getStatus(),
                'endDate' => $match->getEndDate()?->format('c'),
                'runs' => $match->getRuns(),
                'characterName' => $match->getCharacterName(),
                'matchedAt' => $match->getMatchedAt()->format('c'),
                'facilityId' => $match->getFacilityId(),
                'facilityName' => $match->getFacilityName(),
            ];

            // Detect unconfigured facility (job ran in unknown facility)
            if ($unconfiguredFacilityName === null) {
                $facilityId = $match->getFacilityId();
                if ($facilityId !== null && $currentLocationId !== $facilityId) {
                    $unconfiguredFacilityName = $match->getFacilityName() ?? "Facility #{$facilityId}";
                }
            }
        }

        // Determine facility info type
        if ($unconfiguredFacilityName !== null) {
            $resource->facilityInfoType = 'unconfigured';
            $resource->actualFacilityName = $unconfiguredFacilityName;
        } elseif (!empty($resource->jobMatches) && $step->getActivityType() !== 'copy') {
            $user = $step->getProject()->getUser();
            $isReaction = $step->getActivityType() === 'reaction';
            $bestData = $this->calculationService->getBestStructureBonusForProduct($user, $step->getProductTypeId(), $isReaction);

            if ($bestData['materialBonus']['total'] > $structureData['materialBonus']['total'] + 0.01) {
                $resource->facilityInfoType = 'suboptimal';
                $resource->bestStructureName = $bestData['name'];
                $resource->bestMaterialBonus = $bestData['materialBonus']['total'];
            }
        }
        $resource->jobsCost = $step->getJobsCost();

        // Purchases
        $resource->purchases = [];
        foreach ($step->getPurchases() as $purchase) {
            $resource->purchases[] = [
                'id' => (string) $purchase->getId(),
                'typeId' => $purchase->getTypeId(),
                'typeName' => $this->calculationService->resolveTypeName($purchase->getTypeId()),
                'quantity' => $purchase->getQuantity(),
                'unitPrice' => $purchase->getUnitPrice(),
                'totalPrice' => $purchase->getTotalPrice(),
                'source' => $purchase->getSource(),
                'transactionId' => $purchase->getTransaction() ? (string) $purchase->getTransaction()->getId() : null,
                'createdAt' => $purchase->getCreatedAt()->format('c'),
            ];
        }
        $resource->purchasesCost = $step->getPurchasesCost();

        // Sum purchased quantities (only matching the step's product type)
        $purchasedQty = 0;
        foreach ($step->getPurchases() as $purchase) {
            if ($purchase->getTypeId() === $step->getProductTypeId()) {
                $purchasedQty += $purchase->getQuantity();
            }
        }
        $resource->purchasedQuantity = $purchasedQty;

        // Similar jobs (all ESI jobs with same blueprint, preloaded)
        if ($this->similarJobsByBlueprint !== null) {
            $resource->similarJobs = $this->similarJobsByBlueprint[$step->getBlueprintTypeId()] ?? [];
        }

        return $resource;
    }

    /**
     * Preload all ESI jobs for the blueprints in a project (1 query).
     */
    public function preloadSimilarJobs(IndustryProject $project): void
    {
        $blueprintTypeIds = [];
        foreach ($project->getSteps() as $step) {
            $blueprintTypeIds[$step->getBlueprintTypeId()] = true;
        }

        if (empty($blueprintTypeIds)) {
            $this->similarJobsByBlueprint = [];
            return;
        }

        $characterIds = [];
        foreach ($project->getUser()->getCharacters() as $character) {
            $characterIds[] = $character->getId();
        }

        $jobs = $this->industryJobRepository->findByBlueprintsAndCharacters(
            array_keys($blueprintTypeIds),
            $characterIds,
        );

        // Resolve facility names (deduplicated)
        $facilityNames = [];
        foreach ($jobs as $job) {
            $sid = $job->getStationId();
            if ($sid !== null && !isset($facilityNames[$sid])) {
                $facilityNames[$sid] = $this->calculationService->resolveFacilityName($sid);
            }
        }

        $indexed = [];
        foreach ($jobs as $job) {
            $sid = $job->getStationId();
            $indexed[$job->getBlueprintTypeId()][] = [
                'esiJobId' => $job->getJobId(),
                'runs' => $job->getRuns(),
                'status' => $job->getStatus(),
                'characterName' => $job->getCharacter()->getName(),
                'facilityId' => $sid,
                'facilityName' => $sid !== null ? ($facilityNames[$sid] ?? null) : null,
            ];
        }

        $this->similarJobsByBlueprint = $indexed;
    }

    /**
     * Find the best character (lowest time) for a step based on all relevant skills.
     * Includes Industry/AdvInd/Reactions and blueprint-specific science skills.
     *
     * @return array{skills: array<int, int>|null, name: string|null, time?: int|null}
     */
    private function findBestCharacterForStep(IndustryProjectStep $step): array
    {
        $user = $step->getProject()->getUser();
        $activityType = $step->getActivityType();

        if ($activityType === 'copy') {
            return ['skills' => null, 'name' => null];
        }

        // Cache includes blueprint ID since science skills vary per blueprint
        $cacheKey = ($user->getId()?->toRfc4122() ?? '') . '_' . $step->getBlueprintTypeId() . '_' . $activityType;

        if (isset($this->bestCharacterCache[$cacheKey])) {
            return $this->bestCharacterCache[$cacheKey];
        }

        // Get blueprint-specific science skill IDs
        $scienceSkillIds = $this->calculationService->getBlueprintScienceSkillIds(
            $step->getBlueprintTypeId(),
            $activityType
        );

        $bestSkills = null;
        $bestName = null;
        $bestMultiplier = PHP_FLOAT_MAX;

        foreach ($user->getCharacters() as $character) {
            // Load all skills if blueprint needs science skills, otherwise just industry skills
            if (!empty($scienceSkillIds)) {
                $charSkills = $this->skillRepository->findAllSkillsForCharacter($character);
            } else {
                $charSkills = $this->skillRepository->findIndustrySkillsForCharacter($character);
            }

            $skillLevels = [];
            foreach ($charSkills as $skill) {
                $skillLevels[$skill->getSkillId()] = $skill->getLevel();
            }

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
                $bestSkills = $skillLevels;
                $bestName = $character->getName();
            }
        }

        $result = ['skills' => $bestSkills, 'name' => $bestName];
        $this->bestCharacterCache[$cacheKey] = $result;

        return $result;
    }
}
