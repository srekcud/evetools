<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\ProjectJobMatchResource;
use App\Entity\User;
use App\Repository\IndustryProjectJobMatchRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\UuidOrNotFound;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectJobMatchResource>
 */
class JobMatchDeleteProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectJobMatchRepository $matchRepository,
        private readonly GroupProjectAccessChecker $accessChecker,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectJobMatchResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['projectId'], 'Project not found'));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertOwner($user, $project);

        $match = $this->matchRepository->find(UuidOrNotFound::of($uriVariables['id'], 'Job match not found'));
        if ($match === null || $match->getBomLine()->getProject() !== $project) {
            throw new NotFoundHttpException('Job match not found');
        }

        return ProjectJobMatchResource::fromEntity($match);
    }
}
