<?php

declare(strict_types=1);

namespace App\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GroupIndustry\GroupIndustryProjectResource;
use App\Entity\User;
use App\Enum\GroupProjectStatus;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\GroupIndustryProjectRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<GroupIndustryProjectResource>
 */
class AvailableGroupProjectsProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly GroupIndustryProjectRepository $projectRepository,
        private readonly GroupIndustryProjectMemberRepository $memberRepository,
        private readonly GroupIndustryResourceMapper $mapper,
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

        // Filter out projects where user is already a member
        $resources = [];
        foreach ($corpProjects as $project) {
            // Skip own projects
            if ($project->getOwner() === $user) {
                continue;
            }

            $existingMembership = $this->memberRepository->findOneBy([
                'project' => $project,
                'user' => $user,
            ]);

            if ($existingMembership !== null) {
                continue;
            }

            $resources[] = $this->mapper->projectToResource($project, null);
        }

        return $resources;
    }
}
