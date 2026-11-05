<?php

declare(strict_types=1);

namespace App\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GroupIndustry\GroupIndustryProjectResource;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\GroupProjectStatus;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<GroupIndustryProjectResource>
 */
class AvailableGroupProjectsProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectParticipantRepository $memberRepository,
        private readonly GroupIndustryResourceMapper $mapper,
        private readonly IndustryProjectContributionRepository $contributionRepository,
    ) {
    }

    /** @return GroupIndustryProjectResource[] */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $userCorpId = $user->getCorporationId();

        if ($userCorpId === null) {
            return [];
        }

        // Find all same-corp projects with open status
        $corpProjects = $this->projectRepository->findByOwnerCorporation(
            $userCorpId,
            [GroupProjectStatus::Published, GroupProjectStatus::InProgress],
        );

        // Own projects and projects the user already has a membership in (whatever its status) are not available
        $ownProjectsExcluded = array_values(array_filter(
            $corpProjects,
            static fn (IndustryProject $project): bool => $project->getOwner() !== $user,
        ));
        if ($ownProjectsExcluded === []) {
            return [];
        }

        $joinedProjectIds = array_map(
            static fn (IndustryProjectParticipant $membership): string => (string) $membership->getProject()->getId(),
            $this->memberRepository->findBy(['project' => $ownProjectsExcluded, 'user' => $user]),
        );
        $availableProjects = array_values(array_filter(
            $ownProjectsExcluded,
            static fn (IndustryProject $project): bool => !\in_array((string) $project->getId(), $joinedProjectIds, true),
        ));

        $this->memberRepository->loadItemsAndBomItems($availableProjects);
        $membersCountByProject = $this->memberRepository->countAcceptedByProject($availableProjects);
        $approvedValueByProject = $this->contributionRepository->sumApprovedValueByProject($availableProjects);

        $resources = [];
        foreach ($availableProjects as $project) {
            $resources[] = $this->mapper->projectToResource(
                $project,
                null,
                $membersCountByProject[(string) $project->getId()],
                $approvedValueByProject[(string) $project->getId()],
            );
        }

        return $resources;
    }
}
