<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ProjectStepResource;
use App\Repository\IndustryStepJobMatchRepository;
use App\Service\UuidOrNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProcessorInterface<ProjectStepResource, void>
 */
class UnlinkJobProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IndustryStepJobMatchRepository $matchRepository,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $match = $this->matchRepository->find(UuidOrNotFound::of($data->id, 'Job match not found'));

        if ($match === null) {
            throw new NotFoundHttpException('Job match not found');
        }

        $match->getStep()->getJobMatches()->removeElement($match);
        $this->entityManager->remove($match);
        $this->entityManager->flush();
    }
}
