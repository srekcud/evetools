<?php

declare(strict_types=1);

namespace App\State\Provider\ProfitTracker;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ProfitTracker\ProfitSummaryResource;
use App\Entity\User;
use App\Service\Industry\ProfitCalculationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProfitSummaryResource>
 */
class ProfitSummaryProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly ProfitCalculationService $calculationService,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProfitSummaryResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();
        $days = (int) ($request?->query->get('days', 30) ?? 30);

        $summary = $this->calculationService->getSummary($user, $days);

        $resource = new ProfitSummaryResource();
        $resource->totalProfit = $summary['totalProfit'];
        $resource->totalRevenue = $summary['totalRevenue'];
        $resource->totalCost = $summary['totalCost'];
        $resource->avgMargin = $summary['avgMargin'];
        $resource->itemCount = $summary['itemCount'];
        $resource->bestItem = $summary['bestItem'];
        $resource->worstItem = $summary['worstItem'];

        return $resource;
    }
}
