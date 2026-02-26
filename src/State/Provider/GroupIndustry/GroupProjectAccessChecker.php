<?php

declare(strict_types=1);

namespace App\State\Provider\GroupIndustry;

use App\Entity\GroupIndustryProject;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Repository\GroupIndustryProjectMemberRepository;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class GroupProjectAccessChecker
{
    public function __construct(
        private readonly GroupIndustryProjectMemberRepository $memberRepository,
    ) {
    }

    /**
     * Verify the user is an accepted member of the project (or the owner).
     *
     * @throws AccessDeniedHttpException if the user is not a member
     */
    public function assertAcceptedMember(User $user, GroupIndustryProject $project): void
    {
        if ($project->getOwner() === $user) {
            return;
        }

        $member = $this->memberRepository->findOneBy([
            'project' => $project,
            'user' => $user,
            'status' => GroupMemberStatus::Accepted,
        ]);
        if ($member !== null) {
            return;
        }

        throw new AccessDeniedHttpException('You do not have access to this project');
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
}
