<?php

declare(strict_types=1);

namespace App\State\Provider\Escalation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Escalation\EscalationResource;
use App\Entity\User;
use App\Repository\EscalationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<EscalationResource>
 */
class EscalationDeleteProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EscalationRepository $escalationRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): EscalationResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $id = $uriVariables['id'] ?? null;
        if ($id === null) {
            throw new NotFoundHttpException('Escalation not found');
        }

        $escalation = $this->escalationRepository->find(Uuid::fromString($id));
        if ($escalation === null) {
            throw new NotFoundHttpException('Escalation not found');
        }

        if (!$escalation->isOwnedBy($user)) {
            throw new AccessDeniedHttpException('Access denied');
        }

        return EscalationResourceMapper::toResource($escalation, true);
    }
}
