<?php

declare(strict_types=1);

namespace App\State\Provider\ProfitTracker;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ProfitTracker\ProfitUnmatchedResource;
use App\Entity\User;
use App\Service\Industry\ProfitCalculationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProfitUnmatchedResource>
 */
class ProfitUnmatchedProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly ProfitCalculationService $calculationService,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProfitUnmatchedResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();
        $days = (int) ($request?->query->get('days', 30) ?? 30);

        $unmatched = $this->calculationService->getUnmatched($user, $days);

        $resource = new ProfitUnmatchedResource();
        $resource->unmatchedJobs = $unmatched['unmatchedJobs'];
        $resource->unmatchedSales = $unmatched['unmatchedSales'];

        return $resource;
    }
}
