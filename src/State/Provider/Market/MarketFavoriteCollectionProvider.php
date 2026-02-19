<?php

declare(strict_types=1);

namespace App\State\Provider\Market;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Market\MarketFavoriteResource;
use App\Entity\MarketFavorite;
use App\Entity\User;
use App\Repository\MarketFavoriteRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\JitaMarketService;
use App\Service\MarketHistoryService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<MarketFavoriteResource>
 */
class MarketFavoriteCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly MarketFavoriteRepository $favoriteRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly JitaMarketService $jitaMarketService,
        private readonly MarketHistoryService $marketHistoryService,
    ) {
    }

    /**
     * @return MarketFavoriteResource[]
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $favorites = $this->favoriteRepository->findByUser($user);

        if (empty($favorites)) {
            return [];
        }

        $typeIds = array_map(
            static fn (MarketFavorite $fav) => $fav->getTypeId(),
            $favorites,
        );

        // Batch-load SDE types and prices
        $types = $this->invTypeRepository->findByTypeIds($typeIds);
        $sellPrices = $this->jitaMarketService->getPricesWithFallback($typeIds);
        $buyPrices = $this->jitaMarketService->getBuyPricesWithFallback($typeIds);

        $resources = [];

        foreach ($favorites as $favorite) {
            $typeId = $favorite->getTypeId();
            $invType = $types[$typeId] ?? null;

            $resource = new MarketFavoriteResource();
            $resource->typeId = $typeId;
            $resource->typeName = $invType?->getTypeName() ?? "Type #{$typeId}";
            $resource->jitaSell = $sellPrices[$typeId] ?? null;
            $resource->jitaBuy = $buyPrices[$typeId] ?? null;
            $resource->change30d = $this->marketHistoryService->get30dPriceChange($typeId);
            $resource->createdAt = $favorite->getCreatedAt()->format('c');

            $resources[] = $resource;
        }

        return $resources;
    }
}
