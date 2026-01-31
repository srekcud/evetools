<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\IndustryStructureConfig;
use App\Repository\CachedAssetRepository;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Exception\EsiApiException;
use App\Service\ESI\EsiClient;
use App\Service\ESI\MarketService;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\IndustryProjectService;
use App\Service\Industry\IndustryTreeService;
use App\Service\Sync\IndustryJobSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/industry')]
class IndustryController extends AbstractController
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectStepRepository $stepRepository,
        private readonly IndustryStructureConfigRepository $structureConfigRepository,
        private readonly CachedAssetRepository $cachedAssetRepository,
        private readonly CachedStructureRepository $cachedStructureRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly \App\Repository\Sde\MapSolarSystemRepository $solarSystemRepository,
        private readonly IndustryActivityProductRepository $activityProductRepository,
        private readonly IndustryProjectService $projectService,
        private readonly IndustryTreeService $treeService,
        private readonly IndustryBlacklistService $blacklistService,
        private readonly IndustryJobSyncService $jobSyncService,
        private readonly MarketService $marketService,
        private readonly EsiClient $esiClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/search', name: 'api_industry_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');
        if (strlen($query) < 2) {
            return new JsonResponse(['results' => []]);
        }

        // Search published types that have a manufacturing blueprint
        $conn = $this->entityManager->getConnection();
        $sql = <<<SQL
            SELECT DISTINCT t.type_id, t.type_name
            FROM sde_inv_types t
            INNER JOIN sde_industry_activity_products p ON p.product_type_id = t.type_id AND p.activity_id = 1
            WHERE t.published = true
              AND LOWER(t.type_name) LIKE LOWER(:query)
            ORDER BY t.type_name
            LIMIT 20
        SQL;

        $results = $conn->fetchAllAssociative($sql, ['query' => "%{$query}%"]);

        return new JsonResponse([
            'results' => array_map(fn (array $row) => [
                'typeId' => (int) $row['type_id'],
                'typeName' => $row['type_name'],
            ], $results),
        ]);
    }

    #[Route('/projects', name: 'api_industry_projects_create', methods: ['POST'])]
    public function createProject(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $data = json_decode($request->getContent(), true);
        $typeId = $data['typeId'] ?? null;
        $runs = $data['runs'] ?? 1;
        $meLevel = $data['meLevel'] ?? 0;
        $maxJobDurationDays = $data['maxJobDurationDays'] ?? 2.0;

        if ($typeId === null) {
            return new JsonResponse(['error' => 'typeId is required'], Response::HTTP_BAD_REQUEST);
        }

        if ($meLevel < 0 || $meLevel > 10) {
            return new JsonResponse(['error' => 'meLevel must be between 0 and 10'], Response::HTTP_BAD_REQUEST);
        }

        if ($runs < 1) {
            return new JsonResponse(['error' => 'runs must be at least 1'], Response::HTTP_BAD_REQUEST);
        }

        if ($maxJobDurationDays <= 0) {
            return new JsonResponse(['error' => 'maxJobDurationDays must be positive'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $project = $this->projectService->createProject($user, (int) $typeId, (int) $runs, (int) $meLevel, (float) $maxJobDurationDays);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(
            $this->projectService->getProjectSummary($project),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/projects', name: 'api_industry_projects_list', methods: ['GET'])]
    public function listProjects(): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $projects = $this->projectRepository->findByUser($user);

        $totalProfit = 0.0;
        $projectData = [];

        foreach ($projects as $project) {
            $summary = $this->projectService->getProjectSummary($project);
            $projectData[] = $summary;
            $totalProfit += $summary['profit'] ?? 0;
        }

        return new JsonResponse([
            'projects' => $projectData,
            'totalProfit' => $totalProfit,
        ]);
    }

    #[Route('/projects/{id}', name: 'api_industry_project_detail', methods: ['GET'])]
    public function getProject(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $project = $this->projectRepository->find(Uuid::fromString($id));

        if ($project === null || $project->getUser() !== $user) {
            return new JsonResponse(['error' => 'Project not found'], Response::HTTP_NOT_FOUND);
        }

        $summary = $this->projectService->getProjectSummary($project);

        // Build the tree structure from steps
        $steps = [];
        foreach ($project->getSteps() as $step) {
            $steps[] = $this->projectService->serializeStep($step);
        }

        // Also get the full SDE tree for display (with global blacklist applied)
        $excludedTypeIds = $this->blacklistService->resolveBlacklistedTypeIds($user);
        try {
            $tree = $this->treeService->buildProductionTree(
                $project->getProductTypeId(),
                $project->getRuns(),
                $project->getMeLevel(),
                $excludedTypeIds,
                $user,
            );
        } catch (\RuntimeException) {
            $tree = null;
        }

        $summary['steps'] = $steps;
        $summary['tree'] = $tree;

        return new JsonResponse($summary);
    }

    #[Route('/projects/{id}', name: 'api_industry_project_update', methods: ['PATCH'])]
    public function updateProject(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $project = $this->projectRepository->find(Uuid::fromString($id));

        if ($project === null || $project->getUser() !== $user) {
            return new JsonResponse(['error' => 'Project not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (array_key_exists('bpoCost', $data)) {
            $project->setBpoCost($data['bpoCost'] !== null ? (float) $data['bpoCost'] : null);
        }
        if (array_key_exists('materialCost', $data)) {
            $project->setMaterialCost($data['materialCost'] !== null ? (float) $data['materialCost'] : null);
        }
        if (array_key_exists('transportCost', $data)) {
            $project->setTransportCost($data['transportCost'] !== null ? (float) $data['transportCost'] : null);
        }
        if (array_key_exists('taxAmount', $data)) {
            $project->setTaxAmount($data['taxAmount'] !== null ? (float) $data['taxAmount'] : null);
        }
        if (array_key_exists('sellPrice', $data)) {
            $project->setSellPrice($data['sellPrice'] !== null ? (float) $data['sellPrice'] : null);
        }
        if (array_key_exists('notes', $data)) {
            $project->setNotes($data['notes']);
        }
        if (array_key_exists('status', $data) && in_array($data['status'], ['active', 'completed'], true)) {
            $project->setStatus($data['status']);
            if ($data['status'] === 'completed' && $project->getCompletedAt() === null) {
                $project->setCompletedAt(new \DateTimeImmutable());
            }
        }
        if (array_key_exists('personalUse', $data)) {
            $project->setPersonalUse((bool) $data['personalUse']);
        }
        if (array_key_exists('jobsStartDate', $data)) {
            $project->setJobsStartDate(
                $data['jobsStartDate'] !== null ? new \DateTimeImmutable($data['jobsStartDate']) : null
            );
        }

        // Handle runs change - requires regenerating steps
        $regenerateSteps = false;
        if (array_key_exists('runs', $data)) {
            $newRuns = (int) $data['runs'];
            if ($newRuns >= 1 && $newRuns !== $project->getRuns()) {
                $project->setRuns($newRuns);
                $regenerateSteps = true;
            }
        }

        // Handle maxJobDurationDays change - requires regenerating steps
        if (array_key_exists('maxJobDurationDays', $data)) {
            $newDuration = (float) $data['maxJobDurationDays'];
            if ($newDuration > 0 && $newDuration !== $project->getMaxJobDurationDays()) {
                $project->setMaxJobDurationDays($newDuration);
                $regenerateSteps = true;
            }
        }

        $this->entityManager->flush();

        // Regenerate steps if duration changed (this re-splits based on new max)
        if ($regenerateSteps) {
            $this->projectService->regenerateSteps($project);
        }

        return new JsonResponse($this->projectService->getProjectSummary($project));
    }

    #[Route('/projects/{id}', name: 'api_industry_project_delete', methods: ['DELETE'])]
    public function deleteProject(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $project = $this->projectRepository->find(Uuid::fromString($id));

        if ($project === null || $project->getUser() !== $user) {
            return new JsonResponse(['error' => 'Project not found'], Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($project);
        $this->entityManager->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/projects/{id}/steps/{stepId}', name: 'api_industry_step_update', methods: ['PATCH'])]
    public function updateStep(string $id, string $stepId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $project = $this->projectRepository->find(Uuid::fromString($id));

        if ($project === null || $project->getUser() !== $user) {
            return new JsonResponse(['error' => 'Project not found'], Response::HTTP_NOT_FOUND);
        }

        $step = $this->stepRepository->find(Uuid::fromString($stepId));

        if ($step === null || $step->getProject() !== $project) {
            return new JsonResponse(['error' => 'Step not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (array_key_exists('purchased', $data)) {
            $step->setPurchased((bool) $data['purchased']);
            if ($data['purchased']) {
                // Clear ESI job link when marking as purchased
                $step->clearJobData();
            }
        }

        // Clear job data action
        if (array_key_exists('clearJobData', $data) && $data['clearJobData'] === true) {
            $step->clearJobData();
        }

        // Manual job data editing
        if (array_key_exists('esiJobsTotalRuns', $data)) {
            $step->setEsiJobsTotalRuns($data['esiJobsTotalRuns'] !== null ? (int) $data['esiJobsTotalRuns'] : null);
            $step->setManualJobData(true);
        }
        if (array_key_exists('esiJobCost', $data)) {
            $step->setEsiJobCost($data['esiJobCost'] !== null ? (float) $data['esiJobCost'] : null);
            $step->setManualJobData(true);
        }
        if (array_key_exists('esiJobStatus', $data)) {
            $step->setEsiJobStatus($data['esiJobStatus']);
            $step->setManualJobData(true);
        }
        if (array_key_exists('esiJobCharacterName', $data)) {
            $step->setEsiJobCharacterName($data['esiJobCharacterName']);
            $step->setManualJobData(true);
        }
        if (array_key_exists('esiJobsCount', $data)) {
            $step->setEsiJobsCount($data['esiJobsCount'] !== null ? (int) $data['esiJobsCount'] : null);
            $step->setManualJobData(true);
        }
        // Explicit control over manual flag
        if (array_key_exists('manualJobData', $data)) {
            $step->setManualJobData((bool) $data['manualJobData']);
        }

        // Update step runs (recalculates quantity based on blueprint output)
        if (array_key_exists('runs', $data)) {
            $newRuns = (int) $data['runs'];
            if ($newRuns >= 1) {
                $step->setRuns($newRuns);
                // Update quantity based on runs (for reactions/manufacturing, 1 run = 1 product typically)
                // We need to get the product quantity per run from the blueprint
                $quantityPerRun = $this->getQuantityPerRun($step->getBlueprintTypeId(), $step->getActivityType());
                $step->setQuantity($newRuns * $quantityPerRun);
            }
        }

        $this->entityManager->flush();

        return new JsonResponse($this->projectService->serializeStep($step));
    }

    #[Route('/projects/{id}/steps', name: 'api_industry_step_create', methods: ['POST'])]
    public function createStep(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $project = $this->projectRepository->find(Uuid::fromString($id));

        if ($project === null || $project->getUser() !== $user) {
            return new JsonResponse(['error' => 'Project not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $typeId = $data['typeId'] ?? null;
        $runs = $data['runs'] ?? 1;
        $splitGroupId = $data['splitGroupId'] ?? null;
        $stepId = $data['stepId'] ?? null;

        if ($typeId === null && $splitGroupId === null && $stepId === null) {
            return new JsonResponse(['error' => 'typeId, splitGroupId or stepId is required'], Response::HTTP_BAD_REQUEST);
        }

        // If stepId is provided, add a child to a single step (creating a new split group)
        if ($stepId !== null) {
            $existingStep = $this->stepRepository->find(Uuid::fromString($stepId));

            if ($existingStep === null || $existingStep->getProject() !== $project) {
                return new JsonResponse(['error' => 'Step not found'], Response::HTTP_NOT_FOUND);
            }

            // Create a new split group ID
            $newSplitGroupId = Uuid::v4()->toRfc4122();
            $totalRuns = $existingStep->getRuns() + $runs;

            // Update the existing step to be part of the new split group
            $existingStep->setSplitGroupId($newSplitGroupId);
            $existingStep->setSplitIndex(0);
            $existingStep->setTotalGroupRuns($totalRuns);

            // Create new step copying properties from existing step
            $step = new \App\Entity\IndustryProjectStep();
            $step->setBlueprintTypeId($existingStep->getBlueprintTypeId());
            $step->setProductTypeId($existingStep->getProductTypeId());
            $step->setProductTypeName($existingStep->getProductTypeName());
            $step->setQuantity($runs);
            $step->setRuns($runs);
            $step->setDepth($existingStep->getDepth());
            $step->setActivityType($existingStep->getActivityType());
            $step->setSortOrder($existingStep->getSortOrder());
            $step->setSplitGroupId($newSplitGroupId);
            $step->setSplitIndex(1);
            $step->setTotalGroupRuns($totalRuns);
            $step->setRecommendedStructureName($existingStep->getRecommendedStructureName());
            $step->setStructureBonus($existingStep->getStructureBonus());
            $step->setStructureTimeBonus($existingStep->getStructureTimeBonus());
            $step->setTimePerRun($existingStep->getTimePerRun());

            $project->addStep($step);
            $this->entityManager->flush();

            // Return both the updated existing step and the new step
            return new JsonResponse([
                'newStep' => $this->projectService->serializeStep($step),
                'updatedStep' => $this->projectService->serializeStep($existingStep),
            ], Response::HTTP_CREATED);
        }

        // If splitGroupId is provided, add a child job to existing split group
        if ($splitGroupId !== null) {
            // Find an existing step in this split group to copy properties from
            $existingStep = null;
            $maxSplitIndex = -1;
            foreach ($project->getSteps() as $s) {
                if ($s->getSplitGroupId() === $splitGroupId) {
                    if ($existingStep === null) {
                        $existingStep = $s;
                    }
                    if ($s->getSplitIndex() > $maxSplitIndex) {
                        $maxSplitIndex = $s->getSplitIndex();
                    }
                }
            }

            if ($existingStep === null) {
                return new JsonResponse(['error' => 'Split group not found'], Response::HTTP_NOT_FOUND);
            }

            // Create new step copying properties from existing step
            $step = new \App\Entity\IndustryProjectStep();
            $step->setBlueprintTypeId($existingStep->getBlueprintTypeId());
            $step->setProductTypeId($existingStep->getProductTypeId());
            $step->setProductTypeName($existingStep->getProductTypeName());
            $step->setQuantity($runs);
            $step->setRuns($runs);
            $step->setDepth($existingStep->getDepth());
            $step->setActivityType($existingStep->getActivityType());
            $step->setSortOrder($existingStep->getSortOrder());
            $step->setSplitGroupId($splitGroupId);
            $step->setSplitIndex($maxSplitIndex + 1);
            $step->setTotalGroupRuns($existingStep->getTotalGroupRuns());
            $step->setRecommendedStructureName($existingStep->getRecommendedStructureName());
            $step->setStructureBonus($existingStep->getStructureBonus());
            $step->setStructureTimeBonus($existingStep->getStructureTimeBonus());
            $step->setTimePerRun($existingStep->getTimePerRun());

            $project->addStep($step);
            $this->entityManager->flush();

            return new JsonResponse($this->projectService->serializeStep($step), Response::HTTP_CREATED);
        }

        // Find the type
        $type = $this->invTypeRepository->find($typeId);
        if ($type === null) {
            return new JsonResponse(['error' => 'Unknown type'], Response::HTTP_BAD_REQUEST);
        }

        // Find the blueprint for this product
        $activityProduct = $this->activityProductRepository->findOneBy([
            'productTypeId' => $typeId,
            'activityId' => 1, // Manufacturing
        ]);

        // Try reaction if no manufacturing blueprint
        if ($activityProduct === null) {
            $activityProduct = $this->activityProductRepository->findOneBy([
                'productTypeId' => $typeId,
                'activityId' => 11, // Reaction
            ]);
        }

        $blueprintTypeId = $activityProduct?->getBlueprintTypeId() ?? $typeId;
        $activityType = match ($activityProduct?->getActivityId()) {
            11 => 'reaction',
            default => 'manufacturing',
        };

        // Get the max sort order
        $maxSortOrder = 0;
        foreach ($project->getSteps() as $step) {
            if ($step->getSortOrder() > $maxSortOrder) {
                $maxSortOrder = $step->getSortOrder();
            }
        }

        // Create the step
        $step = new \App\Entity\IndustryProjectStep();
        $step->setBlueprintTypeId($blueprintTypeId);
        $step->setProductTypeId($typeId);
        $step->setProductTypeName($type->getTypeName());
        $step->setQuantity($runs);
        $step->setRuns($runs);
        $step->setDepth(0); // Custom steps are at depth 0
        $step->setActivityType($activityType);
        $step->setSortOrder($maxSortOrder + 1);
        $step->setManualJobData(true); // Mark as manual since it's user-added

        $project->addStep($step);
        $this->entityManager->flush();

        return new JsonResponse($this->projectService->serializeStep($step), Response::HTTP_CREATED);
    }

    #[Route('/projects/{id}/steps/{stepId}', name: 'api_industry_step_delete', methods: ['DELETE'])]
    public function deleteStep(string $id, string $stepId): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $project = $this->projectRepository->find(Uuid::fromString($id));

        if ($project === null || $project->getUser() !== $user) {
            return new JsonResponse(['error' => 'Project not found'], Response::HTTP_NOT_FOUND);
        }

        $step = $this->stepRepository->find(Uuid::fromString($stepId));

        if ($step === null || $step->getProject() !== $project) {
            return new JsonResponse(['error' => 'Step not found'], Response::HTTP_NOT_FOUND);
        }

        $project->getSteps()->removeElement($step);
        $this->entityManager->remove($step);
        $this->entityManager->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/projects/{id}/shopping-list', name: 'api_industry_shopping_list', methods: ['GET'])]
    public function shoppingList(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $project = $this->projectRepository->find(Uuid::fromString($id));

        if ($project === null || $project->getUser() !== $user) {
            return new JsonResponse(['error' => 'Project not found'], Response::HTTP_NOT_FOUND);
        }

        $materials = $this->projectService->getShoppingList($project);

        // Get structure ID from query param or user preference
        $structureId = $request->query->get('structureId');
        $structureId = $structureId !== null ? (int) $structureId : $user->getPreferredMarketStructureId();

        // Get transport cost per m³ (default 1200 ISK/m³)
        $transportCostPerM3 = (float) $request->query->get('transportCost', 1200);

        // Get token for structure market access
        $token = null;
        foreach ($user->getCharacters() as $character) {
            if ($character->getEveToken() !== null) {
                $token = $character->getEveToken();
                break;
            }
        }

        // Extract type IDs for price lookup
        $typeIds = array_map(fn (array $mat) => $mat['typeId'], $materials);

        // Get volume for each type from SDE
        $volumes = [];
        foreach ($this->invTypeRepository->findBy(['typeId' => $typeIds]) as $type) {
            $volumes[$type->getTypeId()] = $type->getVolume() ?? 0.0;
        }

        // Get price comparison (with error handling)
        $priceData = null;
        $priceError = null;
        try {
            $priceData = $this->marketService->comparePrices($typeIds, $structureId, $token);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch market prices', [
                'error' => $e->getMessage(),
                'projectId' => $id,
            ]);
            $priceError = 'Impossible de récupérer les prix du marché. Réessayez plus tard.';
        }

        // Enrich materials with prices (if available)
        $enrichedMaterials = [];
        $totalJita = 0.0;
        $totalImport = 0.0;
        $totalJitaWithImport = 0.0;
        $totalStructure = 0.0;
        $totalBest = 0.0;
        $totalVolume = 0.0;

        foreach ($materials as $mat) {
            $typeId = $mat['typeId'];
            $quantity = $mat['quantity'];
            $volume = $volumes[$typeId] ?? 0.0;
            $totalItemVolume = $volume * $quantity;

            $jitaPrice = $priceData !== null ? ($priceData['jita'][$typeId] ?? null) : null;
            $structurePrice = $priceData !== null ? ($priceData['structure'][$typeId] ?? null) : null;

            $jitaTotal = $jitaPrice !== null ? $jitaPrice * $quantity : null;
            $structureTotal = $structurePrice !== null ? $structurePrice * $quantity : null;

            // Calculate import cost from Jita
            $importCost = $totalItemVolume * $transportCostPerM3;
            $jitaWithImport = $jitaTotal !== null ? $jitaTotal + $importCost : null;

            // Determine best price (comparing Jita+Import vs Structure)
            $bestLocation = null;
            $bestPrice = null;
            $savings = null;

            if ($jitaWithImport !== null && $structureTotal !== null) {
                if ($jitaWithImport <= $structureTotal) {
                    $bestLocation = 'jita';
                    $bestPrice = $jitaWithImport;
                    $savings = $structureTotal - $jitaWithImport;
                } else {
                    $bestLocation = 'structure';
                    $bestPrice = $structureTotal;
                    $savings = $jitaWithImport - $structureTotal;
                }
            } elseif ($jitaWithImport !== null) {
                $bestLocation = 'jita';
                $bestPrice = $jitaWithImport;
            } elseif ($structureTotal !== null) {
                $bestLocation = 'structure';
                $bestPrice = $structureTotal;
            }

            if ($jitaTotal !== null) {
                $totalJita += $jitaTotal;
                $totalImport += $importCost;
                $totalJitaWithImport += $jitaWithImport;
            }
            if ($structureTotal !== null) {
                $totalStructure += $structureTotal;
            }
            if ($bestPrice !== null) {
                $totalBest += $bestPrice;
            }
            $totalVolume += $totalItemVolume;

            $enrichedMaterials[] = [
                'typeId' => $typeId,
                'typeName' => $mat['typeName'],
                'quantity' => $quantity,
                'volume' => $volume,
                'totalVolume' => $totalItemVolume,
                'jitaUnitPrice' => $jitaPrice,
                'jitaTotal' => $jitaTotal,
                'importCost' => $importCost,
                'jitaWithImport' => $jitaWithImport,
                'structureUnitPrice' => $structurePrice,
                'structureTotal' => $structureTotal,
                'bestLocation' => $bestLocation,
                'bestPrice' => $bestPrice,
                'savings' => $savings,
            ];
        }

        // Format last sync time
        $structureLastSync = null;
        if ($priceData !== null && isset($priceData['structureLastSync']) && $priceData['structureLastSync'] instanceof \DateTimeImmutable) {
            $structureLastSync = $priceData['structureLastSync']->format('c');
        }

        return new JsonResponse([
            'materials' => $enrichedMaterials,
            'structureId' => $priceData !== null ? ($priceData['structureId'] ?? $this->marketService->getDefaultStructureId()) : $this->marketService->getDefaultStructureId(),
            'structureName' => $priceData !== null ? ($priceData['structureName'] ?? $this->marketService->getDefaultStructureName()) : $this->marketService->getDefaultStructureName(),
            'structureAccessible' => $priceData !== null && ($priceData['structureAccessible'] ?? false),
            'structureFromCache' => $priceData !== null && ($priceData['structureFromCache'] ?? false),
            'structureLastSync' => $structureLastSync,
            'transportCostPerM3' => $transportCostPerM3,
            'totals' => [
                'jita' => $totalJita,
                'import' => $totalImport,
                'jitaWithImport' => $totalJitaWithImport,
                'structure' => $totalStructure,
                'volume' => $totalVolume,
                'best' => $totalBest,
                'savingsVsJitaWithImport' => $totalJitaWithImport > 0 ? $totalJitaWithImport - $totalBest : 0,
                'savingsVsStructure' => $totalStructure > 0 ? $totalStructure - $totalBest : 0,
            ],
            'priceError' => $priceError,
        ]);
    }

    #[Route('/projects/{id}/regenerate-steps', name: 'api_industry_regenerate_steps', methods: ['POST'])]
    public function regenerateSteps(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $project = $this->projectRepository->find(Uuid::fromString($id));

        if ($project === null || $project->getUser() !== $user) {
            return new JsonResponse(['error' => 'Project not found'], Response::HTTP_NOT_FOUND);
        }

        $this->projectService->regenerateSteps($project);

        $steps = [];
        foreach ($project->getSteps() as $step) {
            $steps[] = $this->projectService->serializeStep($step);
        }

        return new JsonResponse([
            'steps' => $steps,
            'stepsCount' => count($steps),
        ]);
    }

    #[Route('/projects/{id}/match-jobs', name: 'api_industry_match_jobs', methods: ['POST'])]
    public function matchJobs(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $project = $this->projectRepository->find(Uuid::fromString($id));

        if ($project === null || $project->getUser() !== $user) {
            return new JsonResponse(['error' => 'Project not found'], Response::HTTP_NOT_FOUND);
        }

        // Sync jobs from ESI first so we have fresh data to match against
        $this->jobSyncService->resetCorporationTracking();
        $syncedCount = 0;
        $warning = null;
        foreach ($user->getCharacters() as $character) {
            try {
                $this->jobSyncService->syncCharacterJobs($character);
                $syncedCount++;
            } catch (EsiApiException $e) {
                if (in_array($e->statusCode, [502, 503, 504], true)) {
                    $warning = 'ESI est actuellement en maintenance. Les jobs seront synchronisés ultérieurement.';
                } else {
                    $this->logger->warning('Failed to sync jobs for character', [
                        'characterName' => $character->getName(),
                        'error' => $e->getMessage(),
                        'statusCode' => $e->statusCode,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to sync jobs for character', [
                    'characterName' => $character->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->projectService->matchEsiJobs($project);

        $steps = [];
        foreach ($project->getSteps() as $step) {
            $steps[] = $this->projectService->serializeStep($step);
        }

        return new JsonResponse([
            'steps' => $steps,
            'jobsCost' => $project->getJobsCost(),
            'syncedCharacters' => $syncedCount,
            'warning' => $warning,
        ]);
    }

    #[Route('/blacklist', name: 'api_industry_blacklist_get', methods: ['GET'])]
    public function getBlacklist(): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        return new JsonResponse([
            'categories' => $this->blacklistService->getCategories($user),
            'items' => $this->blacklistService->getBlacklistedItems($user),
        ]);
    }

    #[Route('/blacklist', name: 'api_industry_blacklist_update', methods: ['PUT'])]
    public function updateBlacklist(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();
        $data = json_decode($request->getContent(), true);

        if (array_key_exists('groupIds', $data)) {
            $user->setIndustryBlacklistGroupIds(array_map('intval', (array) $data['groupIds']));
        }
        if (array_key_exists('typeIds', $data)) {
            $user->setIndustryBlacklistTypeIds(array_map('intval', (array) $data['typeIds']));
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'categories' => $this->blacklistService->getCategories($user),
            'items' => $this->blacklistService->getBlacklistedItems($user),
        ]);
    }

    #[Route('/blacklist/search', name: 'api_industry_blacklist_search', methods: ['GET'])]
    public function searchBlacklistItem(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');
        if (strlen($query) < 2) {
            return new JsonResponse(['results' => []]);
        }

        // Search published types that have a manufacturing blueprint or reaction
        $conn = $this->entityManager->getConnection();
        $sql = <<<SQL
            SELECT DISTINCT t.type_id, t.type_name
            FROM sde_inv_types t
            INNER JOIN sde_industry_activity_products p ON p.product_type_id = t.type_id AND p.activity_id IN (1, 11)
            WHERE t.published = true
              AND LOWER(t.type_name) LIKE LOWER(:query)
            ORDER BY t.type_name
            LIMIT 20
        SQL;

        $results = $conn->fetchAllAssociative($sql, ['query' => "%{$query}%"]);

        return new JsonResponse([
            'results' => array_map(fn (array $row) => [
                'typeId' => (int) $row['type_id'],
                'typeName' => $row['type_name'],
            ], $results),
        ]);
    }

    // ==================== Corporation Structures ====================

    #[Route('/corporation-structures', name: 'api_industry_corporation_structures', methods: ['GET'])]
    public function getCorporationStructures(): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $corporationId = $user->getCorporationId();

        if ($corporationId === null) {
            return new JsonResponse(['structures' => []]);
        }

        // Get structures shared by corporation members (isCorporationStructure = true)
        $sharedConfigs = $this->structureConfigRepository->findCorporationSharedStructures($corporationId, $user);

        if (empty($sharedConfigs)) {
            return new JsonResponse(['structures' => []]);
        }

        // Get user's existing structure configs to filter out already configured structures
        $existingConfigs = $this->structureConfigRepository->findByUser($user);
        $existingLocationIds = [];
        foreach ($existingConfigs as $config) {
            $locId = $config->getLocationId();
            if ($locId !== null) {
                $existingLocationIds[$locId] = true;
            }
        }

        // Build the response from shared configs
        $structures = [];
        foreach ($sharedConfigs as $locationId => $config) {
            // Skip structures already configured by this user
            if (isset($existingLocationIds[$locationId])) {
                continue;
            }

            $structures[$locationId] = [
                'locationId' => $locationId,
                'locationName' => $config->getName(),
                'solarSystemId' => null,
                'solarSystemName' => null,
                'isCorporationOwned' => true, // By definition, these are corp structures
                'structureType' => $config->getStructureType(),
                'sharedConfig' => [
                    'securityType' => $config->getSecurityType(),
                    'structureType' => $config->getStructureType(),
                    'rigs' => $config->getRigs(),
                    'manufacturingMaterialBonus' => $config->getManufacturingMaterialBonus(),
                    'reactionMaterialBonus' => $config->getReactionMaterialBonus(),
                ],
            ];
        }

        // Get cached structure info for solar system names
        if (!empty($structures)) {
            $cachedStructures = $this->cachedStructureRepository->findByStructureIds(array_keys($structures));
            foreach ($cachedStructures as $structureId => $cached) {
                if (isset($structures[$structureId])) {
                    $solarSystemId = $cached->getSolarSystemId();
                    $structures[$structureId]['solarSystemId'] = $solarSystemId;

                    // Resolve solar system name from SDE
                    if ($solarSystemId !== null) {
                        $solarSystem = $this->solarSystemRepository->findBySolarSystemId($solarSystemId);
                        $structures[$structureId]['solarSystemName'] = $solarSystem?->getSolarSystemName();
                    }
                }
            }
        }

        // Sort by name
        usort($structures, fn($a, $b) => strcasecmp($a['locationName'] ?? '', $b['locationName'] ?? ''));

        return new JsonResponse([
            'structures' => array_values($structures),
        ]);
    }

    #[Route('/search-structure', name: 'api_industry_search_structure', methods: ['GET'])]
    public function searchStructure(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $query = trim($request->query->get('q', ''));
        if (strlen($query) < 3) {
            return new JsonResponse(['error' => 'Query must be at least 3 characters'], Response::HTTP_BAD_REQUEST);
        }

        $mainCharacter = $user->getMainCharacter();
        if ($mainCharacter === null) {
            return new JsonResponse(['error' => 'No main character set'], Response::HTTP_FORBIDDEN);
        }

        $token = $mainCharacter->getEveToken();
        if ($token === null) {
            return new JsonResponse(['error' => 'No token available'], Response::HTTP_FORBIDDEN);
        }

        try {
            // Search for structures by name via ESI
            $characterId = $mainCharacter->getEveCharacterId();
            $endpoint = sprintf(
                '/characters/%d/search/?categories=structure&search=%s&strict=false',
                $characterId,
                urlencode($query)
            );

            $searchResults = $this->esiClient->get($endpoint, $token);
            $structureIds = $searchResults['structure'] ?? [];

            if (empty($structureIds)) {
                return new JsonResponse(['structures' => []]);
            }

            // Get user's existing structure locationIds to filter them out
            $existingConfigs = $this->structureConfigRepository->findByUser($user);
            $existingLocationIds = [];
            foreach ($existingConfigs as $config) {
                $locId = $config->getLocationId();
                if ($locId !== null) {
                    $existingLocationIds[$locId] = true;
                }
            }

            // Filter out already configured structures
            $structureIds = array_filter($structureIds, fn($id) => !isset($existingLocationIds[$id]));

            if (empty($structureIds)) {
                return new JsonResponse(['structures' => []]);
            }

            // Limit to first 10 results
            $structureIds = array_slice($structureIds, 0, 10);

            // Fetch structure info for each result
            $structures = [];
            $userCorporationId = $user->getCorporationId();

            foreach ($structureIds as $structureId) {
                try {
                    $structureEndpoint = sprintf('/universe/structures/%d/', $structureId);
                    $structureInfo = $this->esiClient->get($structureEndpoint, $token);

                    $ownerId = $structureInfo['owner_id'] ?? null;
                    $typeId = $structureInfo['type_id'] ?? null;
                    $solarSystemId = $structureInfo['solar_system_id'] ?? null;
                    $name = $structureInfo['name'] ?? 'Unknown';

                    // Cache the structure info for future use
                    $this->cacheStructureInfo($structureId, $name, $solarSystemId, $ownerId, $typeId);

                    // Resolve solar system name from SDE
                    $solarSystemName = null;
                    if ($solarSystemId !== null) {
                        $solarSystem = $this->solarSystemRepository->findBySolarSystemId($solarSystemId);
                        $solarSystemName = $solarSystem?->getSolarSystemName();
                    }

                    $structures[] = [
                        'locationId' => $structureId,
                        'locationName' => $name,
                        'solarSystemId' => $solarSystemId,
                        'solarSystemName' => $solarSystemName,
                        'structureType' => $this->mapTypeIdToStructureType($typeId),
                        'typeId' => $typeId,
                        'isCorporationOwned' => $ownerId !== null && $ownerId === $userCorporationId,
                    ];
                } catch (EsiApiException $e) {
                    // Skip structures we can't access (403 Forbidden)
                    $this->logger->debug('Cannot access structure', [
                        'structureId' => $structureId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return new JsonResponse(['structures' => $structures]);
        } catch (EsiApiException $e) {
            $statusCode = Response::HTTP_BAD_GATEWAY;
            $message = $e->getMessage();

            // Handle rate limiting specifically
            if (str_contains($message, 'Error limited') || str_contains($message, '420')) {
                $statusCode = Response::HTTP_TOO_MANY_REQUESTS;
                $message = 'ESI rate limit atteint. Réessayez dans quelques secondes.';
            }

            return new JsonResponse(
                ['error' => $message],
                $statusCode
            );
        }
    }

    // ==================== Structure Configs ====================

    #[Route('/structures', name: 'api_industry_structures_list', methods: ['GET'])]
    public function listStructures(): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $structures = $this->structureConfigRepository->findByUser($user);

        return new JsonResponse([
            'structures' => array_map(fn (IndustryStructureConfig $s) => $this->serializeStructureConfig($s), $structures),
            'rigOptions' => $this->getRigOptions(),
        ]);
    }

    #[Route('/structures', name: 'api_industry_structures_create', methods: ['POST'])]
    public function createStructure(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $data = json_decode($request->getContent(), true) ?? [];

        $name = trim($data['name'] ?? '');
        if ($name === '') {
            return new JsonResponse(['error' => 'Name is required'], Response::HTTP_BAD_REQUEST);
        }

        $securityType = $data['securityType'] ?? 'nullsec';
        if (!in_array($securityType, ['highsec', 'lowsec', 'nullsec'], true)) {
            return new JsonResponse(['error' => 'Invalid security type'], Response::HTTP_BAD_REQUEST);
        }

        $structureType = $data['structureType'] ?? 'raitaru';
        $validStructureTypes = ['station', 'raitaru', 'azbel', 'sotiyo', 'athanor', 'tatara', 'engineering_complex', 'refinery'];
        if (!in_array($structureType, $validStructureTypes, true)) {
            return new JsonResponse(['error' => 'Invalid structure type'], Response::HTTP_BAD_REQUEST);
        }

        $rigs = $data['rigs'] ?? [];
        if (!is_array($rigs)) {
            $rigs = [];
        }

        $isDefault = (bool) ($data['isDefault'] ?? false);

        // If setting as default, clear other defaults
        if ($isDefault) {
            $this->structureConfigRepository->clearDefaultForUser($user);
        }

        $structure = new IndustryStructureConfig();
        $structure->setUser($user);
        $structure->setName($name);
        $structure->setSecurityType($securityType);
        $structure->setStructureType($structureType);
        $structure->setRigs($rigs);
        $structure->setIsDefault($isDefault);

        // If locationId is provided, store it for corporation sharing
        $locationId = isset($data['locationId']) ? (int) $data['locationId'] : null;
        if ($locationId !== null && $locationId > 0) {
            $structure->setLocationId($locationId);
            // Store corporation ID for sharing with corp members
            $corporationId = $user->getCorporationId();
            if ($corporationId !== null) {
                $structure->setCorporationId($corporationId);

                // Auto-detect if this is a corporation structure based on owner
                $cachedStructure = $this->cachedStructureRepository->findByStructureId($locationId);
                if ($cachedStructure !== null && $cachedStructure->getOwnerCorporationId() === $corporationId) {
                    $structure->setIsCorporationStructure(true);
                }
            }
        }

        $this->entityManager->persist($structure);
        $this->entityManager->flush();

        return new JsonResponse($this->serializeStructureConfig($structure), Response::HTTP_CREATED);
    }

    #[Route('/structures/{id}', name: 'api_industry_structures_update', methods: ['PATCH'])]
    public function updateStructure(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $structure = $this->structureConfigRepository->find(Uuid::fromString($id));

        if ($structure === null || $structure->getUser() !== $user) {
            return new JsonResponse(['error' => 'Structure not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (isset($data['name'])) {
            $name = trim($data['name']);
            if ($name === '') {
                return new JsonResponse(['error' => 'Name cannot be empty'], Response::HTTP_BAD_REQUEST);
            }
            $structure->setName($name);
        }

        if (isset($data['securityType'])) {
            if (!in_array($data['securityType'], ['highsec', 'lowsec', 'nullsec'], true)) {
                return new JsonResponse(['error' => 'Invalid security type'], Response::HTTP_BAD_REQUEST);
            }
            $structure->setSecurityType($data['securityType']);
        }

        if (isset($data['structureType'])) {
            $validStructureTypes = ['station', 'raitaru', 'azbel', 'sotiyo', 'athanor', 'tatara', 'engineering_complex', 'refinery'];
            if (!in_array($data['structureType'], $validStructureTypes, true)) {
                return new JsonResponse(['error' => 'Invalid structure type'], Response::HTTP_BAD_REQUEST);
            }
            $structure->setStructureType($data['structureType']);
        }

        if (isset($data['rigs']) && is_array($data['rigs'])) {
            $structure->setRigs($data['rigs']);
        }

        if (isset($data['isDefault'])) {
            $isDefault = (bool) $data['isDefault'];
            if ($isDefault && !$structure->isDefault()) {
                $this->structureConfigRepository->clearDefaultForUser($user);
            }
            $structure->setIsDefault($isDefault);
        }

        if (isset($data['isCorporationStructure'])) {
            $structure->setIsCorporationStructure((bool) $data['isCorporationStructure']);
        }

        $this->entityManager->flush();

        return new JsonResponse($this->serializeStructureConfig($structure));
    }

    #[Route('/structures/{id}', name: 'api_industry_structures_delete', methods: ['DELETE'])]
    public function deleteStructure(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $structure = $this->structureConfigRepository->find(Uuid::fromString($id));

        if ($structure === null || $structure->getUser() !== $user) {
            return new JsonResponse(['error' => 'Structure not found'], Response::HTTP_NOT_FOUND);
        }

        // If this is a corporation structure, soft-delete to preserve config for corp
        if ($structure->isCorporationStructure() && $structure->getLocationId() !== null) {
            $structure->setIsDeleted(true);
            $this->entityManager->flush();

            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        $this->entityManager->remove($structure);
        $this->entityManager->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/structures/rig-options', name: 'api_industry_rig_options', methods: ['GET'])]
    public function getRigOptionsEndpoint(): JsonResponse
    {
        return new JsonResponse($this->getRigOptions());
    }

    private function serializeStructureConfig(IndustryStructureConfig $structure): array
    {
        return [
            'id' => $structure->getId()->toRfc4122(),
            'name' => $structure->getName(),
            'locationId' => $structure->getLocationId(),
            'securityType' => $structure->getSecurityType(),
            'structureType' => $structure->getStructureType(),
            'rigs' => $structure->getRigs(),
            'isDefault' => $structure->isDefault(),
            'isCorporationStructure' => $structure->isCorporationStructure(),
            'manufacturingMaterialBonus' => $structure->getManufacturingMaterialBonus(),
            'reactionMaterialBonus' => $structure->getReactionMaterialBonus(),
            'createdAt' => $structure->getCreatedAt()->format('c'),
        ];
    }

    private function getRigOptions(): array
    {
        return [
            'manufacturing' => [
                // ===== M-Set (Medium: Raitaru) =====
                // Ships
                ['name' => 'Standup M-Set Basic Small Ship Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Ships', 'size' => 'M', 'targetCategories' => ['basic_small_ship']],
                ['name' => 'Standup M-Set Basic Small Ship Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Ships', 'size' => 'M', 'targetCategories' => ['basic_small_ship']],
                ['name' => 'Standup M-Set Basic Medium Ship Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Ships', 'size' => 'M', 'targetCategories' => ['basic_medium_ship']],
                ['name' => 'Standup M-Set Basic Medium Ship Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Ships', 'size' => 'M', 'targetCategories' => ['basic_medium_ship']],
                ['name' => 'Standup M-Set Basic Large Ship Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Ships', 'size' => 'M', 'targetCategories' => ['basic_large_ship']],
                ['name' => 'Standup M-Set Basic Large Ship Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Ships', 'size' => 'M', 'targetCategories' => ['basic_large_ship']],
                ['name' => 'Standup M-Set Advanced Small Ship Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Ships', 'size' => 'M', 'targetCategories' => ['advanced_small_ship']],
                ['name' => 'Standup M-Set Advanced Small Ship Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Ships', 'size' => 'M', 'targetCategories' => ['advanced_small_ship']],
                ['name' => 'Standup M-Set Advanced Medium Ship Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Ships', 'size' => 'M', 'targetCategories' => ['advanced_medium_ship']],
                ['name' => 'Standup M-Set Advanced Medium Ship Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Ships', 'size' => 'M', 'targetCategories' => ['advanced_medium_ship']],
                ['name' => 'Standup M-Set Advanced Large Ship Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Ships', 'size' => 'M', 'targetCategories' => ['advanced_large_ship']],
                ['name' => 'Standup M-Set Advanced Large Ship Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Ships', 'size' => 'M', 'targetCategories' => ['advanced_large_ship']],
                // Components
                ['name' => 'Standup M-Set Basic Capital Component Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Components', 'size' => 'M', 'targetCategories' => ['basic_capital_component']],
                ['name' => 'Standup M-Set Basic Capital Component Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Components', 'size' => 'M', 'targetCategories' => ['basic_capital_component']],
                ['name' => 'Standup M-Set Advanced Component Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Components', 'size' => 'M', 'targetCategories' => ['advanced_component']],
                ['name' => 'Standup M-Set Advanced Component Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Components', 'size' => 'M', 'targetCategories' => ['advanced_component']],
                ['name' => 'Standup M-Set Thukker Basic Capital Component Manufacturing Material Efficiency', 'bonus' => 2.4, 'category' => 'M-Set Components', 'size' => 'M', 'targetCategories' => ['basic_capital_component']],
                ['name' => 'Standup M-Set Thukker Advanced Component Manufacturing Material Efficiency', 'bonus' => 2.4, 'category' => 'M-Set Components', 'size' => 'M', 'targetCategories' => ['advanced_component']],
                // Equipment
                ['name' => 'Standup M-Set Equipment Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Equipment', 'size' => 'M', 'targetCategories' => ['equipment']],
                ['name' => 'Standup M-Set Equipment Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Equipment', 'size' => 'M', 'targetCategories' => ['equipment']],
                ['name' => 'Standup M-Set Ammunition Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Equipment', 'size' => 'M', 'targetCategories' => ['ammunition']],
                ['name' => 'Standup M-Set Ammunition Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Equipment', 'size' => 'M', 'targetCategories' => ['ammunition']],
                ['name' => 'Standup M-Set Ammunition Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'category' => 'M-Set Equipment TE', 'size' => 'M', 'targetCategories' => ['ammunition']],
                ['name' => 'Standup M-Set Ammunition Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'category' => 'M-Set Equipment TE', 'size' => 'M', 'targetCategories' => ['ammunition']],
                ['name' => 'Standup M-Set Drone and Fighter Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Equipment', 'size' => 'M', 'targetCategories' => ['drone', 'fighter']],
                ['name' => 'Standup M-Set Drone and Fighter Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Equipment', 'size' => 'M', 'targetCategories' => ['drone', 'fighter']],
                // Structures
                ['name' => 'Standup M-Set Structure Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Structures', 'size' => 'M', 'targetCategories' => ['structure', 'structure_component']],
                ['name' => 'Standup M-Set Structure Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Structures', 'size' => 'M', 'targetCategories' => ['structure', 'structure_component']],

                // ===== L-Set (Large: Azbel) =====
                // Ships
                ['name' => 'Standup L-Set Basic Small Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['basic_small_ship']],
                ['name' => 'Standup L-Set Basic Small Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['basic_small_ship']],
                ['name' => 'Standup L-Set Basic Medium Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['basic_medium_ship']],
                ['name' => 'Standup L-Set Basic Medium Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['basic_medium_ship']],
                ['name' => 'Standup L-Set Basic Large Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['basic_large_ship']],
                ['name' => 'Standup L-Set Basic Large Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['basic_large_ship']],
                ['name' => 'Standup L-Set Advanced Small Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['advanced_small_ship']],
                ['name' => 'Standup L-Set Advanced Small Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['advanced_small_ship']],
                ['name' => 'Standup L-Set Advanced Medium Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['advanced_medium_ship']],
                ['name' => 'Standup L-Set Advanced Medium Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['advanced_medium_ship']],
                ['name' => 'Standup L-Set Advanced Large Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['advanced_large_ship']],
                ['name' => 'Standup L-Set Advanced Large Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['advanced_large_ship']],
                ['name' => 'Standup L-Set Capital Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['capital_ship']],
                ['name' => 'Standup L-Set Capital Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['capital_ship']],
                // Components
                ['name' => 'Standup L-Set Basic Capital Component Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Components', 'size' => 'L', 'targetCategories' => ['basic_capital_component']],
                ['name' => 'Standup L-Set Basic Capital Component Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Components', 'size' => 'L', 'targetCategories' => ['basic_capital_component']],
                ['name' => 'Standup L-Set Advanced Component Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Components', 'size' => 'L', 'targetCategories' => ['advanced_component']],
                ['name' => 'Standup L-Set Advanced Component Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Components', 'size' => 'L', 'targetCategories' => ['advanced_component']],
                ['name' => 'Standup L-Set Thukker Basic Capital Component Manufacturing Efficiency', 'bonus' => 2.4, 'category' => 'L-Set Components', 'size' => 'L', 'targetCategories' => ['basic_capital_component']],
                ['name' => 'Standup L-Set Thukker Advanced Component Manufacturing Efficiency', 'bonus' => 2.4, 'category' => 'L-Set Components', 'size' => 'L', 'targetCategories' => ['advanced_component']],
                // Equipment
                ['name' => 'Standup L-Set Equipment Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Equipment', 'size' => 'L', 'targetCategories' => ['equipment']],
                ['name' => 'Standup L-Set Equipment Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Equipment', 'size' => 'L', 'targetCategories' => ['equipment']],
                ['name' => 'Standup L-Set Ammunition Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Equipment', 'size' => 'L', 'targetCategories' => ['ammunition']],
                ['name' => 'Standup L-Set Ammunition Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Equipment', 'size' => 'L', 'targetCategories' => ['ammunition']],
                ['name' => 'Standup L-Set Ammunition Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'category' => 'L-Set Equipment TE', 'size' => 'L', 'targetCategories' => ['ammunition']],
                ['name' => 'Standup L-Set Ammunition Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'category' => 'L-Set Equipment TE', 'size' => 'L', 'targetCategories' => ['ammunition']],
                ['name' => 'Standup L-Set Drone and Fighter Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Equipment', 'size' => 'L', 'targetCategories' => ['drone', 'fighter']],
                ['name' => 'Standup L-Set Drone and Fighter Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Equipment', 'size' => 'L', 'targetCategories' => ['drone', 'fighter']],
                // Structures
                ['name' => 'Standup L-Set Structure Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Structures', 'size' => 'L', 'targetCategories' => ['structure', 'structure_component']],
                ['name' => 'Standup L-Set Structure Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Structures', 'size' => 'L', 'targetCategories' => ['structure', 'structure_component']],

                // ===== XL-Set (Extra Large: Sotiyo) =====
                ['name' => 'Standup XL-Set Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'XL-Set', 'size' => 'XL', 'targetCategories' => ['basic_small_ship', 'basic_medium_ship', 'basic_large_ship', 'advanced_small_ship', 'advanced_medium_ship', 'advanced_large_ship', 'capital_ship']],
                ['name' => 'Standup XL-Set Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'XL-Set', 'size' => 'XL', 'targetCategories' => ['basic_small_ship', 'basic_medium_ship', 'basic_large_ship', 'advanced_small_ship', 'advanced_medium_ship', 'advanced_large_ship', 'capital_ship']],
                ['name' => 'Standup XL-Set Equipment and Consumable Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'XL-Set', 'size' => 'XL', 'targetCategories' => ['equipment', 'ammunition', 'drone', 'fighter']],
                ['name' => 'Standup XL-Set Equipment and Consumable Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'XL-Set', 'size' => 'XL', 'targetCategories' => ['equipment', 'ammunition', 'drone', 'fighter']],
                ['name' => 'Standup XL-Set Structure and Component Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'XL-Set', 'size' => 'XL', 'targetCategories' => ['structure', 'structure_component', 'basic_capital_component', 'advanced_component']],
                ['name' => 'Standup XL-Set Structure and Component Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'XL-Set', 'size' => 'XL', 'targetCategories' => ['structure', 'structure_component', 'basic_capital_component', 'advanced_component']],
                ['name' => 'Standup XL-Set Thukker Structure and Component Manufacturing Efficiency', 'bonus' => 2.4, 'category' => 'XL-Set', 'size' => 'XL', 'targetCategories' => ['structure', 'structure_component', 'basic_capital_component', 'advanced_component']],

                // ===== Laboratory Optimization (Research/Invention/Copy TE bonus) =====
                // M-Set (Raitaru)
                ['name' => 'Standup M-Set Laboratory Optimization I', 'bonus' => 2.0, 'category' => 'M-Set Laboratory', 'size' => 'M', 'targetCategories' => ['research']],
                ['name' => 'Standup M-Set Laboratory Optimization II', 'bonus' => 2.4, 'category' => 'M-Set Laboratory', 'size' => 'M', 'targetCategories' => ['research']],
                // L-Set (Azbel)
                ['name' => 'Standup L-Set Laboratory Optimization I', 'bonus' => 2.0, 'category' => 'L-Set Laboratory', 'size' => 'L', 'targetCategories' => ['research']],
                ['name' => 'Standup L-Set Laboratory Optimization II', 'bonus' => 2.4, 'category' => 'L-Set Laboratory', 'size' => 'L', 'targetCategories' => ['research']],
                // XL-Set (Sotiyo)
                ['name' => 'Standup XL-Set Laboratory Optimization I', 'bonus' => 2.0, 'category' => 'XL-Set Laboratory', 'size' => 'XL', 'targetCategories' => ['research']],
                ['name' => 'Standup XL-Set Laboratory Optimization II', 'bonus' => 2.4, 'category' => 'XL-Set Laboratory', 'size' => 'XL', 'targetCategories' => ['research']],
            ],
            'reaction' => [
                // M-Set (Athanor)
                ['name' => 'Standup M-Set Composite Reactor Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Reactions', 'size' => 'M', 'targetCategories' => ['composite_reaction']],
                ['name' => 'Standup M-Set Composite Reactor Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Reactions', 'size' => 'M', 'targetCategories' => ['composite_reaction']],
                ['name' => 'Standup M-Set Biochemical Reactor Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Reactions', 'size' => 'M', 'targetCategories' => ['biochemical_reaction']],
                ['name' => 'Standup M-Set Biochemical Reactor Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Reactions', 'size' => 'M', 'targetCategories' => ['biochemical_reaction']],
                ['name' => 'Standup M-Set Hybrid Reactor Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Reactions', 'size' => 'M', 'targetCategories' => ['hybrid_reaction']],
                ['name' => 'Standup M-Set Hybrid Reactor Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Reactions', 'size' => 'M', 'targetCategories' => ['hybrid_reaction']],
                // L-Set (Tatara) - applies to ALL reactions
                ['name' => 'Standup L-Set Reactor Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Reactions', 'size' => 'L', 'targetCategories' => ['composite_reaction', 'biochemical_reaction', 'hybrid_reaction']],
                ['name' => 'Standup L-Set Reactor Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Reactions', 'size' => 'L', 'targetCategories' => ['composite_reaction', 'biochemical_reaction', 'hybrid_reaction']],
            ],
        ];
    }

    /**
     * Get the quantity produced per run for a given blueprint and activity type.
     */
    private function getQuantityPerRun(int $blueprintTypeId, string $activityType): int
    {
        $activityId = match ($activityType) {
            'manufacturing' => 1,
            'reaction' => 11,
            default => 1,
        };

        $product = $this->activityProductRepository->findOneBy([
            'typeId' => $blueprintTypeId,
            'activityId' => $activityId,
        ]);

        return $product?->getQuantity() ?? 1;
    }

    /**
     * Map EVE structure type ID to structure type name.
     */
    private function mapTypeIdToStructureType(?int $typeId): ?string
    {
        return match ($typeId) {
            35825 => 'raitaru',   // Engineering Complex M
            35826 => 'azbel',     // Engineering Complex L
            35827 => 'sotiyo',    // Engineering Complex XL
            35835 => 'athanor',   // Refinery M
            35836 => 'tatara',    // Refinery L
            default => null,
        };
    }

    /**
     * Cache structure info from ESI for future use.
     */
    private function cacheStructureInfo(int $structureId, string $name, ?int $solarSystemId, ?int $ownerId, ?int $typeId): void
    {
        $cached = $this->cachedStructureRepository->findByStructureId($structureId);

        if ($cached === null) {
            $cached = new \App\Entity\CachedStructure();
            $cached->setStructureId($structureId);
            $this->entityManager->persist($cached);
        }

        $cached->setName($name);
        $cached->setSolarSystemId($solarSystemId);
        $cached->setOwnerCorporationId($ownerId);
        $cached->setTypeId($typeId);
        $cached->setResolvedAt(new \DateTimeImmutable());

        $this->entityManager->flush();
    }
}
