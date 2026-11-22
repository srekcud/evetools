<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\UnassignedJob;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectBomLineRepository;
use App\State\Provider\Industry\Project\ProjectAccessChecker;

class UnassignedJobCandidates
{
    private const array CLOSED_STATUSES = [ProjectStatus::Completed, ProjectStatus::Draft];

    public function __construct(
        private readonly IndustryProjectBomLineRepository $bomLineRepository,
        private readonly ProjectAccessChecker $accessChecker,
    ) {
    }

    /**
     * @return list<AssignableCandidate> the stored candidates still valid for the user, in stored order
     */
    public function assignableTo(User $user, UnassignedJob $unassignedJob): array
    {
        $assignable = [];
        foreach ($unassignedJob->getCandidates() as $stored) {
            $line = $this->bomLineRepository->find($stored['lineId']);
            if ($line === null || !$line->isManufacturingOrReactionJob()) {
                continue;
            }

            $project = $line->getProject();
            if (\in_array($project->getStatus(), self::CLOSED_STATUSES, true)
                || !\in_array($user, $this->accessChecker->acceptedParticipantsOf($project), true)) {
                continue;
            }

            $runs = $line->getRuns() ?? throw new \LogicException(\sprintf('Job line %s has no runs.', $line->getId()->toRfc4122()));
            $assignable[] = new AssignableCandidate(
                $project,
                $line,
                max(0, $runs - $line->getMatchedRuns()),
                $stored['score'],
                $stored['signals'],
                $stored['oversized'],
            );
        }

        return $assignable;
    }
}
