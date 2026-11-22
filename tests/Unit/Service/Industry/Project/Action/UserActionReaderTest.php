<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\Action;

use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectParticipantRepository;
use App\Service\ESI\CharacterLocation;
use App\Service\ESI\CharacterLocationReader;
use App\Service\Industry\Project\Action\ActionPlace;
use App\Service\Industry\Project\Action\CharacterPositions;
use App\Service\Industry\Project\Action\LocatedAction;
use App\Service\Industry\Project\Action\PlacedAction;
use App\Service\Industry\Project\Action\PlacedActionCode;
use App\Service\Industry\Project\Action\PlacedActionSorter;
use App\Service\Industry\Project\Action\ProjectActionSource;
use App\Service\Industry\Project\Action\UserActionReader;
use App\Tests\Unit\State\Provider\Industry\Project\BuildsWorkshopProjects;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserActionReader::class)]
#[Group('issue-1300')]
final class UserActionReaderTest extends TestCase
{
    use BuildsWorkshopProjects;

    private const int PERIMETER_SYSTEM_ID = 30_000_144;
    private const int AZBEL_ID = 1_040_000_001_000;
    private const int JITA_STATION_ID = 60_003_760;
    private const int MAIN_EVE_CHARACTER_ID = 2_112_130_001;

    private User $reader;
    private IndustryProject $ishtarBatch;
    private IndustryProject $hecateBatch;
    private ?CharacterLocation $mainLocation = null;

