<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectCourierResource;
use App\Repository\IndustryProjectCourierContractRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\UuidOrNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProcessorInterface<ProjectCourierResource, void>
 */
final readonly class UnlinkCourierContractProcessor implements ProcessorInterface
{
    public function __construct(
        private IndustryProjectRepository $projectRepository,
        private IndustryProjectCourierContractRepository $linkRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        \assert($data instanceof ProjectCourierResource);

        $project = $this->projectRepository->find(UuidOrNotFound::of($data->id, 'Project not found'));
        if (null === $project) {
            throw new NotFoundHttpException('Project not found');
        }

        $link = $this->linkRepository->findOneByProject($project);
        if (null === $link) {
            throw new NotFoundHttpException('No courier contract is linked to this project');
        }

        $this->entityManager->remove($link);
        $this->entityManager->flush();
    }
}
