<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectStationTransferResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ProjectContainerReadings;
use App\Service\Industry\Project\Transfer\ProjectStationTransfers;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectStationTransferResource>
 */
final readonly class ProjectStationTransfersProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private ProjectContainerReadings $containerReadings,
        private ProjectStationTransfers $transfers,
        private ProjectStationTransferResourceMapper $mapper,
    ) {
    }

    /**
     * @return list<ProjectStationTransferResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
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

        return $this->mapper->toResources($project, $this->transfers->of($project, $this->containerReadings->of($project)));
    }
}
