<?php

declare(strict_types=1);

namespace App\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GroupIndustry\GroupIndustryProjectResource;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Repository\GroupIndustryProjectMemberRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<GroupIndustryProjectResource>
 */
class GroupProjectCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
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

        $memberships = $this->memberRepository->findAcceptedWithProjectsByUser($user);
        $membersCountByProject = $this->memberRepository->countAcceptedByProject(
            array_map(static fn (GroupIndustryProjectMember $membership) => $membership->getProject(), $memberships),
        );

        $resources = [];
        foreach ($memberships as $membership) {
            $project = $membership->getProject();
            $resources[] = $this->mapper->projectToResource(
                $project,
                $membership,
                $membersCountByProject[(string) $project->getId()],
            );
        }

        return $resources;
    }
}
