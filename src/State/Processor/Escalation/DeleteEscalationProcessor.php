<?php

declare(strict_types=1);

namespace App\State\Processor\Escalation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Repository\EscalationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

class DeleteEscalationProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EscalationRepository $escalationRepository,
        private readonly EntityManagerInterface $em,
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

        $this->em->remove($escalation);
        $this->em->flush();
    }
}
