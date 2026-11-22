<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\Action;

use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\User;
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
use App\Tests\Unit\State\Provider\Industry\Project\BuildsWorkshopProjects;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserActionReader::class)]
#[Group('issue-1306')]
final class UserActionReaderCountsTest extends TestCase
{
    use BuildsWorkshopProjects;

    private User $owner;
    private User $member;
    private User $idleMember;
    private IndustryProject $ishtarBatch;

    protected function setUp(): void
    {
        $this->owner = $this->pilotNamed('Aria Solberg', 2_112_130_001);
        $this->member = $this->pilotNamed('Brann Holt', 2_112_130_002);
        $this->idleMember = $this->pilotNamed('Corin Vale', 2_112_130_003);
        $this->ishtarBatch = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $this->owner);
    }

    public function testEachUserGetsTheNumberOfActionsEverySourceGivesThemOnTheProject(): void
    {
        $launch = $this->sourceEmitting(fn (IndustryProject $project, User $user): array => $user === $this->owner ? [$this->action($project, PlacedActionCode::LaunchJob), $this->action($project, PlacedActionCode::LaunchJob)] : []);
        $review = $this->sourceEmitting(fn (IndustryProject $project, User $user): array => match ($user) {
            $this->owner => [$this->action($project, PlacedActionCode::ReviewJoinRequests)],
            $this->member => [$this->action($project, PlacedActionCode::SourceMaterial)],
            default => [],
        });

        $counts = $this->reader([$launch, $review])->countsOfProject($this->ishtarBatch, [$this->member, $this->owner, $this->idleMember]);

        self::assertSame([1, 3, 0], $counts);
    }

    public function testEverySourceIsAskedForEveryGivenUserOnThatProjectOnly(): void
    {
        $calls = [];
        $source = $this->sourceEmitting(function (IndustryProject $project, User $user) use (&$calls): array {
            $calls[] = [$project->getName(), $user];

            return [];
        });

        $this->reader([$source])->countsOfProject($this->ishtarBatch, [$this->owner, $this->member]);

        self::assertSame([['Ishtar batch', $this->owner], ['Ishtar batch', $this->member]], $calls);
    }

    public function testNoUserGivesNoCount(): void
    {
        self::assertSame([], $this->reader([$this->sourceEmitting(fn (): array => [])])->countsOfProject($this->ishtarBatch, []));
    }

    public function testSourceFailureIsNotSwallowed(): void
    {
        $failing = $this->sourceEmitting(static fn (): array => throw new \RuntimeException('Container stock unreadable'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Container stock unreadable');

        $this->reader([$failing])->countsOfProject($this->ishtarBatch, [$this->owner]);
    }

    private function pilotNamed(string $name, int $eveCharacterId): User
    {
        $user = new User();
        $character = (new Character())->setEveCharacterId($eveCharacterId)->setName($name);
        $user->addCharacter($character);
        $user->setMainCharacter($character);

        return $user;
    }

    /**
     * @param list<ProjectActionSource> $sources
     */
    private function reader(array $sources): UserActionReader
    {
        return new UserActionReader(
            $this->createStub(IndustryProjectParticipantRepository::class),
            $sources,
            new CharacterPositions($this->createStub(CharacterLocationReader::class)),
            new PlacedActionSorter(),
        );
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

    private function action(IndustryProject $project, PlacedActionCode $code): PlacedAction
    {
        return new PlacedAction($project->getId()->toRfc4122(), $project->getName(), $code, null, null, null, null, ActionPlace::anywhere());
    }
}
