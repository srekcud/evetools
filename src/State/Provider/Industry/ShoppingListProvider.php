<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\ShoppingListMaterialResource;
use App\ApiResource\Industry\ShoppingListResource;
use App\ApiResource\Industry\ShoppingListTotalsResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\ESI\MarketService;
use App\Service\Industry\IndustryProjectService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<ShoppingListResource>
 */
class ShoppingListProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectService $projectService,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly MarketService $marketService,
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

        $project = $this->projectRepository->find(Uuid::fromString($uriVariables['id']));

        if ($project === null || $project->getUser() !== $user) {
            throw new NotFoundHttpException('Project not found');
        }

        $materials = $this->projectService->getShoppingList($project);
        $purchasedQuantities = $this->projectService->getPurchasedQuantities($project);

        $request = $this->requestStack->getCurrentRequest();
        $structureId = $request?->query->get('structureId');
        $structureId = $structureId !== null ? (int) $structureId : $user->getPreferredMarketStructureId();
        $transportCostPerM3 = (float) ($request?->query->get('transportCost', 1200) ?? 1200);

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
            $volumes[$type->getTypeId()] = $type->getVolume() ?? 0.0;
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

        $enrichedMaterials = [];
        $totals = new ShoppingListTotalsResource();

        foreach ($materials as $mat) {
            $typeId = $mat['typeId'];
            $quantity = $mat['quantity'];
            $volume = $volumes[$typeId] ?? 0.0;
            $totalItemVolume = $volume * $quantity;

            $jitaPrice = $priceData !== null ? ($priceData['jita'][$typeId] ?? null) : null;
            $structurePrice = $priceData !== null ? ($priceData['structure'][$typeId] ?? null) : null;

            $jitaTotal = $jitaPrice !== null ? $jitaPrice * $quantity : null;
            $structureTotal = $structurePrice !== null ? $structurePrice * $quantity : null;

            $importCost = $totalItemVolume * $transportCostPerM3;
            $jitaWithImport = $jitaTotal !== null ? $jitaTotal + $importCost : null;

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
                $totals->jita += $jitaTotal;
                $totals->import += $importCost;
                $totals->jitaWithImport += $jitaWithImport;
            }
            if ($structureTotal !== null) {
                $totals->structure += $structureTotal;
            }
            if ($bestPrice !== null) {
                $totals->best += $bestPrice;
            }
            $totals->volume += $totalItemVolume;

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

            $enrichedMaterials[] = $material;
        }

        $totals->savingsVsJitaWithImport = $totals->jitaWithImport > 0 ? $totals->jitaWithImport - $totals->best : 0;
        $totals->savingsVsStructure = $totals->structure > 0 ? $totals->structure - $totals->best : 0;

        $structureLastSync = null;
        if ($priceData !== null && $priceData['structureLastSync'] !== null) {
            $structureLastSync = $priceData['structureLastSync']->format('c');
        }

        $resource = new ShoppingListResource();
        $resource->id = $uriVariables['id'];
        $resource->materials = $enrichedMaterials;
        $resource->structureId = $priceData !== null ? $priceData['structureId'] : $this->marketService->getDefaultStructureId();
        $resource->structureName = $priceData !== null ? $priceData['structureName'] : $this->marketService->getDefaultStructureName();
        $resource->structureAccessible = $priceData !== null && $priceData['structureAccessible'];
        $resource->structureFromCache = $priceData !== null && $priceData['structureFromCache'];
        $resource->structureLastSync = $structureLastSync;
        $resource->transportCostPerM3 = $transportCostPerM3;
        $resource->totals = $totals;
        $resource->priceError = $priceError;

        return $resource;
    }
}
