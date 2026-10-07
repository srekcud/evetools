<?php

declare(strict_types=1);

namespace App\State\Provider\Escalation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Escalation\EscalationResource;
use App\Entity\Escalation;
use App\Entity\User;
use App\Enum\EscalationVisibility;
use App\Repository\EscalationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<EscalationResource>
 */
class EscalationProvider implements ProviderInterface
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
        // Hors audience, on répond comme pour un id inconnu pour ne pas révéler l'existence de l'escalation.
        if ($escalation === null || !$this->isVisibleTo($escalation, $user)) {
            throw new NotFoundHttpException('Escalation not found');
        }

        return EscalationResourceMapper::toResource($escalation, $escalation->isOwnedBy($user));
    }

    private function isVisibleTo(Escalation $escalation, User $reader): bool
    {
        if ($escalation->isOwnedBy($reader)) {
            return true;
        }

        return match ($escalation->getVisibility()) {
            EscalationVisibility::Perso => false,
            EscalationVisibility::Corp => $reader->getCorporationId() === $escalation->getCorporationId(),
            EscalationVisibility::Alliance => $escalation->getAllianceId() !== null
                && $reader->getAllianceId() === $escalation->getAllianceId(),
            EscalationVisibility::Public => true,
        };
    }
}
