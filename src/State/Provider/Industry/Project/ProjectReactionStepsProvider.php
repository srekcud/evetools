<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectReactionStepsResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ProjectReactionStepsLoader;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectReactionStepsResource>
 */
final readonly class ProjectReactionStepsProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private ProjectReactionStepsLoader $stepsLoader,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectReactionStepsResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $projectId = $uriVariables['projectId'] ?? null;
        if (null === $projectId) {
            throw new NotFoundHttpException('Project not found');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($projectId, 'Project not found'));
        if (null === $project) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        $resource = new ProjectReactionStepsResource();
        $resource->id = $projectId;
        $resource->steps = $this->stepsLoader->load($project);

        return $resource;
    }
}
