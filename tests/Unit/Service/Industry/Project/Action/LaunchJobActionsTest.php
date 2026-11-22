<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\Action;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Enum\StructureAccessSource;
use App\Enum\StructureAccessStatus;
use App\Repository\IndustryProjectLineReservationRepository;
use App\Service\Industry\Project\Action\ActionPlace;
use App\Service\Industry\Project\Action\LaunchJobActions;
use App\Service\Industry\Project\Action\PlacedAction;
use App\Service\Industry\Project\Action\PlacedActionCode;
use App\Service\Industry\Project\StructureAccess\MemberStructureAccess;
use App\Tests\Unit\Service\Industry\Project\StructureAccess\BuildsStructureAccess;
use App\Tests\Unit\State\Provider\Industry\Project\BuildsWorkshopProjects;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(LaunchJobActions::class)]
#[Group('issue-1300')]
#[Group('issue-1336')]
final class LaunchJobActionsTest extends TestCase
{
    use BuildsActionPlaces;
    use BuildsLineReservations;
    use BuildsStructureAccess;
    use BuildsWorkshopProjects;

    private const int COMPONENT_TYPE_ID = 11_540;
    private const int RAM_TYPE_ID = 11_478;
    private const int ISHTAR_TYPE_ID = 12_005;
    private const int TRITANIUM_TYPE_ID = 34;

    private ?IndustryProjectLineReservationRepository $reservations = null;

    public function testJobLineWithRunsStillToLaunchIsAnActionWithTheRemainingRunsAtTheStationOfItsStep(): void
    {
        $owner = new User();
        $azbel = $this->azbelConfig();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $owner);
        $component = $this->jobOf($project, self::COMPONENT_TYPE_ID, 'Composant X', 'component', 8, 8, 0)->setPlannedStructureId($azbel->getId()->toRfc4122());
        $this->matchedRunsOn($component, 3);
        $this->materialOf($project, self::TRITANIUM_TYPE_ID, 'Tritanium', 500, 0);

        $actions = $this->launchJobActions($owner, [$azbel])->actionsOf($project, $owner);

