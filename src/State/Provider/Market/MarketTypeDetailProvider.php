<?php

declare(strict_types=1);

namespace App\State\Provider\Market;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Market\MarketTypeDetailResource;
use App\Entity\User;
use App\Repository\MarketFavoriteRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\JitaMarketService;
use App\Service\MarketHistoryService;
use App\Service\StructureMarketService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<MarketTypeDetailResource>
 */
class MarketTypeDetailProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly JitaMarketService $jitaMarketService,
        private readonly StructureMarketService $structureMarketService,
        private readonly MarketHistoryService $marketHistoryService,
        private readonly MarketFavoriteRepository $favoriteRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MarketTypeDetailResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $typeId = (int) ($uriVariables['typeId'] ?? 0);
        $invType = $this->invTypeRepository->findByTypeId($typeId);

        if ($invType === null) {
            throw new NotFoundHttpException('Item type not found');
        }

        $resource = new MarketTypeDetailResource();
        $resource->typeId = $invType->getTypeId();
        $resource->typeName = $invType->getTypeName();
        $resource->groupName = $invType->getGroup()->getGroupName();
        $resource->categoryName = $invType->getGroup()->getCategory()->getCategoryName();

        // Jita prices and order books
        $resource->jitaSell = $this->jitaMarketService->getPrice($typeId);
        $resource->jitaBuy = $this->jitaMarketService->getBuyPrice($typeId);
        $resource->sellOrders = $this->jitaMarketService->getSellOrders($typeId);
        $resource->buyOrders = $this->jitaMarketService->getBuyOrders($typeId);

        // Spread
        if ($resource->jitaSell !== null && $resource->jitaBuy !== null && $resource->jitaSell > 0) {
            $resource->spread = round(($resource->jitaSell - $resource->jitaBuy) / $resource->jitaSell * 100, 2);
        }

        // Structure prices (if user has a preferred structure)
        $structureId = $user->getPreferredMarketStructureId();
        if ($structureId !== null) {
            $resource->structureSell = $this->structureMarketService->getLowestSellPrice($structureId, $typeId);
        }

        // Volume and price change from history
        $volumes = $this->jitaMarketService->getAverageDailyVolumes([$typeId]);
        $resource->avgDailyVolume = $volumes[$typeId] ?? null;
        $resource->change30d = $this->marketHistoryService->get30dPriceChange($typeId);

        // Check if favorite
        $favorite = $this->favoriteRepository->findByUserAndType($user, $typeId);
        $resource->isFavorite = $favorite !== null;

        return $resource;
    }
}
