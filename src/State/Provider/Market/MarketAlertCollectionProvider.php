<?php

declare(strict_types=1);

namespace App\State\Provider\Market;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Market\MarketAlertResource;
use App\Entity\MarketPriceAlert;
use App\Entity\User;
use App\Repository\MarketPriceAlertRepository;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<MarketAlertResource>
 */
class MarketAlertCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly MarketPriceAlertRepository $alertRepository,
        private readonly JitaMarketService $jitaMarketService,
        private readonly StructureMarketService $structureMarketService,
    ) {
    }

    /**
     * @return MarketAlertResource[]
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $alerts = $this->alertRepository->findByUser($user);

        if (empty($alerts)) {
            return [];
        }

        // Batch-load current Jita prices
        $typeIds = array_values(array_unique(array_map(
            static fn (MarketPriceAlert $a) => $a->getTypeId(),
            $alerts,
        )));
        $sellPrices = $this->jitaMarketService->getPricesWithFallback($typeIds);
        $buyPrices = $this->jitaMarketService->getBuyPricesWithFallback($typeIds);

        // Batch-load structure prices if user has a preferred structure and any alerts use it
        $structureSellPrices = [];
        $structureBuyPrices = [];
        $structureId = $user->getPreferredMarketStructureId();

        if ($structureId !== null) {
            $hasStructureAlerts = array_filter(
                $alerts,
                static fn (MarketPriceAlert $a) => in_array($a->getPriceSource(), [
                    MarketPriceAlert::SOURCE_STRUCTURE_SELL,
                    MarketPriceAlert::SOURCE_STRUCTURE_BUY,
                ], true),
            );

            if (!empty($hasStructureAlerts)) {
                $structureTypeIds = array_values(array_unique(array_map(
                    static fn (MarketPriceAlert $a) => $a->getTypeId(),
                    $hasStructureAlerts,
                )));
                $structureSellPrices = $this->structureMarketService->getLowestSellPrices($structureId, $structureTypeIds);
                $structureBuyPrices = $this->structureMarketService->getHighestBuyPrices($structureId, $structureTypeIds);
            }
        }

        return array_map(function (MarketPriceAlert $alert) use ($sellPrices, $buyPrices, $structureSellPrices, $structureBuyPrices) {
            $resource = new MarketAlertResource();
            $resource->id = $alert->getId()?->toRfc4122() ?? '';
            $resource->typeId = $alert->getTypeId();
            $resource->typeName = $alert->getTypeName();
            $resource->direction = $alert->getDirection();
            $resource->threshold = $alert->getThreshold();
            $resource->priceSource = $alert->getPriceSource();
            $resource->status = $alert->getStatus();
            $resource->triggeredAt = $alert->getTriggeredAt()?->format('c');
            $resource->createdAt = $alert->getCreatedAt()->format('c');

            // Enrich with current price
            $resource->currentPrice = match ($alert->getPriceSource()) {
                MarketPriceAlert::SOURCE_JITA_SELL => $sellPrices[$alert->getTypeId()] ?? null,
                MarketPriceAlert::SOURCE_JITA_BUY => $buyPrices[$alert->getTypeId()] ?? null,
                MarketPriceAlert::SOURCE_STRUCTURE_SELL => $structureSellPrices[$alert->getTypeId()] ?? null,
                MarketPriceAlert::SOURCE_STRUCTURE_BUY => $structureBuyPrices[$alert->getTypeId()] ?? null,
                default => null,
            };

            return $resource;
        }, $alerts);
    }
}
