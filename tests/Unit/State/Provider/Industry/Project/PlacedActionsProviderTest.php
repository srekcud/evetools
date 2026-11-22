<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Industry\Project\PlacedActionResource;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\ESI\CharacterLocation;
use App\Service\ESI\CharacterLocationReader;
use App\Service\Industry\Project\Action\ActionPlace;
use App\Service\Industry\Project\Action\CharacterPositions;
use App\Service\Industry\Project\Action\PlacedAction;
use App\Service\Industry\Project\Action\PlacedActionCode;
use App\Service\Industry\Project\Action\PlacedActionSorter;
use App\Service\Industry\Project\Action\ProjectActionSource;
use App\Service\Industry\Project\Action\UserActionReader;
use App\State\Provider\Industry\Project\PlacedActionsProvider;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(PlacedActionsProvider::class)]
#[CoversClass(PlacedActionResource::class)]
#[Group('issue-1300')]
#[Group('issue-1336')]
final class PlacedActionsProviderTest extends TestCase
{
    use BuildsWorkshopProjects;

    private const int AZBEL_ID = 1_040_000_001_000;
    private const int PERIMETER_SYSTEM_ID = 30_000_144;
    private const int RAM_TYPE_ID = 11_478;
    private const string PROJECTS_URI = '/industry/place-actions';
    private const string PROJECT_URI = '/industry/projects/{projectId}/place-actions';

    private User $reader;
    private ?CharacterLocation $location = null;
    private IndustryProject $ishtarBatch;
    private IndustryProject $hecateBatch;
    private IndustryProjectRepository $projectRepository;
    private IndustryProjectParticipantRepository $memberRepository;
    private array $readProjectNames = [];
    private array $reservedByOthers = [];

