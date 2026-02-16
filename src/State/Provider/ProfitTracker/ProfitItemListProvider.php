<?php

declare(strict_types=1);

namespace App\State\Provider\ProfitTracker;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ProfitTracker\ProfitItemListResource;
use App\ApiResource\ProfitTracker\ProfitItemResource;
use App\Entity\User;
use App\Service\Industry\ProfitCalculationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProfitItemListResource>
 */
class ProfitItemListProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly ProfitCalculationService $calculationService,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProfitItemListResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();
        $days = (int) ($request?->query->get('days', 30) ?? 30);
        $sort = (string) ($request?->query->get('sort', 'profit') ?? 'profit');
        $order = (string) ($request?->query->get('order', 'desc') ?? 'desc');
        $filter = (string) ($request?->query->get('filter', 'all') ?? 'all');

        $items = $this->calculationService->getItemProfits($user, $days, $sort, $order, $filter);

        $resource = new ProfitItemListResource();
        foreach ($items as $item) {
            $itemResource = new ProfitItemResource();
            $itemResource->productTypeId = $item['productTypeId'];
            $itemResource->typeName = $item['typeName'];
            $itemResource->quantitySold = $item['quantitySold'];
            $itemResource->materialCost = $item['materialCost'];
            $itemResource->jobInstallCost = $item['jobInstallCost'];
            $itemResource->taxAmount = $item['taxAmount'];
            $itemResource->totalCost = $item['totalCost'];
            $itemResource->revenue = $item['revenue'];
            $itemResource->profit = $item['profit'];
            $itemResource->marginPercent = $item['marginPercent'];
            $itemResource->lastSaleDate = $item['lastSaleDate'];
            $resource->items[] = $itemResource;
        }

        return $resource;
    }
}
