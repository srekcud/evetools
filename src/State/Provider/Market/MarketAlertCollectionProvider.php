<?php

declare(strict_types=1);

namespace App\State\Provider\Market;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Market\MarketAlertResource;
use App\Entity\MarketPriceAlert;
use App\Entity\User;
use App\Enum\AlertPriceSource;
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
                    AlertPriceSource::StructureSell,
                    AlertPriceSource::StructureBuy,
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
            $resource->direction = $alert->getDirection()->value;
            $resource->threshold = $alert->getThreshold();
            $resource->priceSource = $alert->getPriceSource()->value;
            $resource->status = $alert->getStatus()->value;
            $resource->triggeredAt = $alert->getTriggeredAt()?->format('c');
            $resource->createdAt = $alert->getCreatedAt()->format('c');

            // Enrich with current price
            $resource->currentPrice = match ($alert->getPriceSource()) {
                AlertPriceSource::JitaSell => $sellPrices[$alert->getTypeId()] ?? null,
                AlertPriceSource::JitaBuy => $buyPrices[$alert->getTypeId()] ?? null,
                AlertPriceSource::StructureSell => $structureSellPrices[$alert->getTypeId()] ?? null,
                AlertPriceSource::StructureBuy => $structureBuyPrices[$alert->getTypeId()] ?? null,
            };

            return $resource;
        }, $alerts);
    }
}
