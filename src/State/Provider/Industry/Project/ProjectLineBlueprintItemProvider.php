<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectLineBlueprintResource;
use App\Entity\User;
use App\Repository\IndustryProjectLineBlueprintRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectLineBlueprintResource>
 */
final readonly class ProjectLineBlueprintItemProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private IndustryProjectLineBlueprintRepository $lineBlueprintRepository,
        private ProjectAccessChecker $accessChecker,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectLineBlueprintResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['projectId'] ?? null, 'Project not found'));
        if (null === $project) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        $attachment = $this->lineBlueprintRepository->findOneByProjectAndId($project, UuidOrNotFound::of($uriVariables['id'] ?? null, 'Blueprint not found'))
            ?? throw new NotFoundHttpException('Blueprint not found');

        $resource = new ProjectLineBlueprintResource();
        $resource->id = $attachment->getId()->toRfc4122();
        $resource->bomLineId = $attachment->getBomLine()->getId()->toRfc4122();
        $resource->itemId = $attachment->getItemId();

        return $resource;
    }
}