        self::assertEquals(
            [
                new PlacedAction(
                    $project->getId()->toRfc4122(),
                    'Ishtar batch',
                    PlacedActionCode::LaunchJob,
                    $component->getId()->toRfc4122(),
                    self::COMPONENT_TYPE_ID,
                    'Composant X',
                    5,
                    ActionPlace::station(self::AZBEL_LOCATION_ID, self::AZBEL_NAME, self::PERIMETER_SYSTEM_ID, self::PERIMETER_SYSTEM_NAME),
                    StructureAccessStatus::Unknown,
                ),
            ],
            $actions,
        );
    }

    public function testJobLineWithoutRunCountStaysAnActionWithAnUnknownQuantity(): void
    {
        $owner = new User();
        $azbel = $this->azbelConfig();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $owner);
        $this->jobOf($project, self::ISHTAR_TYPE_ID, 'Ishtar', 'final', null, 2, 0)->setPlannedStructureId($azbel->getId()->toRfc4122());

        $actions = $this->launchJobActions($owner, [$azbel])->actionsOf($project, $owner);

        self::assertSame([[PlacedActionCode::LaunchJob, self::ISHTAR_TYPE_ID, null]], $this->codesWithTypeIdsAndQuantities($actions));
    }

    public function testRunsCountedByActiveJobsAreNotToLaunchAgain(): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $owner);
        $this->matchedRunsOn($this->jobOf($project, self::COMPONENT_TYPE_ID, 'Composant X', 'component', 8, 8, 0), 2);
        $partlyLaunched = $this->jobOf($project, self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 'component', 9, 9, 0);
        $this->matchedRunsOn($partlyLaunched, 4);
        $this->matchedRunsOn($partlyLaunched, 3);
        $fullyLaunched = $this->jobOf($project, self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 6, 6, 0);
        $this->matchedRunsOn($fullyLaunched, 6);

        $actions = $this->launchJobActions($owner, [])->actionsOf($project, $owner);

        self::assertEqualsCanonicalizing(
            [[PlacedActionCode::LaunchJob, self::COMPONENT_TYPE_ID, 6], [PlacedActionCode::LaunchJob, self::RAM_TYPE_ID, 2]],
            $this->codesWithTypeIdsAndQuantities($actions),
        );
    }

    public function testJobLineWhoseRunsAreAllDeliveredIsNotToLaunchAgain(): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $owner);
        $line = $this->jobOf($project, self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 4, 4, 0);
        $line->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(612_336_001)->setRuns(4)->setStatus('delivered'));

        self::assertSame([], $this->launchJobActions($owner, [])->actionsOf($project, $owner));
    }

    public function testJobLineWithoutPlannedStructureStaysAnActionAtAnUnresolvedStation(): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $owner);
        $this->jobOf($project, self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 'component', 3, 3, 0);

        $actions = $this->launchJobActions($owner, [$this->azbelConfig()])->actionsOf($project, $owner);

        self::assertCount(1, $actions);
        self::assertEquals(ActionPlace::unknown(ActionPlace::UNKNOWN_STATION_UNRESOLVED), $actions[0]->place);
        self::assertNull($actions[0]->access);
    }

    public function testStepStationIsReadFromTheConfigurationsOfTheOwnerNotOfTheReader(): void
    {
        $owner = $this->pilot();
        $reader = new User();
        $azbel = $this->azbelConfig();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $owner);
        $this->jobOf($project, self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 'component', 3, 3, 0)->setPlannedStructureId($azbel->getId()->toRfc4122());

        $actions = $this->launchJobActions($owner, [$azbel])->actionsOf($project, $reader);

        self::assertSame(self::AZBEL_LOCATION_ID, $actions[0]->place->locationId);
    }

    #[DataProvider('statusesWithTheirActionCount')]
    public function testOnlyInProgressProjectsAskToLaunchJobs(ProjectStatus $status, int $expectedActionCount): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Sabre batch', $status, $owner);
        $this->jobOf($project, self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 2, 2, 0);

        self::assertCount($expectedActionCount, $this->launchJobActions($owner, [])->actionsOf($project, $owner));
    }

    public static function statusesWithTheirActionCount(): iterable
    {
        yield 'draft' => [ProjectStatus::Draft, 0];
        yield 'published' => [ProjectStatus::Published, 0];
        yield 'in progress' => [ProjectStatus::InProgress, 1];
        yield 'selling' => [ProjectStatus::Selling, 0];
        yield 'completed' => [ProjectStatus::Completed, 0];
    }

    #[DataProvider('storedProofsWithTheOfferedAccess')]
    public function testStepInAStructureIsOfferedUnlessTheReaderIsDeniedThere(?StructureAccessStatus $storedStatus, ?StructureAccessStatus $expectedAccess): void
    {
        $character = $this->characterOf();
        $reader = $this->pilotOf($character);
        $azbel = $this->azbelConfig();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $this->pilot());
        $component = $this->jobOf($project, self::COMPONENT_TYPE_ID, 'Composant X', 'component', 8, 8, 0)->setPlannedStructureId($azbel->getId()->toRfc4122());
        $this->matchedRunsOn($component, 3);
        $rows = null === $storedStatus ? [] : [$this->accessRowOf($character, self::AZBEL_LOCATION_ID, $storedStatus, StructureAccessSource::Esi, '2026-11-21T10:00:00+00:00')];

        $actions = $this->launchJobActions($reader, [$azbel], new MemberStructureAccess($this->readerHolding($rows)))->actionsOf($project, $reader);

        self::assertEquals(
            null === $expectedAccess ? [] : [
                new PlacedAction(
                    $project->getId()->toRfc4122(),
                    'Ishtar batch',
                    PlacedActionCode::LaunchJob,
                    $component->getId()->toRfc4122(),
                    self::COMPONENT_TYPE_ID,
                    'Composant X',
                    5,
                    ActionPlace::station(self::AZBEL_LOCATION_ID, self::AZBEL_NAME, self::PERIMETER_SYSTEM_ID, self::PERIMETER_SYSTEM_NAME),
                    $expectedAccess,
                ),
            ],
            $actions,
        );
    }

    public static function storedProofsWithTheOfferedAccess(): iterable
    {
        yield 'denied reader gets no action' => [StructureAccessStatus::Denied, null];
        yield 'reader without proof gets the action flagged unknown' => [null, StructureAccessStatus::Unknown];
        yield 'granted reader gets the action flagged granted' => [StructureAccessStatus::Granted, StructureAccessStatus::Granted];
    }

    public function testStepInANpcStationCarriesNoAccess(): void
    {
        $reader = $this->pilotOf($this->characterOf());
        $jitaFortizar = $this->azbelConfig(self::JITA_FORTIZAR_STATION_ID);
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $this->pilot());
        $this->jobOf($project, self::COMPONENT_TYPE_ID, 'Composant X', 'component', 8, 8, 0)->setPlannedStructureId($jitaFortizar->getId()->toRfc4122());

        $actions = $this->launchJobActions($reader, [$jitaFortizar])->actionsOf($project, $reader);

        self::assertCount(1, $actions);
        self::assertSame(self::JITA_FORTIZAR_STATION_ID, $actions[0]->place->locationId);
        self::assertNull($actions[0]->access);
    }

    public function testJobLineReservedByAnotherMemberIsNoActionForAViewerWithoutReservation(): void
    {
        $viewer = $this->pilot();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $this->pilot());
        $ram = $this->jobOf($project, self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 'component', 9, 9, 0);
        $this->reservations = $this->reservationsHolding($this->reservationOf($ram, $this->reserverNamed('Mira Kessel'), 3));

        self::assertSame([], $this->launchJobActions($viewer, [])->actionsOf($project, $viewer));
    }

    public function testViewerWhoReservedTheJobLineKeepsTheActionWithTheSameRemainingRuns(): void
    {
        $viewer = $this->pilot();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $this->pilot());
        $ram = $this->jobOf($project, self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 'component', 9, 9, 0);
        $this->matchedRunsOn($ram, 4);
        $this->reservations = $this->reservationsHolding($this->reservationOf($ram, $viewer, 3));

        $actions = $this->launchJobActions($viewer, [])->actionsOf($project, $viewer);

        self::assertSame([[PlacedActionCode::LaunchJob, self::RAM_TYPE_ID, 5]], $this->codesWithTypeIdsAndQuantities($actions));
        self::assertSame([], $actions[0]->reservedByOthers);
    }

    public function testViewerWhoReservedTheJobLineKeepsTheActionAndSeesTheOtherReservationsInOrder(): void
    {
        $viewer = $this->pilot();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $this->pilot());
        $ram = $this->jobOf($project, self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 'component', 9, 9, 0);
        $this->reservations = $this->reservationsHolding(
            $this->reservationOf($ram, $this->reserverNamed('Mira Kessel'), 3),
            $this->reservationOf($ram, $viewer, 2),
            $this->reservationOf($ram, $this->reserverNamed('Torin Vael'), 4),
        );

        $actions = $this->launchJobActions($viewer, [])->actionsOf($project, $viewer);

        self::assertSame([[PlacedActionCode::LaunchJob, self::RAM_TYPE_ID, 9]], $this->codesWithTypeIdsAndQuantities($actions));
        self::assertSame([['characterName' => 'Mira Kessel', 'quantity' => 3], ['characterName' => 'Torin Vael', 'quantity' => 4]], $actions[0]->reservedByOthers);
    }

    public function testJobLineReservedByAMemberWithoutUserIsNoActionForAViewerWithoutReservation(): void
    {
        $viewer = $this->pilot();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $this->pilot());
        $ram = $this->jobOf($project, self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 'component', 9, 9, 0);
        $this->reservations = $this->reservationsHolding($this->reservationOf($ram, null, 3));

        self::assertSame([], $this->launchJobActions($viewer, [])->actionsOf($project, $viewer));
    }

    public function testReservationOfAnotherJobLineDoesNotHideTheOtherJobLines(): void
    {
        $viewer = $this->pilot();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $this->pilot());
        $ram = $this->jobOf($project, self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 'component', 9, 9, 0);
        $this->jobOf($project, self::COMPONENT_TYPE_ID, 'Composant X', 'component', 6, 6, 0);
        $this->reservations = $this->reservationsHolding($this->reservationOf($ram, $this->reserverNamed('Mira Kessel'), 3));

        $actions = $this->launchJobActions($viewer, [])->actionsOf($project, $viewer);

        self::assertSame([[PlacedActionCode::LaunchJob, self::COMPONENT_TYPE_ID, 6]], $this->codesWithTypeIdsAndQuantities($actions));
        self::assertSame([], $actions[0]->reservedByOthers);
    }

    public function testJobLineWithoutRunCountFollowsTheSameReservationRules(): void
    {
        $reserver = $this->reserverNamed('Mira Kessel');
        $viewer = $this->pilot();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $this->pilot());
        $ishtar = $this->jobOf($project, self::ISHTAR_TYPE_ID, 'Ishtar', 'final', null, 2, 0);
        $this->reservations = $this->reservationsHolding($this->reservationOf($ishtar, $reserver, 1));

        self::assertSame([], $this->launchJobActions($viewer, [])->actionsOf($project, $viewer));
        self::assertSame([[PlacedActionCode::LaunchJob, self::ISHTAR_TYPE_ID, null]], $this->codesWithTypeIdsAndQuantities($this->launchJobActions($reserver, [])->actionsOf($project, $reserver)));
    }

    public function testReservationsAreReadOnceForTheWholeProject(): void
    {
        $viewer = $this->pilot();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $this->pilot());
        $this->jobOf($project, self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 'component', 9, 9, 0);
        $this->jobOf($project, self::COMPONENT_TYPE_ID, 'Composant X', 'component', 6, 6, 0);
        $reservations = $this->createMock(IndustryProjectLineReservationRepository::class);
        $reservations->expects(self::once())->method('findByProject')->with($project)->willReturn([]);
        $this->reservations = $reservations;

        self::assertCount(2, $this->launchJobActions($viewer, [])->actionsOf($project, $viewer));
    }

    public function testReservationsAreNotReadWhenTheProjectIsNotInProgress(): void
    {
        $viewer = $this->pilot();
        $project = $this->workshopProject('Sabre batch', ProjectStatus::Published, $this->pilot());
        $this->jobOf($project, self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 2, 2, 0);
        $reservations = $this->createMock(IndustryProjectLineReservationRepository::class);
        $reservations->expects(self::never())->method('findByProject');
        $this->reservations = $reservations;

        self::assertSame([], $this->launchJobActions($viewer, [])->actionsOf($project, $viewer));
    }

    private function launchJobActions(User $owner, array $ownerConfigs, ?MemberStructureAccess $memberAccess = null): LaunchJobActions
    {
        return new LaunchJobActions($this->placeResolver($ownerConfigs), $memberAccess ?? new MemberStructureAccess($this->readerHolding()), $this->reservations ?? $this->noReservations());
    }

    private function codesWithTypeIdsAndQuantities(array $actions): array
    {
        return array_map(static fn (PlacedAction $action): array => [$action->code, $action->typeId, $action->quantity], $actions);
    }
}
