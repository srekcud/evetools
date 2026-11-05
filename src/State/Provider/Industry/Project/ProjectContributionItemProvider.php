<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectContributionResource;
use App\Entity\User;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<ProjectContributionResource>
 */
class ProjectContributionItemProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectContributionRepository $contributionRepository,
        private readonly ProjectResourceMapper $mapper,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectContributionResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $projectId = $uriVariables['projectId'] ?? null;
        $contributionId = $uriVariables['id'] ?? null;

        if ($projectId === null || $contributionId === null) {
            throw new NotFoundHttpException('Contribution not found');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($projectId, 'Project not found'));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $contribution = $this->contributionRepository->find(Uuid::fromString($contributionId));
        if ($contribution === null || $contribution->getProject() !== $project) {
            throw new NotFoundHttpException('Contribution not found');
        }

        return $this->mapper->contributionToResource($contribution);
    }
}
