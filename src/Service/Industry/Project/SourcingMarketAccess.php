<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectParticipantRepository;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class SourcingMarketAccess
{
    public function __construct(private IndustryProjectParticipantRepository $participantRepository)
    {
    }

    public function assertMayChoose(User $user, IndustryProject $project): void
    {
        if ($project->getOwner() === $user) {
            return;
        }

        $admin = $this->participantRepository->findOneBy([
            'project' => $project,
            'user' => $user,
            'status' => ParticipantStatus::Accepted,
            'role' => ParticipantRole::Admin,
        ]);
        if (null === $admin) {
            throw new AccessDeniedHttpException('Only the project owner or an admin can choose the market of a line');
        }
    }
}
