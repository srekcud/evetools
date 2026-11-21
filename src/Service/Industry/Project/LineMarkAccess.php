<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectLineReservationRepository;
use App\Repository\IndustryProjectParticipantRepository;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class LineMarkAccess
{
    private const string NOT_ALLOWED_MESSAGE = 'Only the project owner, an admin or the member who reserved the line can mark it';

    public function __construct(
        private IndustryProjectParticipantRepository $participantRepository,
        private IndustryProjectLineReservationRepository $reservationRepository,
    ) {
    }

    public function assertMayMark(User $user, IndustryProject $project, IndustryProjectBomLine $line): void
    {
        if ($project->getOwner() === $user) {
            return;
        }

        $participant = $this->participantRepository->findOneBy([
            'project' => $project,
            'user' => $user,
            'status' => ParticipantStatus::Accepted,
        ]);
        if (null !== $participant && (ParticipantRole::Admin === $participant->getRole() || null !== $this->reservationRepository->findOneByLineAndParticipant($line, $participant))) {
            return;
        }

        throw new AccessDeniedHttpException(self::NOT_ALLOWED_MESSAGE);
    }
}
