<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Repository\IndustryStepJobMatchRepository;
use App\Service\UuidOrNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<mixed, void>
 */
class UnlinkJobProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryStepJobMatchRepository $matchRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $match = $this->matchRepository->find(UuidOrNotFound::of($uriVariables['id'], 'Job match not found'));

        if ($match === null) {
            throw new NotFoundHttpException('Job match not found');
        }

        // Verify ownership
        $step = $match->getStep();
        if ($step->getProject()->getUser() !== $user) {
            throw new NotFoundHttpException('Job match not found');
        }

        $step->getJobMatches()->removeElement($match);
        $this->entityManager->remove($match);
        $this->entityManager->flush();
    }
}
