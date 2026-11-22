<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\EmptyInput;
use App\Entity\User;
use App\Repository\UnassignedJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<EmptyInput, null>
 */
class DismissUnassignedJobProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly UnassignedJobRepository $unassignedJobRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $unassignedJob = $this->unassignedJobRepository->findOneOfUser($user, (int) $uriVariables['esiJobId']);
        if ($unassignedJob === null) {
            throw new NotFoundHttpException('Unassigned job not found');
        }

        $unassignedJob->setDismissedAt($this->clock->now());
        $this->entityManager->flush();

        return null;
    }
}
