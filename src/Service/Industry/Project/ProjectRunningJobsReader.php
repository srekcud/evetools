<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\User;

final class ProjectRunningJobsReader
{
    /**
     * @return list<array<string, mixed>>
     */
    public function of(IndustryProject $project, User $viewer): array
    {
        $activeLinks = array_values(array_filter(
            ProjectJobGroup::linksOf($project),
            static fn (IndustryProjectJobMatch $link): bool => CachedIndustryJob::STATUS_ACTIVE === $link->getStatus(),
        ));

        $groups = ProjectJobGroup::groupsOf($activeLinks);
        usort($groups, static fn (ProjectJobGroup $a, ProjectJobGroup $b): int => self::endTimestampOf($a) <=> self::endTimestampOf($b));

        return array_map(
            static fn (ProjectJobGroup $group): array => [
                'characterName' => JobInstallerName::seenBy($group->installerName(), $project, $viewer),
                'typeId' => $group->typeId(),
                'typeName' => $group->typeName(),
                'jobCount' => $group->jobCount(),
                'runs' => $group->runs(),
                'startedAt' => $group->startedAt()?->format(\DateTimeInterface::ATOM),
                'endsAt' => $group->endsAt()?->format(\DateTimeInterface::ATOM),
                'cost' => $group->cost(),
            ],
            $groups,
        );
    }

    private static function endTimestampOf(ProjectJobGroup $group): int
    {
        return $group->endsAt()?->getTimestamp() ?? \PHP_INT_MAX;
    }
}
