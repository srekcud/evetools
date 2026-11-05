<?php

declare(strict_types=1);

namespace App\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GroupIndustry\GroupContainerVerificationResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\GroupIndustry\GroupIndustryContainerService;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<GroupContainerVerificationResource>
 */
class ContainerVerificationProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly GroupIndustryContainerService $containerService,
        private readonly GroupProjectAccessChecker $accessChecker,
    ) {
    }

    /**
     * @return GroupContainerVerificationResource[]
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $projectId = $uriVariables['projectId'] ?? null;
        if ($projectId === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($projectId, 'Project not found'));
        if ($project === null || $project->isPlannedBySteps()) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAdminOrOwner($user, $project);

        return $this->containerService->verifyContainer($project);
    }
}
