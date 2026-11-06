<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\IndustryProjectResource;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectParticipantRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<IndustryProjectResource>
 */
class ProjectCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectParticipantRepository $memberRepository,
        private readonly ProjectResourceMapper $mapper,
        private readonly IndustryProjectContributionRepository $contributionRepository,
    ) {
    }

    /** @return IndustryProjectResource[] */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $memberships = $this->memberRepository->findAcceptedWithProjectsByUser($user);
        $projects = array_values(array_map(static fn (IndustryProjectParticipant $membership) => $membership->getProject(), $memberships));
        $membersCountByProject = $this->memberRepository->countAcceptedByProject($projects);
        $approvedValueByProject = $this->contributionRepository->sumApprovedValueByProject($projects);

        $resources = [];
        foreach ($memberships as $membership) {
            $project = $membership->getProject();
            $resources[] = $this->mapper->projectToResource(
                $project,
                $membership,
                $membersCountByProject[(string) $project->getId()],
                $approvedValueByProject[(string) $project->getId()],
            );
        }

        return $resources;
    }
}
