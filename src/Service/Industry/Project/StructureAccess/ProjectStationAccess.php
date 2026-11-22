<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\StructureAccess;

use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\StructureAccessStatus;

final readonly class ProjectStationAccess
{
    public function __construct(
        private PlanStations $planStations,
        private MemberStructureAccess $memberAccess,
    ) {
    }

    /**
     * @return list<StationAccessCount>
     */
    public function of(IndustryProject $project): array
    {
        $members = $project->acceptedUsers();

        $counts = [];
        foreach ($this->planStations->of($project) as $station) {
            $locationId = $station->locationId ?? throw new \LogicException('A plan station always has a location.');
            $statuses = array_map(
                fn (User $member): StructureAccessStatus => $this->memberAccess->of($member, $locationId),
                $members,
            );
            $counts[] = new StationAccessCount(
                $station,
                $this->countOf($statuses, StructureAccessStatus::Granted),
                $this->countOf($statuses, StructureAccessStatus::Denied),
                $this->countOf($statuses, StructureAccessStatus::Unknown),
            );
        }

        return $counts;
    }

    /**
     * @param list<StructureAccessStatus> $statuses
     */
    private function countOf(array $statuses, StructureAccessStatus $status): int
    {
        return \count(array_filter($statuses, static fn (StructureAccessStatus $candidate): bool => $candidate === $status));
    }
}
