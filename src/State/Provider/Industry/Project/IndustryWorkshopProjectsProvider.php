<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\IndustryWorkshopProjectResource;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Repository\IndustryProjectParticipantRepository;
use App\Service\Industry\Project\CockpitPhasePresenter;
use App\Service\Industry\Project\PilotName;
use App\Service\Industry\Project\ProjectCockpitCalculator;
use App\Service\Industry\Project\ProjectDistributionService;
use App\Service\Industry\Project\ViewerEngagedCapital;
use App\Service\Industry\Project\ViewerProfitShare;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<IndustryWorkshopProjectResource>
 */
class IndustryWorkshopProjectsProvider implements ProviderInterface
{
    private const array ROLE_RANK = [
        ParticipantRole::Owner->value => 0,
        ParticipantRole::Admin->value => 1,
        ParticipantRole::Member->value => 2,
    ];

    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectParticipantRepository $memberRepository,
        private readonly ProjectCockpitInputFactory $inputFactory,
        private readonly ProjectCockpitCalculator $calculator,
        private readonly ProjectDistributionService $distributionService,
    ) {
    }

    /** @return list<IndustryWorkshopProjectResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $memberships = $this->memberRepository->findAcceptedWithProjectsByUser($user);
        $projects = array_map(static fn (IndustryProjectParticipant $membership): IndustryProject => $membership->getProject(), $memberships);
        $participantsByProject = $this->memberRepository->findAcceptedGroupedByProject($projects);

        $resources = [];
        foreach ($memberships as $membership) {
            $project = $membership->getProject();
            $resources[] = $this->toResource(
                $project,
                $membership,
                $participantsByProject[$project->getId()->toRfc4122()] ?? [],
                $user,
            );
        }

        return $resources;
    }

    /** @param list<IndustryProjectParticipant> $participants */
    private function toResource(IndustryProject $project, IndustryProjectParticipant $membership, array $participants, User $user): IndustryWorkshopProjectResource
    {
        try {
            $cockpit = $this->calculator->calculate($this->inputFactory->create($project, $user));
        } catch (\UnexpectedValueException $failure) {
            throw new \UnexpectedValueException(
                sprintf('Phases of project %s cannot be computed: %s', $project->getId()->toRfc4122(), $failure->getMessage()),
                previous: $failure,
            );
        }

        $resource = new IndustryWorkshopProjectResource();
        $resource->projectId = $project->getId()->toRfc4122();
        $resource->members = $this->membersOf($participants);
        $resource->phases = CockpitPhasePresenter::of($cockpit->phases);
        $distribution = $this->distributionService->calculateDistribution($project);
        $memberId = $membership->getId()->toRfc4122();
        $resource->profit = ViewerProfitShare::of($distribution, $memberId);
        $resource->engagedCapital = ViewerEngagedCapital::of($distribution, $memberId);

        return $resource;
    }

    /**
     * @param list<IndustryProjectParticipant> $participants
     *
     * @return list<array{characterId: int|null, characterName: string, role: string}>
     */
    private function membersOf(array $participants): array
    {
        usort($participants, static fn (IndustryProjectParticipant $left, IndustryProjectParticipant $right): int => [
            self::ROLE_RANK[$left->getRole()->value],
            $left->getJoinedAt(),
        ] <=> [
            self::ROLE_RANK[$right->getRole()->value],
            $right->getJoinedAt(),
        ]);

        return array_map(
            static fn (IndustryProjectParticipant $participant): array => [
                'characterId' => $participant->getUser()?->getMainCharacter()?->getEveCharacterId(),
                'characterName' => PilotName::of($participant->getUser())
                    ?? throw new \UnexpectedValueException(sprintf('Accepted participant %s has no user', $participant->getId()->toRfc4122())),
                'role' => $participant->getRole()->value,
            ],
            $participants,
        );
    }
}
