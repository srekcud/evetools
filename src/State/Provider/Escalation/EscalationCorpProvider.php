<?php

declare(strict_types=1);

namespace App\State\Provider\Escalation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Escalation\EscalationResource;
use App\Entity\User;
use App\Repository\EscalationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<EscalationResource>
 */
class EscalationCorpProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EscalationRepository $escalationRepository,
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

        $corporationId = $user->getCorporationId();
        if ($corporationId === null) {
            return [];
        }

        $entries = $this->escalationRepository->findByCorporation($corporationId, $user);

        return array_map(fn($e) => EscalationResourceMapper::toResource($e, false), $entries);
    }
}
