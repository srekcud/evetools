<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectParticipantResource;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectParticipantResource>
 */
class ProjectParticipantCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectParticipantRepository $memberRepository,
        private readonly IndustryProjectContributionRepository $contributionRepository,
        private readonly ProjectAccessChecker $accessChecker,
        private readonly ProjectResourceMapper $mapper,
    ) {
    }

    /**
     * @return ProjectParticipantResource[]
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
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        $members = $this->memberRepository->findBy([
            'project' => $project,
            'status' => [ParticipantStatus::Accepted, ParticipantStatus::Pending],
        ]);
        $this->memberRepository->loadUsersWithMainCharacter($members);

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
            function (IndustryProjectParticipant $member) use ($contributionsByMember) {
                $memberId = $member->getId()->toString();
                $memberContributions = $contributionsByMember[$memberId] ?? [];

                $totalValue = 0.0;
                foreach ($memberContributions as $contribution) {
                    $value = $contribution->getEstimatedValue();
                    if ($value === null) {
                        $totalValue = null;
                        break;
                    }
                    $totalValue += $value;
                }

                return $this->mapper->memberToResource($member, $totalValue, count($memberContributions));
            },
            $members,
        );
    }
}