    protected function setUp(): void
    {
        $this->reader = new User();
        $character = (new Character())->setEveCharacterId(self::MAIN_EVE_CHARACTER_ID)->setName('Aria Solberg');
        $this->reader->addCharacter($character);
        $this->reader->setMainCharacter($character);
        $this->ishtarBatch = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $this->reader);
        $this->hecateBatch = $this->workshopProject('Hecate batch', ProjectStatus::InProgress, $this->reader);
    }

    public function testActionsOfEverySourceOnEveryAcceptedProjectAreSortedByPlaceWithTheDockedPlaceFirst(): void
    {
        $this->mainLocation = new CharacterLocation(self::PERIMETER_SYSTEM_ID, null, self::AZBEL_ID, true, null);
        $buy = $this->sourceEmitting(fn (IndustryProject $project): array => 'Ishtar batch' === $project->getName() ? [$this->action($project, PlacedActionCode::SourceMaterial, $this->jita(), 'Tritanium')] : []);
        $launch = $this->sourceEmitting(fn (IndustryProject $project): array => 'Hecate batch' === $project->getName() ? [$this->action($project, PlacedActionCode::LaunchJob, $this->azbel(), 'Hecate')] : []);
        $review = $this->sourceEmitting(fn (IndustryProject $project): array => 'Ishtar batch' === $project->getName() ? [$this->action($project, PlacedActionCode::ReviewJoinRequests, ActionPlace::anywhere(), null)] : []);

        $located = $this->actionReader([$this->ishtarBatch, $this->hecateBatch], [$buy, $launch, $review])->ofUser($this->reader);

        self::assertSame(
            [['Hecate batch', 'launch_job', true], ['Ishtar batch', 'source_material', false], ['Ishtar batch', 'review_join_requests', null]],
            $this->summary($located),
        );
    }

    public function testWithoutReadablePositionNoActionIsHere(): void
    {
        $source = $this->sourceEmitting(fn (IndustryProject $project): array => [$this->action($project, PlacedActionCode::SourceMaterial, $this->jita(), 'Tritanium')]);

        $located = $this->actionReader([$this->ishtarBatch, $this->hecateBatch], [$source])->ofUser($this->reader);

        self::assertSame([['Hecate batch', 'source_material', null], ['Ishtar batch', 'source_material', null]], $this->summary($located));
    }

    public function testSourcesAreAskedForEveryAcceptedProjectOnBehalfOfTheReader(): void
    {
        $calls = [];
        $source = $this->sourceEmitting(function (IndustryProject $project, User $user) use (&$calls): array {
            $calls[] = [$project->getName(), $user];

            return [];
        });

        $this->actionReader([$this->ishtarBatch, $this->hecateBatch], [$source])->ofUser($this->reader);

        self::assertSame([['Ishtar batch', $this->reader], ['Hecate batch', $this->reader]], $calls);
    }

    public function testUserWithoutProjectHasNoAction(): void
    {
        $source = $this->sourceEmitting(fn (IndustryProject $project): array => [$this->action($project, PlacedActionCode::SourceMaterial, $this->jita(), 'Tritanium')]);

        self::assertSame([], $this->actionReader([], [$source])->ofUser($this->reader));
    }

    public function testOneProjectIsReadWithoutLookingForTheOtherProjectsOfTheUser(): void
    {
        $this->mainLocation = new CharacterLocation(self::PERIMETER_SYSTEM_ID, null, self::AZBEL_ID, true, null);
        $calls = [];
        $source = $this->sourceEmitting(function (IndustryProject $project, User $user) use (&$calls): array {
            $calls[] = [$project->getName(), $user];

            return [$this->action($project, PlacedActionCode::LaunchJob, $this->azbel(), 'Hecate')];
        });
        $memberRepository = $this->createMock(IndustryProjectParticipantRepository::class);
        $memberRepository->expects(self::never())->method('findAcceptedWithProjectsByUser');

        $located = (new UserActionReader($memberRepository, [$source], new CharacterPositions($this->locationReader()), new PlacedActionSorter()))
            ->ofProject($this->reader, $this->hecateBatch);

        self::assertSame([['Hecate batch', $this->reader]], $calls);
        self::assertSame([['Hecate batch', 'launch_job', true]], $this->summary($located));
    }

    public function testSourceFailureIsNotSwallowedWhenReadingEveryProject(): void
    {
        $failing = $this->sourceEmitting(static fn (): array => throw new \RuntimeException('Container stock unreadable'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Container stock unreadable');

        $this->actionReader([$this->ishtarBatch], [$failing])->ofUser($this->reader);
    }

    public function testSourceFailureIsNotSwallowedWhenReadingOneProject(): void
    {
        $failing = $this->sourceEmitting(static fn (): array => throw new \RuntimeException('Container stock unreadable'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Container stock unreadable');

        $this->actionReader([], [$failing])->ofProject($this->reader, $this->ishtarBatch);
    }

    private function actionReader(array $projects, array $sources): UserActionReader
    {
        $memberRepository = $this->createStub(IndustryProjectParticipantRepository::class);
        $memberRepository->method('findAcceptedWithProjectsByUser')->willReturn(
            array_map(fn (IndustryProject $project) => $this->membershipOf($project, $this->reader, ParticipantRole::Owner, ParticipantStatus::Accepted), $projects),
        );

        return new UserActionReader($memberRepository, $sources, new CharacterPositions($this->locationReader()), new PlacedActionSorter());
    }

    private function locationReader(): CharacterLocationReader
    {
        $locationReader = $this->createStub(CharacterLocationReader::class);
        $locationReader->method('read')->willReturnCallback(fn (Character $character): ?CharacterLocation => $this->mainLocation);

        return $locationReader;
    }

    private function sourceEmitting(\Closure $emit): ProjectActionSource
    {
        return new class($emit) implements ProjectActionSource {
            public function __construct(private readonly \Closure $emit)
            {
            }

            public function actionsOf(IndustryProject $project, User $user): array
            {
                return ($this->emit)($project, $user);
            }
        };
    }

    private function action(IndustryProject $project, PlacedActionCode $code, ActionPlace $place, ?string $typeName): PlacedAction
    {
        return new PlacedAction($project->getId()->toRfc4122(), $project->getName(), $code, null === $typeName ? null : 'line-'.$typeName, null, $typeName, null, $place);
    }

    private function jita(): ActionPlace
    {
        return ActionPlace::station(self::JITA_STATION_ID, 'Jita IV - Moon 4 - Caldari Navy Assembly Plant', 30_000_142, 'Jita');
    }

    private function azbel(): ActionPlace
    {
        return ActionPlace::station(self::AZBEL_ID, 'Azbel', self::PERIMETER_SYSTEM_ID, 'Perimeter');
    }

    private function summary(array $located): array
    {
        return array_map(
            static fn (LocatedAction $entry): array => [$entry->action->projectName, $entry->action->code->value, $entry->here],
            $located,
        );
    }
}
