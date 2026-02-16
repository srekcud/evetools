<?php

declare(strict_types=1);

namespace App\State\Provider\ProfitTracker;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ProfitTracker\ProfitItemDetailResource;
use App\Entity\User;
use App\Service\Industry\ProfitCalculationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProfitItemDetailResource>
 */
class ProfitItemDetailProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly ProfitCalculationService $calculationService,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProfitItemDetailResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $typeId = (int) ($uriVariables['typeId'] ?? 0);
        $request = $this->requestStack->getCurrentRequest();
        $days = (int) ($request?->query->get('days', 30) ?? 30);

        $detail = $this->calculationService->getItemDetail($user, $typeId, $days);

        $resource = new ProfitItemDetailResource();
        $resource->typeId = $typeId;
        $resource->costBreakdown = $detail['costBreakdown'];
        $resource->matches = $detail['matches'];
        $resource->marginTrend = $detail['marginTrend'];

        return $resource;
    }
}
