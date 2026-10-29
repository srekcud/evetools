<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\ShoppingListMaterialResource;
use App\ApiResource\Industry\ShoppingListResource;
use App\ApiResource\Industry\ShoppingListTotalsResource;
use App\Constant\EveConstants;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\ESI\MarketService;
use App\Service\Industry\IndustryShoppingListBuilder;
use App\Service\JitaMarketService;
use App\Service\UuidOrNotFound;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ShoppingListResource>
 */
class ShoppingListProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryShoppingListBuilder $shoppingListBuilder,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly MarketService $marketService,
        private readonly JitaMarketService $jitaMarketService,
        private readonly IndustryUserSettingsRepository $settingsRepository,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ShoppingListResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['id'], 'Project not found'));

        if ($project === null || $project->getUser() !== $user) {
            throw new NotFoundHttpException('Project not found');
        }

        $shoppingList = $this->shoppingListBuilder->getShoppingList($project);
        $materials = $shoppingList['materials'];

        // Append invention materials stored on the project (datacores, decryptors)
        $inventionMaterials = $project->getInventionMaterials();
        if (!empty($inventionMaterials)) {
            foreach ($inventionMaterials as $invMat) {
                $materials[] = [
                    'typeId' => $invMat['typeId'],
                    'typeName' => $invMat['typeName'],
                    'quantity' => $invMat['quantity'],
                    'extraQuantity' => 0,
                    'isInventionMaterial' => true,
                ];
            }
        }

        $purchasedQuantities = $this->shoppingListBuilder->getPurchasedQuantities($project);

        $request = $this->requestStack->getCurrentRequest();
        $requestedStructureId = $request?->query->get('structureId');
        $structureId = $requestedStructureId !== null
            ? (int) $requestedStructureId
            : ($user->getPreferredMarketStructureId() ?? $this->marketService->getDefaultStructureId());
        $requestedTransportCost = $request?->query->get('transportCost');
        $transportCostPerM3 = $requestedTransportCost !== null
            ? (float) $requestedTransportCost
            : ($this->settingsRepository->findOneBy(['user' => $user])?->getExportCostPerM3() ?? EveConstants::DEFAULT_EXPORT_COST_PER_M3);

        $token = null;
        foreach ($user->getCharacters() as $character) {
            if ($character->getEveToken() !== null) {
                $token = $character->getEveToken();
                break;
            }
        }

        $typeIds = array_map(fn (array $mat) => $mat['typeId'], $materials);

        $volumes = [];
        foreach ($this->invTypeRepository->findBy(['typeId' => $typeIds]) as $type) {
            $volumes[$type->getTypeId()] = $type->getVolume();
        }

        $priceData = null;
        $priceError = null;
        try {
            $priceData = $this->marketService->comparePrices($typeIds, $structureId, $token);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch market prices', [
                'error' => $e->getMessage(),
                'projectId' => $uriVariables['id'],
            ]);
            $priceError = 'Impossible de récupérer les prix du marché. Réessayez plus tard.';
        }

        // Calculate weighted Jita prices per material
        $typeQuantities = [];
        foreach ($materials as $mat) {
            $typeId = $mat['typeId'];
            $typeQuantities[$typeId] = ($typeQuantities[$typeId] ?? 0) + $mat['quantity'];
        }

        $weightedJitaPrices = [];
        try {
            $weightedJitaPrices = $this->jitaMarketService->getWeightedSellPricesWithFallback($typeQuantities);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch weighted Jita prices', [
                'error' => $e->getMessage(),
            ]);
        }

        $enrichedMaterials = [];
        $totals = new ShoppingListTotalsResource();
        $importTotal = 0.0;
        $savingsVsJitaWithImport = 0.0;
        $savingsVsStructure = 0.0;
        $volumeTotal = 0.0;
        $missingVolumeTypeIds = [];
        $missingPriceTypeIds = [];

        foreach ($materials as $mat) {
            $typeId = $mat['typeId'];
            $quantity = $mat['quantity'];
            $volume = $volumes[$typeId] ?? null;
            $totalItemVolume = null;
            $importCost = null;
            if ($volume === null) {
                $missingVolumeTypeIds[$typeId] = $typeId;
            } else {
                $totalItemVolume = $volume * $quantity;
                $importCost = $totalItemVolume * $transportCostPerM3;
            }

            $jitaPrice = $priceData !== null ? ($priceData['jita'][$typeId] ?? null) : null;
            $structurePrice = $priceData !== null ? ($priceData['structure'][$typeId] ?? null) : null;

            if ($jitaPrice === null && $structurePrice === null) {
                $missingPriceTypeIds[$typeId] = $typeId;
            }

            $jitaTotal = $jitaPrice !== null ? $jitaPrice * $quantity : null;
            $structureTotal = $structurePrice !== null ? $structurePrice * $quantity : null;

            $jitaWithImport = $jitaTotal !== null && $importCost !== null ? $jitaTotal + $importCost : null;

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
                $savingsVsJitaWithImport += $jitaWithImport - $bestPrice;
                $savingsVsStructure += $structureTotal - $bestPrice;
            } elseif ($jitaWithImport !== null) {
                $bestLocation = 'jita';
                $bestPrice = $jitaWithImport;
            } elseif ($structureTotal !== null) {
                $bestLocation = 'structure';
                $bestPrice = $structureTotal;
            }

            $totals->jita = $jitaTotal === null || $totals->jita === null ? null : $totals->jita + $jitaTotal;
            $totals->structure = $structureTotal === null || $totals->structure === null ? null : $totals->structure + $structureTotal;
            $totals->best = $bestPrice === null || $totals->best === null ? null : $totals->best + $bestPrice;
            if ($importCost !== null) {
                $importTotal += $importCost;
            }
            if ($totalItemVolume !== null) {
                $volumeTotal += $totalItemVolume;
            }

            // Weighted Jita prices
            $weightedData = $weightedJitaPrices[$typeId] ?? null;
            $jitaWeightedUnitPrice = $weightedData !== null ? $weightedData['weightedPrice'] : null;
            $jitaWeightedTotal = $jitaWeightedUnitPrice !== null ? $jitaWeightedUnitPrice * $quantity : null;
            $jitaCoverage = $weightedData['coverage'] ?? null;

            $material = new ShoppingListMaterialResource();
            $material->typeId = $typeId;
            $material->typeName = $mat['typeName'];
            $material->quantity = $quantity;
            $material->volume = $volume;
            $material->totalVolume = $totalItemVolume;
            $material->jitaUnitPrice = $jitaPrice;
            $material->jitaTotal = $jitaTotal;
            $material->importCost = $importCost;
            $material->jitaWithImport = $jitaWithImport;
            $material->structureUnitPrice = $structurePrice;
            $material->structureTotal = $structureTotal;
            $material->bestLocation = $bestLocation;
            $material->bestPrice = $bestPrice;
            $material->savings = $savings;
            $material->purchasedQuantity = $purchasedQuantities[$typeId] ?? 0;
            $material->extraQuantity = $mat['extraQuantity'] ?? 0;
            $material->jitaWeightedUnitPrice = $jitaWeightedUnitPrice;
            $material->jitaWeightedTotal = $jitaWeightedTotal;
            $material->jitaCoverage = $jitaCoverage;
            $material->isInventionMaterial = $mat['isInventionMaterial'] ?? false;

            $enrichedMaterials[] = $material;
        }

        $everyVolumeIsKnown = $missingVolumeTypeIds === [];
        $totals->import = $everyVolumeIsKnown ? $importTotal : null;
        $totals->jitaWithImport = $totals->jita === null || $totals->import === null ? null : $totals->jita + $totals->import;
        $totals->volume = $everyVolumeIsKnown ? $volumeTotal : null;
        $totals->savingsVsJitaWithImport = $everyVolumeIsKnown && $priceError === null ? $savingsVsJitaWithImport : null;
        $totals->savingsVsStructure = $priceError === null ? $savingsVsStructure : null;

        $structureLastSync = null;
        if ($priceData !== null && $priceData['structureLastSync'] !== null) {
            $structureLastSync = $priceData['structureLastSync']->format('c');
        }

        $resource = new ShoppingListResource();
        $resource->id = $uriVariables['id'];
        $resource->materials = $enrichedMaterials;
        $resource->structureId = $priceData !== null ? $priceData['structureId'] : $structureId;
        $resource->structureName = $priceData !== null ? $priceData['structureName'] : $this->resolvedStructureName($user, $structureId);
        $resource->structureAccessible = $priceData !== null && $priceData['structureAccessible'];
        $resource->structureFromCache = $priceData !== null && $priceData['structureFromCache'];
        $resource->structureLastSync = $structureLastSync;
        $resource->transportCostPerM3 = $transportCostPerM3;
        $resource->totals = $totals;
        $resource->priceError = $priceError;
        ksort($missingVolumeTypeIds);
        $resource->missingVolumeTypeIds = array_values($missingVolumeTypeIds);
        ksort($missingPriceTypeIds);
        $resource->missingPriceTypeIds = array_values($missingPriceTypeIds);
        $resource->failedProducts = $shoppingList['failedProducts'];

        return $resource;
    }

    private function resolvedStructureName(User $user, int $structureId): ?string
    {
        if ($structureId === $user->getPreferredMarketStructureId()) {
            return $user->getPreferredMarketStructureName();
        }

        if ($structureId === $this->marketService->getDefaultStructureId()) {
            return $this->marketService->getDefaultStructureName();
        }

        return null;
    }
}
