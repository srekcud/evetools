<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectActivityResource;
use App\Entity\IndustryProjectActivity;
use App\Entity\User;
use App\Repository\IndustryProjectActivityRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ProjectRunningJobsReader;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectActivityResource>
 */
final readonly class ProjectActivityProvider implements ProviderInterface
{
    private const int ACTIVITY_PAGE_SIZE = 100;

    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private IndustryProjectActivityRepository $activityRepository,
        private ProjectRunningJobsReader $runningJobsReader,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectActivityResource
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

        $resource = new ProjectActivityResource();
        $resource->id = $projectId;
        $resource->events = array_map(
            static fn (IndustryProjectActivity $activity): array => [
                'id' => $activity->getId()->toRfc4122(),
                'kind' => $activity->getKind()->value,
                'occurredAt' => $activity->getOccurredAt()->format(\DateTimeInterface::ATOM),
                'data' => $activity->getData(),
            ],
            $this->activityRepository->latestOf($project, self::ACTIVITY_PAGE_SIZE),
        );
        $resource->runningJobs = $this->runningJobsReader->of($project, $user);

        return $resource;
    }
}
