<?php

declare(strict_types=1);

namespace App\State\Provider\Escalation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Escalation\EscalationResource;
use App\Entity\User;
use App\Repository\EscalationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<EscalationResource>
 */
class EscalationCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EscalationRepository $escalationRepository,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @return EscalationResource[]
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();
        $visibility = $request?->query->get('visibility');
        $saleStatus = $request?->query->get('saleStatus');
        $activeOnly = filter_var($request?->query->get('active', 'false'), FILTER_VALIDATE_BOOLEAN);

        $entries = $this->escalationRepository->findByUser($user, $visibility, $saleStatus, $activeOnly);

        return array_map(fn($e) => EscalationResourceMapper::toResource($e, true), $entries);
    }
}
