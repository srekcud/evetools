<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectJobMatch;
use App\Enum\ProjectActivityKind;
use App\Repository\IndustryProjectActivityRepository;
use App\Service\Mercure\MercurePublisherService;

class ProjectJobActivityRecorder
{
    private const array FINISHED_STATUSES = [CachedIndustryJob::STATUS_DELIVERED, CachedIndustryJob::STATUS_READY];

    public function __construct(
        private readonly IndustryProjectActivityRepository $activityRepository,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function recordJobsOf(IndustryProject $project): void
    {
        $links = ProjectJobGroup::linksOf($project);
        if ([] === $links) {
            return;
        }

        $detectedJobIds = $this->activityRepository->recordedJobIdsOf($project, ProjectActivityKind::JobsDetected);
        $finishedJobIds = $this->activityRepository->recordedJobIdsOf($project, ProjectActivityKind::JobsFinished);

        $newLinks = array_values(array_filter(
            $links,
            static fn (IndustryProjectJobMatch $link): bool => !\in_array($link->getEsiJobId(), $detectedJobIds, true),
        ));
        foreach (ProjectJobGroup::groupsOf($newLinks) as $group) {
            $this->publish($project, ProjectActivityKind::JobsDetected, [
                'characterName' => $this->pilotNameOf($project, $group),
                'typeId' => $group->typeId(),
                'typeName' => $group->typeName(),
                'jobCount' => $group->jobCount(),
                'runs' => $group->runs(),
                'startedAt' => $group->startedAt()?->format(\DateTimeInterface::ATOM),
                'endsAt' => $group->endsAt()?->format(\DateTimeInterface::ATOM),
                'cost' => $group->cost(),
                'jobIds' => $group->jobIds(),
            ]);
        }

        $finishingLinks = array_values(array_filter(
            $links,
            static fn (IndustryProjectJobMatch $link): bool => \in_array($link->getStatus(), self::FINISHED_STATUSES, true)
                && !\in_array($link->getEsiJobId(), $finishedJobIds, true),
        ));
        foreach (ProjectJobGroup::groupsOf($finishingLinks) as $group) {
            $this->publish($project, ProjectActivityKind::JobsFinished, [
                'characterName' => $this->pilotNameOf($project, $group),
                'typeId' => $group->typeId(),
                'typeName' => $group->typeName(),
                'jobCount' => $group->jobCount(),
                'runs' => $group->runs(),
                'endedAt' => $group->endsAt()?->format(\DateTimeInterface::ATOM),
                'jobIds' => $group->jobIds(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function publish(IndustryProject $project, ProjectActivityKind $kind, array $data): void
    {
        $this->mercurePublisher->publishGroupProjectEvent($project->getId()->toRfc4122(), $kind->value, $data);
    }

    private function pilotNameOf(IndustryProject $project, ProjectJobGroup $group): ?string
    {
        return PilotName::of(ProjectJobInstaller::userOf($project, $group->installerName()));
    }
}