    protected function setUp(): void
    {
        $this->reader = new User();
        $character = (new Character())->setEveCharacterId(2_112_130_001)->setName('Aria Solberg');
        $this->reader->addCharacter($character);
        $this->reader->setMainCharacter($character);
        $this->ishtarBatch = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $this->reader);
        $this->hecateBatch = $this->workshopProject('Hecate batch', ProjectStatus::InProgress, $this->reader);
        $this->projectRepository = $this->createStub(IndustryProjectRepository::class);
        $this->projectRepository->method('find')->willReturnCallback(
            fn (mixed $id): ?IndustryProject => match ((string) $id) {
                $this->ishtarBatch->getId()->toRfc4122() => $this->ishtarBatch,
                $this->hecateBatch->getId()->toRfc4122() => $this->hecateBatch,
                default => null,
            },
        );
        $this->memberRepository = $this->createStub(IndustryProjectParticipantRepository::class);
        $memberships = [
            $this->membershipOf($this->ishtarBatch, $this->reader, ParticipantRole::Owner, ParticipantStatus::Accepted),
            $this->membershipOf($this->hecateBatch, $this->reader, ParticipantRole::Owner, ParticipantStatus::Accepted),
        ];
        $this->memberRepository->method('findAcceptedWithProjectsByUser')->willReturn($memberships);
    }

    public function testPlacedActionsAreRefusedWithoutConnectedUser(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        $this->provider(null)->provide(new GetCollection(uriTemplate: self::PROJECTS_URI));
    }

    public function testProjectIdentifierThatIsNotAUuidOrMatchesNoProjectIsNotFound(): void
    {
        foreach (['abc', Uuid::v4()->toRfc4122()] as $projectId) {
            try {
                $this->provider($this->reader)->provide(new GetCollection(uriTemplate: self::PROJECT_URI), ['projectId' => $projectId]);
                self::fail('The project '.$projectId.' must not be found');
            } catch (NotFoundHttpException $notFound) {
                self::assertSame('Project not found', $notFound->getMessage());
            }
        }
    }

    public function testPilotWhoIsNotAnAcceptedMemberIsRefusedTheActionsOfTheProject(): void
    {
        $outsider = new User();
        $this->expectException(AccessDeniedHttpException::class);

        $this->provider($outsider)->provide(new GetCollection(uriTemplate: self::PROJECT_URI), ['projectId' => $this->ishtarBatch->getId()->toRfc4122()]);
    }

    public function testEveryActionOfEveryProjectBecomesAResourceWithItsPlaceAsAnArrayAndItsPosition(): void
    {
        $this->location = new CharacterLocation(self::PERIMETER_SYSTEM_ID, null, self::AZBEL_ID, true, null);

        $resources = $this->provider($this->reader)->provide(new GetCollection(uriTemplate: self::PROJECTS_URI));

        $ishtarId = $this->ishtarBatch->getId()->toRfc4122();
        $hecateId = $this->hecateBatch->getId()->toRfc4122();
        self::assertSame(
            [
                [
                    'id' => $hecateId.':launch_job:line-ram',
                    'projectId' => $hecateId,
                    'projectName' => 'Hecate batch',
                    'code' => 'launch_job',
                    'bomLineId' => 'line-ram',
                    'typeId' => self::RAM_TYPE_ID,
                    'typeName' => 'R.A.M.- Starship Tech',
                    'quantity' => 5,
                    'place' => ['kind' => 'station', 'locationId' => self::AZBEL_ID, 'name' => 'Azbel', 'solarSystemId' => self::PERIMETER_SYSTEM_ID, 'solarSystemName' => 'Perimeter', 'unknownReason' => null],
                    'here' => true,
                ],
                [
                    'id' => $ishtarId.':review_join_requests:-',
                    'projectId' => $ishtarId,
                    'projectName' => 'Ishtar batch',
                    'code' => 'review_join_requests',
                    'bomLineId' => null,
                    'typeId' => null,
                    'typeName' => null,
                    'quantity' => 2,
                    'place' => ['kind' => 'anywhere', 'locationId' => null, 'name' => null, 'solarSystemId' => null, 'solarSystemName' => null, 'unknownReason' => null],
                    'here' => null,
                ],
            ],
            array_map($this->describe(...), $resources),
        );
    }

    public function testWithoutReadablePositionTheResourcesKeepAnUnknownPositionInsteadOfFalse(): void
    {
        $resources = $this->provider($this->reader)->provide(new GetCollection(uriTemplate: self::PROJECTS_URI));

        self::assertCount(2, $resources);
        foreach ($resources as $resource) {
            self::assertNull($resource->here);
        }
    }

    public function testOneProjectIsReadAloneAfterTheAccessCheck(): void
    {
        $resources = $this->provider($this->reader)->provide(new GetCollection(uriTemplate: self::PROJECT_URI), ['projectId' => $this->ishtarBatch->getId()->toRfc4122()]);

        self::assertSame(['Ishtar batch'], $this->readProjectNames);
        self::assertSame(['review_join_requests'], array_map(static fn (PlacedActionResource $resource): string => $resource->code, $resources));
        self::assertSame($this->ishtarBatch->getId()->toRfc4122(), $resources[0]->projectId);
    }

    public function testReservedByOthersOfTheActionIsCopiedOnTheResource(): void
    {
        $this->reservedByOthers = [['characterName' => 'Mira Kessel', 'quantity' => 3], ['characterName' => null, 'quantity' => 2]];

        $resources = $this->provider($this->reader)->provide(new GetCollection(uriTemplate: self::PROJECTS_URI));

        self::assertSame([['characterName' => 'Mira Kessel', 'quantity' => 3], ['characterName' => null, 'quantity' => 2]], $resources[0]->reservedByOthers);
        self::assertSame('launch_job', $resources[0]->code);
        self::assertSame([], $resources[1]->reservedByOthers);
    }

    private function provider(?User $user): PlacedActionsProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $source = new class(fn (IndustryProject $project): array => $this->actionsOf($project)) implements ProjectActionSource {
            public function __construct(private readonly \Closure $emit)
            {
            }

            public function actionsOf(IndustryProject $project, User $user): array
            {
                return ($this->emit)($project);
            }
        };
        $locationReader = $this->createStub(CharacterLocationReader::class);
        $locationReader->method('read')->willReturnCallback(fn (Character $character): ?CharacterLocation => $this->location);
        $reader = new UserActionReader($this->memberRepository, [$source], new CharacterPositions($locationReader), new PlacedActionSorter());

        return new PlacedActionsProvider($security, $this->projectRepository, new ProjectAccessChecker($this->memberRepository), $reader);
    }

    private function actionsOf(IndustryProject $project): array
    {
        $this->readProjectNames[] = $project->getName();
        $projectId = $project->getId()->toRfc4122();
        if ($project === $this->hecateBatch) {
            return [new PlacedAction($projectId, 'Hecate batch', PlacedActionCode::LaunchJob, 'line-ram', self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 5, ActionPlace::station(self::AZBEL_ID, 'Azbel', self::PERIMETER_SYSTEM_ID, 'Perimeter'), null, $this->reservedByOthers)];
        }

        return [new PlacedAction($projectId, 'Ishtar batch', PlacedActionCode::ReviewJoinRequests, null, null, null, 2, ActionPlace::anywhere())];
    }

    private function describe(PlacedActionResource $resource): array
    {
        return [
            'id' => $resource->id,
            'projectId' => $resource->projectId,
            'projectName' => $resource->projectName,
            'code' => $resource->code,
            'bomLineId' => $resource->bomLineId,
            'typeId' => $resource->typeId,
            'typeName' => $resource->typeName,
            'quantity' => $resource->quantity,
            'place' => $resource->place,
            'here' => $resource->here,
        ];
    }
}
