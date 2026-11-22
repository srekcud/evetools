<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Action;

use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\ProjectStatus;

final readonly class ReviewJoinRequestActions implements ProjectActionSource
{
    public function actionsOf(IndustryProject $project, User $user): array
    {
        if (ProjectStatus::Completed === $project->getStatus() || !$project->isManagedBy($user)) {
            return [];
        }

        $pendingCount = $project->pendingJoinRequestCount();
        if ($pendingCount <= 0) {
            return [];
        }

        return [new PlacedAction(
            $project->getId()->toRfc4122(),
            $project->getName(),
            PlacedActionCode::ReviewJoinRequests,
            null,
            null,
            null,
            $pendingCount,
            ActionPlace::anywhere(),
        )];
    }
}
