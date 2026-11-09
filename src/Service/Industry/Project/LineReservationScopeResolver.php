<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\User;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final readonly class LineReservationScopeResolver
{
    private const string NO_ACCESS_MESSAGE = 'You do not have access to this project';

    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private IndustryProjectBomLineRepository $bomLineRepository,
        private IndustryProjectParticipantRepository $participantRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    public function resolve(array $uriVariables): LineReservationScope
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['projectId'] ?? '', 'Project not found'));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $participant = $this->participantRepository->findOneBy([
            'project' => $project,
            'user' => $user,
            'status' => ParticipantStatus::Accepted,
        ]);
        if ($participant === null) {
            throw new AccessDeniedHttpException(self::NO_ACCESS_MESSAGE);
        }

        $line = $this->bomLineRepository->find(UuidOrNotFound::of($uriVariables['lineId'] ?? '', 'BOM item not found'));
        if ($line === null || $line->getProject() !== $project) {
            throw new NotFoundHttpException('BOM item not found');
        }

        return new LineReservationScope($project, $line, $participant);
    }
}
