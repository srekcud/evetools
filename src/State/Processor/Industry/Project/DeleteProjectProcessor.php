<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Repository\IndustryProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<mixed, void>
 */
class DeleteProjectProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly IndustryProjectRepository $projectRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        // The ProjectDeleteProvider already verified ownership
        $project = $this->projectRepository->find(Uuid::fromString($uriVariables['id']));

        if ($project !== null) {
            $this->entityManager->remove($project);
            $this->entityManager->flush();
        }
    }
}
