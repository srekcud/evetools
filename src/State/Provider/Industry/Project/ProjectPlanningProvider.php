<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectPlanningResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\PlanningBlueprintNames;
use App\Service\Industry\Project\PlanningCharacterLane;
use App\Service\Industry\Project\PlanningJob;
use App\Service\Industry\Project\PlanningUnscheduled;
use App\Service\Industry\Project\PlanningWait;
use App\Service\Industry\Project\ProjectPlanningBuilder;
use App\Service\Industry\Project\ProjectPlanningInputLoader;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectPlanningResource>
 */
final readonly class ProjectPlanningProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private ProjectPlanningInputLoader $inputLoader,
        private ProjectPlanningBuilder $planningBuilder,
        private PlanningBlueprintNames $blueprintNames,
        private ClockInterface $clock,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectPlanningResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $projectId = $uriVariables['projectId'] ?? null;
        if (null === $projectId) {
            throw new NotFoundHttpException('Project not found');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($projectId, 'Project not found'));
        if (null === $project) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        $input = $this->inputLoader->load($project);
        $planning = $this->planningBuilder->build($input, $this->clock->now());
        $blueprintNameByLineId = $this->blueprintNames->byLineId($input->lines);

        $resource = new ProjectPlanningResource();
        $resource->id = $projectId;
        $resource->anchor = $planning->anchor->format(\DATE_ATOM);
        $resource->structureTimeBonusIncluded = $planning->structureTimeBonusIncluded;
        $resource->plannedEnd = $planning->plannedEnd?->format(\DATE_ATOM);
        $resource->expectedEnd = $planning->expectedEnd?->format(\DATE_ATOM);
        $resource->delaySeconds = $planning->delaySeconds;
        $resource->delayCause = null === $planning->delayCause ? null : $this->waitOf($planning->delayCause);
        $resource->lanesShown = $project->isSoloProjectOf($project->getOwner());
        $resource->characters = $resource->lanesShown ? array_map(fn (PlanningCharacterLane $lane): array => $this->laneOf($lane, $blueprintNameByLineId), $planning->characters) : [];
        $resource->unscheduled = array_map(
            static fn (PlanningUnscheduled $line): array => [
                'lineId' => $line->lineId,
                'typeName' => $line->typeName,
                'blueprintName' => $blueprintNameByLineId[$line->lineId] ?? null,
                'reason' => $line->reason,
            ],
            $planning->unscheduled,
        );

        return $resource;
    }

    /**
     * @param array<string, string> $blueprintNameByLineId
     *
     * @return array<string, mixed>
     */
    private function laneOf(PlanningCharacterLane $lane, array $blueprintNameByLineId): array
    {
        return [
            'characterName' => $lane->characterName,
            'skillsUnknown' => $lane->skillsUnknown,
            'slots' => $lane->slots,
            'skills' => $lane->skills,
            'endsAt' => $lane->endsAt?->format(\DATE_ATOM),
            'jobs' => array_map(fn (PlanningJob $job): array => $this->jobOf($job, $blueprintNameByLineId[$job->lineId] ?? null), $lane->jobs),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jobOf(PlanningJob $job, ?string $blueprintName): array
    {
        return [
            'lineId' => $job->lineId,
            'typeId' => $job->typeId,
            'typeName' => $job->typeName,
            'blueprintName' => $blueprintName,
            'activity' => $job->activity,
            'runs' => $job->runs,
            'planned' => null === $job->planned ? null : [
                'start' => $job->planned->start->format(\DATE_ATOM),
                'end' => $job->planned->end->format(\DATE_ATOM),
            ],
            'real' => null === $job->real ? null : [
                'start' => $job->real->start->format(\DATE_ATOM),
                'end' => $job->real->end->format(\DATE_ATOM),
                'status' => $job->real->status,
            ],
            'wait' => null === $job->wait ? null : $this->waitOf($job->wait),
        ];
    }

    /**
     * @return array{kind: string, names: list<string>, lateSeconds: int}
     */
    private function waitOf(PlanningWait $wait): array
    {
        return ['kind' => $wait->kind, 'names' => $wait->names, 'lateSeconds' => $wait->lateSeconds];
    }
}
