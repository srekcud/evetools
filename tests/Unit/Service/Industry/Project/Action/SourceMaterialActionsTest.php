<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\Action;

use App\Entity\IndustryProject;
use App\Enum\ProjectStatus;
use App\Enum\SourcingMarket;
use App\Service\Industry\Project\Action\ActionPlace;
use App\Service\Industry\Project\Action\PlacedAction;
use App\Service\Industry\Project\Action\PlacedActionCode;
use App\Service\Industry\Project\Action\SourceMaterialActions;
use App\Service\Industry\Project\ContainerStockState;
use App\Service\Industry\Project\ProjectContainerFulfillment;
use App\Service\Industry\Project\ProjectLineFulfillments;
use App\Tests\Unit\State\Provider\Industry\Project\BuildsWorkshopProjects;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceMaterialActions::class)]
#[Group('issue-1300')]
final class SourceMaterialActionsTest extends TestCase
{
    use BuildsActionPlaces;
    use BuildsWorkshopProjects;

    private const int TRITANIUM_TYPE_ID = 34;
    private const int PYERITE_TYPE_ID = 35;
    private const int ZYDRINE_TYPE_ID = 39;
    private const int COMPONENT_TYPE_ID = 11_540;

    private ProjectLineFulfillments $fulfillments;

    protected function setUp(): void
    {
        $this->fulfillments = ProjectLineFulfillments::none();
    }

    public function testEachMaterialLineStillToBuyIsAnActionWithTheRemainingQuantityAndThePlaceOfItsMarket(): void
    {
        $viewer = $this->pilot();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $viewer);
        $tritanium = $this->materialOf($project, self::TRITANIUM_TYPE_ID, 'Tritanium', 500, 100)->setSourcingMarket(SourcingMarket::Jita);
        $zydrine = $this->materialOf($project, self::ZYDRINE_TYPE_ID, 'Zydrine', 50, 0);
        $this->jobOf($project, self::COMPONENT_TYPE_ID, 'Composant X', 'component', 8, 8, 0);

        $actions = $this->sourceMaterialActions()->actionsOf($project, $viewer);

        self::assertEquals(
            [
                new PlacedAction(
                    $project->getId()->toRfc4122(),
                    'Ishtar batch',
                    PlacedActionCode::SourceMaterial,
                    $tritanium->getId()->toRfc4122(),
                    self::TRITANIUM_TYPE_ID,
                    'Tritanium',
                    400,
                    ActionPlace::station(60_003_760, self::JITA_STATION_NAME, 30_000_142, self::JITA_SYSTEM_NAME),
                ),
                new PlacedAction(
                    $project->getId()->toRfc4122(),
                    'Ishtar batch',
                    PlacedActionCode::SourceMaterial,
                    $zydrine->getId()->toRfc4122(),
                    self::ZYDRINE_TYPE_ID,
                    'Zydrine',
                    50,
                    ActionPlace::unknown(ActionPlace::UNKNOWN_MARKET_NOT_CHOSEN),
                ),
            ],
            $this->sortedByTypeId($actions),
        );
    }

    public function testRemainingQuantityCountsWhatTheContainersHoldAndWhatStartedJobsConsumed(): void
    {
        $viewer = $this->pilot();
        $project = $this->workshopProject('Hecate batch', ProjectStatus::InProgress, $viewer);
        $this->materialOf($project, self::PYERITE_TYPE_ID, 'Pyerite', 1_000, 50);
        $this->fulfillments = new ProjectLineFulfillments(ContainerStockState::Known, [self::PYERITE_TYPE_ID => 300], [self::PYERITE_TYPE_ID => 100]);

        $actions = $this->sourceMaterialActions()->actionsOf($project, $viewer);

        self::assertSame([[self::PYERITE_TYPE_ID, 600]], $this->typeIdsWithQuantities($actions));
    }

    public function testLinesAlreadyCoveredByTheLineOrByTheContainersEmitNothing(): void
    {
        $viewer = $this->pilot();
        $project = $this->workshopProject('Gila batch', ProjectStatus::InProgress, $viewer);
        $this->materialOf($project, self::ZYDRINE_TYPE_ID, 'Zydrine', 50, 50);
        $this->materialOf($project, self::PYERITE_TYPE_ID, 'Pyerite', 1_000, 0);
        $this->materialOf($project, self::TRITANIUM_TYPE_ID, 'Tritanium', 700, 20);
        $this->fulfillments = new ProjectLineFulfillments(ContainerStockState::Known, [self::PYERITE_TYPE_ID => 1_200, self::TRITANIUM_TYPE_ID => 500]);

        $actions = $this->sourceMaterialActions()->actionsOf($project, $viewer);

        self::assertSame([[self::TRITANIUM_TYPE_ID, 200]], $this->typeIdsWithQuantities($actions));
    }

    public function testTwoLinesOfTheSameTypeAreTwoActionsPlacedByTheMarketOfTheReaderNotOfTheOwner(): void
    {
        $reader = $this->viewerFollowingTheMarketStructure();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $this->pilot());
        $inJita = $this->materialOf($project, self::TRITANIUM_TYPE_ID, 'Tritanium', 300, 0)->setSourcingMarket(SourcingMarket::Jita);
        $inStructure = $this->materialOf($project, self::TRITANIUM_TYPE_ID, 'Tritanium', 120, 20)->setSourcingMarket(SourcingMarket::Structure);

        $actions = $this->sourceMaterialActions()->actionsOf($project, $reader);

        self::assertEqualsCanonicalizing(
            [
                [$inJita->getId()->toRfc4122(), 300, 'station', self::JITA_STATION_NAME],
                [$inStructure->getId()->toRfc4122(), 100, 'station', self::MARKET_STRUCTURE_NAME],
            ],
            array_map(
                static fn (PlacedAction $action): array => [$action->bomLineId, $action->quantity, $action->place->kind, $action->place->name],
                $actions,
            ),
        );
    }

    #[DataProvider('statusesWithTheirActionCount')]
    public function testOnlyPublishedAndInProgressProjectsAskToSourceMaterial(ProjectStatus $status, int $expectedActionCount): void
    {
        $viewer = $this->pilot();
        $project = $this->workshopProject('Sabre batch', $status, $viewer);
        $this->materialOf($project, self::TRITANIUM_TYPE_ID, 'Tritanium', 500, 100);

        self::assertCount($expectedActionCount, $this->sourceMaterialActions()->actionsOf($project, $viewer));
    }

    public static function statusesWithTheirActionCount(): iterable
    {
        yield 'draft' => [ProjectStatus::Draft, 0];
        yield 'published' => [ProjectStatus::Published, 1];
        yield 'in progress' => [ProjectStatus::InProgress, 1];
        yield 'selling' => [ProjectStatus::Selling, 0];
        yield 'completed' => [ProjectStatus::Completed, 0];
    }

    private function sourceMaterialActions(): SourceMaterialActions
    {
        $containerFulfillment = $this->createStub(ProjectContainerFulfillment::class);
        $containerFulfillment->method('of')->willReturnCallback(fn (IndustryProject $project): ProjectLineFulfillments => $this->fulfillments);

        return new SourceMaterialActions($containerFulfillment, $this->placeResolver());
    }

    private function sortedByTypeId(array $actions): array
    {
        usort($actions, static fn (PlacedAction $left, PlacedAction $right): int => $left->typeId <=> $right->typeId);

        return $actions;
    }

    private function typeIdsWithQuantities(array $actions): array
    {
        return array_map(static fn (PlacedAction $action): array => [$action->typeId, $action->quantity], $this->sortedByTypeId($actions));
    }
}
