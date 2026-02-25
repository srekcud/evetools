<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\StockpileStatusResource;
use App\Entity\User;
use App\Service\Industry\StockpileService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<StockpileStatusResource>
 */
class StockpileStatusProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly StockpileService $stockpileService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StockpileStatusResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $status = $this->stockpileService->getStockpileStatus($user);

        $resource = new StockpileStatusResource();
        $resource->targetCount = $status['targetCount'];
        $resource->stages = $status['stages'];
        $resource->kpis = $status['kpis'];
        $resource->shoppingList = $status['shoppingList'];

        return $resource;
    }
}
