<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectParticipantRepository;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ProjectAccessChecker
{
    private const string NO_ACCESS_MESSAGE = 'You do not have access to this project';
    private const string OWNER_ONLY_MESSAGE = 'Only the project owner can perform this action';

    public function __construct(
        private readonly IndustryProjectParticipantRepository $memberRepository,
    ) {
    }

    /**
     * Verify the user is an accepted member of the project (or the owner).
     *
     * @throws AccessDeniedHttpException if the user is not a member
     */
    public function assertAcceptedMember(User $user, IndustryProject $project): void
    {
        if ($this->isOwnerOrAcceptedMember($user, $project)) {
            return;
        }

        throw new AccessDeniedHttpException(self::NO_ACCESS_MESSAGE);
    }

    public function assertOwner(User $user, IndustryProject $project): void
    {
        if ($project->getOwner() !== $user) {
            throw new AccessDeniedHttpException(self::OWNER_ONLY_MESSAGE);
        }
    }

    public function assertCanManageMember(User $user, IndustryProject $project, IndustryProjectParticipant $member): void
    {
        if ($project->getOwner() === $user || $member->getRole() !== ParticipantRole::Admin) {
            return;
        }

        throw new AccessDeniedHttpException('Only the project owner can manage an admin');
    }

    /**
     * Verify the user is admin or owner of the project.
     *
     * @throws AccessDeniedHttpException if the user is not admin or owner
     */
    public function assertAdminOrOwner(User $user, IndustryProject $project): void
    {
        if ($project->getOwner() === $user) {
            return;
        }

        $member = $this->memberRepository->findOneBy([
            'project' => $project,
            'user' => $user,
            'status' => ParticipantStatus::Accepted,
            'role' => ParticipantRole::Admin,
        ]);
        if ($member !== null) {
            return;
        }

        throw new AccessDeniedHttpException('Only project owner or admin can perform this action');
    }

    private function isOwnerOrAcceptedMember(User $user, IndustryProject $project): bool
    {
        if ($project->getOwner() === $user) {
            return true;
        }

        return $this->memberRepository->findOneBy([
            'project' => $project,
            'user' => $user,
            'status' => ParticipantStatus::Accepted,
        ]) !== null;
    }
}
