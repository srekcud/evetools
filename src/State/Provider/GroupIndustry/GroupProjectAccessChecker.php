<?php

declare(strict_types=1);

namespace App\State\Provider\GroupIndustry;

use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Repository\GroupIndustryProjectMemberRepository;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class GroupProjectAccessChecker
{
    private const string NO_ACCESS_MESSAGE = 'You do not have access to this project';

    public function __construct(
        private readonly GroupIndustryProjectMemberRepository $memberRepository,
    ) {
    }

    public function assertCanRead(User $user, GroupIndustryProject $project): void
    {
        if ($this->isOwnerOrAcceptedMember($user, $project) || $this->isInOwnerCorporation($user, $project)) {
            return;
        }

        throw new AccessDeniedHttpException(self::NO_ACCESS_MESSAGE);
    }

    /**
     * Verify the user is an accepted member of the project (or the owner).
     *
     * @throws AccessDeniedHttpException if the user is not a member
     */
    public function assertAcceptedMember(User $user, GroupIndustryProject $project): void
    {
        if ($this->isOwnerOrAcceptedMember($user, $project)) {
            return;
        }

        throw new AccessDeniedHttpException(self::NO_ACCESS_MESSAGE);
    }

    public function assertCanManageMember(User $user, GroupIndustryProject $project, GroupIndustryProjectMember $member): void
    {
        if ($project->getOwner() === $user || $member->getRole() !== GroupMemberRole::Admin) {
            return;
        }

        throw new AccessDeniedHttpException('Only the project owner can manage an admin');
    }

    /**
     * Verify the user is admin or owner of the project.
     *
     * @throws AccessDeniedHttpException if the user is not admin or owner
     */
    public function assertAdminOrOwner(User $user, GroupIndustryProject $project): void
    {
        if ($project->getOwner() === $user) {
            return;
        }

        $member = $this->memberRepository->findOneBy([
            'project' => $project,
            'user' => $user,
            'status' => GroupMemberStatus::Accepted,
            'role' => GroupMemberRole::Admin,
        ]);
        if ($member !== null) {
            return;
        }

        throw new AccessDeniedHttpException('Only project owner or admin can perform this action');
    }

    private function isOwnerOrAcceptedMember(User $user, GroupIndustryProject $project): bool
    {
        if ($project->getOwner() === $user) {
            return true;
        }

        return $this->memberRepository->findOneBy([
            'project' => $project,
            'user' => $user,
            'status' => GroupMemberStatus::Accepted,
        ]) !== null;
    }

    private function isInOwnerCorporation(User $user, GroupIndustryProject $project): bool
    {
        $userCorporationId = $user->getCorporationId();
        $ownerCorporationId = $project->getOwner()->getCorporationId();

        return $userCorporationId !== null && $userCorporationId === $ownerCorporationId;
    }
}
