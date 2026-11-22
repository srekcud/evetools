<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Industry\Project\ProjectOverviewResource;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectParticipantRepository;
use App\Service\ESI\CharacterLocationReader;
use App\Service\Industry\Project\Action\ActionPlace;
use App\Service\Industry\Project\Action\CharacterPositions;
use App\Service\Industry\Project\Action\PlacedAction;
use App\Service\Industry\Project\Action\PlacedActionCode;
use App\Service\Industry\Project\Action\PlacedActionSorter;
use App\Service\Industry\Project\Action\ProjectActionSource;
use App\Service\Industry\Project\Action\UserActionReader;
use App\Service\Industry\Project\Overview\ProjectFlowCounter;
use App\Service\Industry\Project\StationQuantitiesSource;
use App\Service\Industry\Project\StationQuantity;
use App\Service\Industry\Project\TypeStationQuantities;
use App\State\Provider\Industry\Project\ProjectOverviewCollectionProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(ProjectOverviewCollectionProvider::class)]
#[CoversClass(ProjectOverviewResource::class)]
#[Group('issue-1306')]
final class ProjectOverviewCollectionProviderTest extends TestCase
{
    use BuildsWorkshopProjects;

    private const string URI = '/industry/projects/overview';
    private const int AZBEL_ID = 1_040_000_001_000;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int ISHTAR_TYPE_ID = 12_005;

    private User $viewer;
    private User $member;
    private User $idleMember;
    private User $pendingMember;
    private IndustryProject $ishtarBatch;
    private array $quantitiesReadFor = [];
    private ?array $quantities = null;
    private array $actionCounts = [];

