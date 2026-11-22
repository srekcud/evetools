<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectOverviewResource;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectParticipantRepository;
use App\Service\Industry\Project\Action\UserActionReader;
use App\Service\Industry\Project\Overview\ProjectFlow;
use App\Service\Industry\Project\Overview\ProjectFlowCounter;
use App\Service\Industry\Project\PilotName;
use App\Service\Industry\Project\StationQuantitiesSource;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectOverviewResource>
 */
final readonly class ProjectOverviewCollectionProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectParticipantRepository $memberRepository,
        private StationQuantitiesSource $stationQuantities,
        private ProjectFlowCounter $flowCounter,
        private UserActionReader $actionReader,
    ) {
    }

    /**
     * @return list<ProjectOverviewResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $memberships = $this->memberRepository->findAcceptedWithProjectsByUser($user);
        $projects = array_values(array_map(
            static fn (IndustryProjectParticipant $membership): IndustryProject => $membership->getProject(),
            $memberships,
        ));
        $participantsByProject = $this->memberRepository->findAcceptedGroupedByProject($projects);

        $overviews = [];
        foreach ($memberships as $membership) {
            $project = $membership->getProject();
            $overviews[] = $this->overviewOf($project, $membership, $user, $participantsByProject[$project->getId()->toRfc4122()] ?? []);
        }

        return $overviews;
    }

    /**
     * @param list<IndustryProjectParticipant> $participants
     */
    private function overviewOf(IndustryProject $project, IndustryProjectParticipant $membership, User $viewer, array $participants): ProjectOverviewResource
    {
        $overview = new ProjectOverviewResource();
        $overview->projectId = $project->getId()->toRfc4122();
        $overview->myRole = $membership->getRole()->value;
        $overview->ownerName = PilotName::of($project->getOwner()) ?? throw new \LogicException('A project always has an owner.');
        $overview->team = array_values(array_map(
            static fn (IndustryProjectParticipant $participant): array => [
                'name' => PilotName::of($participant->getUser()) ?? throw new \LogicException('A participant always has a user.'),
                'role' => $participant->getRole()->value,
            ],
            array_filter($participants, static fn (IndustryProjectParticipant $participant): bool => ParticipantRole::Owner !== $participant->getRole()),
        ));

        $flow = $this->flowOf($project);
        $overview->flow = $flow->counts();
        $overview->flowReason = $flow->reason;
        $overview->flowUnknown = $flow->unknown;
        $overview->awaited = ProjectStatus::Completed === $project->getStatus() ? [] : $this->awaitedOf($project, $viewer, $participants);

        return $overview;
    }

    private function flowOf(IndustryProject $project): ProjectFlow
    {
        return match ($project->getStatus()) {
            ProjectStatus::Draft => ProjectFlow::unavailable(ProjectFlow::REASON_NOT_STARTED),
            ProjectStatus::Completed => ProjectFlow::unavailable(ProjectFlow::REASON_COMPLETED),
            default => $this->flowCounter->count($this->stationQuantities->of($project), $this->soldTypeCountOf($project)),
        };
    }

    private function soldTypeCountOf(IndustryProject $project): int
    {
        $typeIds = [];
        foreach ($project->getSales() as $sale) {
            if ($sale->getQuantity() > 0) {
                $typeIds[$sale->getTypeId()] = true;
            }
        }

        return \count($typeIds);
    }

    /**
     * @param list<IndustryProjectParticipant> $participants
     *
     * @return list<array{name: string, mine: bool, count: int}>
     */
    private function awaitedOf(IndustryProject $project, User $viewer, array $participants): array
    {
        $users = array_map(
            static fn (IndustryProjectParticipant $participant): User => $participant->getUser() ?? throw new \LogicException('A participant always has a user.'),
            $participants,
        );
        $counts = $this->actionReader->countsOfProject($project, $users);

        $awaited = [];
        foreach ($users as $index => $participantUser) {
            if ($counts[$index] > 0) {
                $awaited[] = [
                    'name' => PilotName::of($participantUser) ?? throw new \LogicException('A participant always has a user.'),
                    'mine' => $participantUser === $viewer,
                    'count' => $counts[$index],
                ];
            }
        }
        usort($awaited, static fn (array $left, array $right): int => [!$left['mine'], -$left['count'], $left['name']] <=> [!$right['mine'], -$right['count'], $right['name']]);

        return $awaited;
    }
}
