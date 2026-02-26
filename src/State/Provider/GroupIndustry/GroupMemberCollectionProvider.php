<?php

declare(strict_types=1);

namespace App\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GroupIndustry\GroupIndustryMemberResource;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Repository\GroupIndustryContributionRepository;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\GroupIndustryProjectRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<GroupIndustryMemberResource>
 */
class GroupMemberCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly GroupIndustryProjectRepository $projectRepository,
        private readonly GroupIndustryProjectMemberRepository $memberRepository,
        private readonly GroupIndustryContributionRepository $contributionRepository,
        private readonly GroupProjectAccessChecker $accessChecker,
        private readonly GroupIndustryResourceMapper $mapper,
    ) {
    }

    /**
     * @return GroupIndustryMemberResource[]
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

        $project = $this->projectRepository->find(Uuid::fromString($projectId));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        $members = $this->memberRepository->findBy(['project' => $project]);

        // Pre-load approved contributions for all members in one query
        $approvedContributions = $this->contributionRepository->findBy([
            'project' => $project,
            'status' => ContributionStatus::Approved,
        ]);

        // Index contributions by member ID for efficient lookup
        $contributionsByMember = [];
        foreach ($approvedContributions as $contribution) {
            $memberId = $contribution->getMember()->getId()->toString();
            $contributionsByMember[$memberId][] = $contribution;
        }

        return array_map(
            function (GroupIndustryProjectMember $member) use ($contributionsByMember) {
                $memberId = $member->getId()->toString();
                $memberContributions = $contributionsByMember[$memberId] ?? [];

                $totalValue = 0.0;
                foreach ($memberContributions as $contribution) {
                    $totalValue += $contribution->getEstimatedValue();
                }

                return $this->mapper->memberToResource($member, $totalValue, count($memberContributions));
            },
            $members,
        );
    }
}
