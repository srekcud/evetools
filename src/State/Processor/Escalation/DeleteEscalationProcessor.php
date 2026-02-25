<?php

declare(strict_types=1);

namespace App\State\Processor\Escalation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Escalation;
use App\Entity\User;
use App\Enum\EscalationVisibility;
use App\Repository\EscalationRepository;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<mixed, void> */
class DeleteEscalationProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EscalationRepository $escalationRepository,
        private readonly EntityManagerInterface $em,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $id = $uriVariables['id'] ?? null;
        $escalation = $this->escalationRepository->find(Uuid::fromString($id));

        if ($escalation === null) {
            throw new NotFoundHttpException('Escalation not found');
        }

        if (!$escalation->isOwnedBy($user)) {
            throw new AccessDeniedHttpException('Access denied');
        }

        $visibility = $escalation->getVisibility();
        $escalationData = [
            'id' => $escalation->getId()?->toRfc4122(),
            'type' => $escalation->getType(),
            'solarSystemName' => $escalation->getSolarSystemName(),
            'characterName' => $escalation->getCharacterName(),
            'visibility' => $visibility->value,
        ];
        $corporationId = $escalation->getCorporationId();
        $allianceId = $escalation->getAllianceId();

        $this->em->remove($escalation);
        $this->em->flush();

        // Publish Mercure event for non-personal escalations
        if ($visibility !== EscalationVisibility::Perso) {
            $this->mercurePublisher->publishEscalationEvent(
                'deleted',
                $escalationData,
                $corporationId,
                $allianceId,
                $visibility->value,
            );
        }
    }
}
