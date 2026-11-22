<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\Action;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Service\Industry\Project\Action\ActionPlace;
use App\Service\Industry\Project\Action\LaunchJobActions;
use App\Service\Industry\Project\Action\PlacedAction;
use App\Service\Industry\Project\Action\PlacedActionCode;
use App\Tests\Unit\State\Provider\Industry\Project\BuildsWorkshopProjects;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(LaunchJobActions::class)]
#[Group('issue-1300')]
final class LaunchJobActionsTest extends TestCase
{
    use BuildsActionPlaces;
    use BuildsWorkshopProjects;

    private const int COMPONENT_TYPE_ID = 11_540;
    private const int RAM_TYPE_ID = 11_478;
    private const int ISHTAR_TYPE_ID = 12_005;
    private const int TRITANIUM_TYPE_ID = 34;

    public function testJobLineWithRunsStillToLaunchIsAnActionWithTheRemainingRunsAtTheStationOfItsStep(): void
    {
        $owner = $this->pilot();
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
                ),
            ],
            $actions,
        );
    }

    public function testJobLineWithoutRunCountStaysAnActionWithAnUnknownQuantity(): void
    {
        $owner = $this->pilot();
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
    }

    public function testStepStationIsReadFromTheConfigurationsOfTheOwnerNotOfTheReader(): void
    {
        $owner = $this->pilot();
        $reader = $this->pilot();
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

    private function launchJobActions(User $owner, array $ownerConfigs): LaunchJobActions
    {
        return new LaunchJobActions($this->placeResolver($ownerConfigs));
    }

    private function codesWithTypeIdsAndQuantities(array $actions): array
    {
        return array_map(static fn (PlacedAction $action): array => [$action->code, $action->typeId, $action->quantity], $actions);
    }
}