    protected function setUp(): void
    {
        $this->viewer = $this->pilotNamed('Aria Solberg', 2_112_130_001);
        $this->member = $this->pilotNamed('Brann Holt', 2_112_130_002);
        $this->idleMember = $this->pilotNamed('Corin Vale', 2_112_130_003);
        $this->pendingMember = $this->pilotNamed('Dorn Achar', 2_112_130_004);
        $this->ishtarBatch = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $this->viewer);
        $this->quantities = [
            new TypeStationQuantities(self::TRITANIUM_TYPE_ID, 'Tritanium', ['line-tritanium'], [$this->card(toBring: 500, onSite: 0), $this->card(toBring: 0, onSite: 200)], null),
        ];
    }

    public function testOverviewIsRefusedWithoutConnectedUser(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        $this->provider(null, [])->provide(new GetCollection(uriTemplate: self::URI));
    }

    public function testPilotWithoutProjectGetsAnEmptyOverview(): void
    {
        self::assertSame([], $this->provider($this->viewer, [])->provide(new GetCollection(uriTemplate: self::URI)));
    }

    public function testRoleOwnerAndTeamOfAProjectOwnedByAnotherPilot(): void
    {
        $this->membershipOf($this->ishtarBatch, $this->member, ParticipantRole::Admin, ParticipantStatus::Accepted);
        $this->membershipOf($this->ishtarBatch, $this->viewer, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->membershipOf($this->ishtarBatch, $this->idleMember, ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->membershipOf($this->ishtarBatch, $this->pendingMember, ParticipantRole::Member, ParticipantStatus::Pending);

        $overview = $this->provider($this->member, [$this->ishtarBatch])->provide(new GetCollection(uriTemplate: self::URI));

        self::assertCount(1, $overview);
        self::assertSame($this->ishtarBatch->getId()->toRfc4122(), $overview[0]->projectId);
        self::assertSame('admin', $overview[0]->myRole);
        self::assertSame('Aria Solberg', $overview[0]->ownerName);
        self::assertSame([['name' => 'Brann Holt', 'role' => 'admin'], ['name' => 'Corin Vale', 'role' => 'member']], $overview[0]->team);
    }

    public function testOwnerWithoutMainCharacterIsNamedUnknown(): void
    {
        $nameless = new User();
        $project = $this->workshopProject('Nameless batch', ProjectStatus::InProgress, $nameless);
        $this->membershipOf($project, $nameless, ParticipantRole::Owner, ParticipantStatus::Accepted);

        $overview = $this->provider($nameless, [$project])->provide(new GetCollection(uriTemplate: self::URI));

        self::assertSame('Unknown', $overview[0]->ownerName);
        self::assertSame('owner', $overview[0]->myRole);
    }

    public function testFlowOfAProjectInProgressComesFromItsStationQuantitiesAndItsSoldTypes(): void
    {
        $this->membershipOf($this->ishtarBatch, $this->viewer, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->saleOf($this->ishtarBatch, self::ISHTAR_TYPE_ID, 'Ishtar', 2);
        $this->saleOf($this->ishtarBatch, self::ISHTAR_TYPE_ID, 'Ishtar', 1);

        $overview = $this->provider($this->viewer, [$this->ishtarBatch])->provide(new GetCollection(uriTemplate: self::URI));

        self::assertSame(['toBring' => 1, 'onSite' => 1, 'inProduction' => 0, 'out' => 0, 'done' => 1], $overview[0]->flow);
        self::assertNull($overview[0]->flowReason);
        self::assertSame(0, $overview[0]->flowUnknown);
    }

    public function testFlowThatCannotBeReadKeepsItsReasonAndNoCount(): void
    {
        $this->membershipOf($this->ishtarBatch, $this->viewer, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->quantities = [new TypeStationQuantities(self::TRITANIUM_TYPE_ID, 'Tritanium', ['line-tritanium'], null, 'plan_unavailable')];

        $overview = $this->provider($this->viewer, [$this->ishtarBatch])->provide(new GetCollection(uriTemplate: self::URI));

        self::assertNull($overview[0]->flow);
        self::assertSame('plan_unavailable', $overview[0]->flowReason);
    }

    public function testQuantityThatCannotBeReadIsReportedAsUnknownNotAsZero(): void
    {
        $this->membershipOf($this->ishtarBatch, $this->viewer, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->quantities = [new TypeStationQuantities(self::TRITANIUM_TYPE_ID, 'Tritanium', ['line-tritanium'], [$this->card(toBring: 0, onSite: null)], null)];

        $overview = $this->provider($this->viewer, [$this->ishtarBatch])->provide(new GetCollection(uriTemplate: self::URI));

        self::assertSame(1, $overview[0]->flowUnknown);
        self::assertSame(0, $overview[0]->flow['onSite']);
    }

    public function testDraftAndCompletedProjectsAreNotReadAndSayWhy(): void
    {
        $draft = $this->workshopProject('Draft batch', ProjectStatus::Draft, $this->viewer);
        $completed = $this->workshopProject('Completed batch', ProjectStatus::Completed, $this->viewer);
        $this->membershipOf($draft, $this->viewer, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->membershipOf($completed, $this->viewer, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->actionCounts = ['Aria Solberg' => 4];

        $overview = $this->provider($this->viewer, [$draft, $completed])->provide(new GetCollection(uriTemplate: self::URI));

        self::assertSame([null, 'not_started'], [$overview[0]->flow, $overview[0]->flowReason]);
        self::assertSame([null, 'completed'], [$overview[1]->flow, $overview[1]->flowReason]);
        self::assertSame([], $this->quantitiesReadFor);
        self::assertSame([], $overview[1]->awaited);
    }

    public function testAwaitedParticipantsPutTheViewerFirstThenTheBusiestAndLeaveOutThoseWithNothingToDo(): void
    {
        $this->membershipOf($this->ishtarBatch, $this->viewer, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->membershipOf($this->ishtarBatch, $this->idleMember, ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->membershipOf($this->ishtarBatch, $this->member, ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->actionCounts = ['Aria Solberg' => 3, 'Brann Holt' => 5, 'Corin Vale' => 0];

        $overview = $this->provider($this->viewer, [$this->ishtarBatch])->provide(new GetCollection(uriTemplate: self::URI));

        self::assertSame(
            [['name' => 'Aria Solberg', 'mine' => true, 'count' => 3], ['name' => 'Brann Holt', 'mine' => false, 'count' => 5]],
            $overview[0]->awaited,
        );
    }

    public function testNobodyAwaitedGivesAnEmptyList(): void
    {
        $this->membershipOf($this->ishtarBatch, $this->viewer, ParticipantRole::Owner, ParticipantStatus::Accepted);

        $overview = $this->provider($this->viewer, [$this->ishtarBatch])->provide(new GetCollection(uriTemplate: self::URI));

        self::assertSame([], $overview[0]->awaited);
    }

    public function testStationQuantitiesFailureIsNotSwallowed(): void
    {
        $this->membershipOf($this->ishtarBatch, $this->viewer, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->quantities = null;

        $this->expectException(\RuntimeException::class);

        $this->provider($this->viewer, [$this->ishtarBatch])->provide(new GetCollection(uriTemplate: self::URI));
    }

    private function pilotNamed(string $name, int $eveCharacterId): User
    {
        $user = new User();
        $character = (new Character())->setEveCharacterId($eveCharacterId)->setName($name);
        $user->addCharacter($character);
        $user->setMainCharacter($character);

        return $user;
    }

    private function card(int $toBring, ?int $onSite): StationQuantity
    {
        return new StationQuantity(self::AZBEL_ID, 'Azbel', 700, 0, 0, $onSite, 0, $toBring, 0, null);
    }

    /**
     * @param list<IndustryProject> $projects
     */
    private function provider(?User $viewer, array $projects): ProjectOverviewCollectionProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($viewer);

        $memberRepository = $this->createStub(IndustryProjectParticipantRepository::class);
        $memberRepository->method('findAcceptedWithProjectsByUser')->willReturnCallback(
            function (User $user) use ($projects): array {
                $memberships = [];
                foreach ($projects as $project) {
                    foreach ($project->getMembers() as $membership) {
                        if ($membership->getUser() === $user && ParticipantStatus::Accepted === $membership->getStatus()) {
                            $memberships[] = $membership;
                        }
                    }
                }

                return $memberships;
            },
        );
        $memberRepository->method('findAcceptedGroupedByProject')->willReturnCallback(
            static function (array $requested): array {
                $grouped = [];
                foreach ($requested as $project) {
                    foreach ($project->getMembers() as $membership) {
                        if (ParticipantStatus::Accepted === $membership->getStatus()) {
                            $grouped[$project->getId()->toRfc4122()][] = $membership;
                        }
                    }
                }

                return $grouped;
            },
        );

        $quantitiesSource = new class($this) implements StationQuantitiesSource {
            public function __construct(private readonly ProjectOverviewCollectionProviderTest $test)
            {
            }

            public function of(IndustryProject $project): array
            {
                return $this->test->readQuantitiesOf($project);
            }
        };

        $source = new class($this) implements ProjectActionSource {
            public function __construct(private readonly ProjectOverviewCollectionProviderTest $test)
            {
            }

            public function actionsOf(IndustryProject $project, User $user): array
            {
                return $this->test->actionsOf($project, $user);
            }
        };

        return new ProjectOverviewCollectionProvider(
            $security,
            $memberRepository,
            $quantitiesSource,
            new ProjectFlowCounter(),
            new UserActionReader($memberRepository, [$source], new CharacterPositions($this->createStub(CharacterLocationReader::class)), new PlacedActionSorter()),
        );
    }

    /**
     * @return list<TypeStationQuantities>
     */
    public function readQuantitiesOf(IndustryProject $project): array
    {
        $this->quantitiesReadFor[] = $project->getName();

        return $this->quantities ?? throw new \RuntimeException('Station quantities unreadable');
    }

    /**
     * @return list<PlacedAction>
     */
    public function actionsOf(IndustryProject $project, User $user): array
    {
        $count = $this->actionCounts[$user->getMainCharacter()?->getName()] ?? 0;

        return array_map(
            static fn (int $index): PlacedAction => new PlacedAction($project->getId()->toRfc4122(), $project->getName(), PlacedActionCode::LaunchJob, 'line-'.$index, null, null, null, ActionPlace::anywhere()),
            $count > 0 ? range(1, $count) : [],
        );
    }
}
