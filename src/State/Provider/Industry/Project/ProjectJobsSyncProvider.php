<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectJobsSyncResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Help\ScheduledSyncReader;
use App\Service\Industry\Project\ProjectJobsSyncReader;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectJobsSyncResource>
 */
final readonly class ProjectJobsSyncProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private ProjectJobsSyncReader $jobsSyncReader,
        private ScheduledSyncReader $scheduledSyncReader,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectJobsSyncResource
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

        $resource = new ProjectJobsSyncResource();
        $resource->id = $projectId;
        $resource->intervalSeconds = $this->scheduledSyncReader->industryJobsIntervalSeconds();
        $resource->installers = $this->jobsSyncReader->of($this->accessChecker->acceptedParticipantsOf($project), $user);

        return $resource;
    }
}
