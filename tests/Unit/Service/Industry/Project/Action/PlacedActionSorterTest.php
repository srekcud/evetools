<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\Action;

use App\Service\ESI\CharacterLocation;
use App\Service\Industry\Project\Action\ActionPlace;
use App\Service\Industry\Project\Action\LocatedAction;
use App\Service\Industry\Project\Action\PlacedAction;
use App\Service\Industry\Project\Action\PlacedActionCode;
use App\Service\Industry\Project\Action\PlacedActionSorter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlacedActionSorter::class)]
#[CoversClass(PlacedActionCode::class)]
#[Group('issue-1300')]
final class PlacedActionSorterTest extends TestCase
{
    private const int PERIMETER_SYSTEM_ID = 30_000_144;
    private const int JITA_SYSTEM_ID = 30_000_142;
    private const int AMARR_SYSTEM_ID = 30_002_187;
    private const int AZBEL_ID = 1_040_000_001_000;
    private const int TRADING_TOWER_ID = 1_040_000_002_000;
    private const int JITA_STATION_ID = 60_003_760;
    private const int AMARR_STATION_ID = 60_008_494;
    private const int OUTPOST_ID = 1_040_000_003_000;
    private const string PROJECT_ID = 'project-ishtar';

    public function testDockedPlaceComesFirstThenTheOtherPlacesThenTheUnknownPlaceThenAnywhere(): void
    {
        $sorted = (new PlacedActionSorter())->sort(
            [
                $this->action(PlacedActionCode::ReviewJoinRequests, ActionPlace::anywhere(), null),
                $this->action(PlacedActionCode::SourceMaterial, ActionPlace::unknown(ActionPlace::UNKNOWN_MARKET_NOT_CHOSEN), 'Zydrine'),
                $this->action(PlacedActionCode::SourceMaterial, $this->jita(), 'Tritanium'),
                $this->action(PlacedActionCode::LaunchJob, $this->azbel(), 'Composant X'),
            ],
            [new CharacterLocation(self::PERIMETER_SYSTEM_ID, null, self::AZBEL_ID, true, null)],
        );

        self::assertSame(
            [
                ['launch_job', 'Composant X', true],
                ['source_material', 'Tritanium', false],
                ['source_material', 'Zydrine', null],
                ['review_join_requests', null, null],
            ],
            $this->summary($sorted),
        );
    }

    public function testPlacesAreRankedDockedThenSameSolarSystemThenOtherStationsByNameThenUnknownThenAnywhere(): void
    {
        $sorted = (new PlacedActionSorter())->sort(
            [
                $this->action(PlacedActionCode::ReviewContributions, ActionPlace::anywhere(), null),
                $this->action(PlacedActionCode::SourceMaterial, ActionPlace::unknown(ActionPlace::UNKNOWN_STATION_UNRESOLVED), 'Nocxium'),
                $this->action(PlacedActionCode::SourceMaterial, $this->jita(), 'Tritanium'),
                $this->action(PlacedActionCode::SourceMaterial, ActionPlace::station(self::OUTPOST_ID, 'Aaa Outpost', null, null), 'Pyerite'),
                $this->action(PlacedActionCode::SourceMaterial, $this->amarr(), 'Mexallon'),
                $this->action(PlacedActionCode::SourceMaterial, $this->tradingTower(), 'Isogen'),
                $this->action(PlacedActionCode::LaunchJob, $this->azbel(), 'Composant X'),
            ],
            [new CharacterLocation(self::PERIMETER_SYSTEM_ID, null, self::AZBEL_ID, true, null)],
        );

        self::assertSame(
            [
                ['Composant X', true],
                ['Isogen', false],
                ['Pyerite', false],
                ['Mexallon', false],
                ['Tritanium', false],
                ['Nocxium', null],
                [null, null],
            ],
            array_map(static fn (array $row): array => [$row[1], $row[2]], $this->summary($sorted)),
        );
    }

    public function testCharacterDockedInANpcStationMakesThatStationTheHere(): void
    {
        $sorted = (new PlacedActionSorter())->sort(
            [
                $this->action(PlacedActionCode::SourceMaterial, $this->azbel(), 'Isogen'),
                $this->action(PlacedActionCode::SourceMaterial, $this->jita(), 'Tritanium'),
            ],
            [new CharacterLocation(self::JITA_SYSTEM_ID, self::JITA_STATION_ID, null, true, null)],
        );

        self::assertSame([['Tritanium', true], ['Isogen', false]], array_map(static fn (array $row): array => [$row[1], $row[2]], $this->summary($sorted)));
    }

    public function testEveryCharacterOfThePilotMakesItsPlaceHere(): void
    {
        $sorted = (new PlacedActionSorter())->sort(
            [
                $this->action(PlacedActionCode::SourceMaterial, $this->amarr(), 'Mexallon'),
                $this->action(PlacedActionCode::SourceMaterial, $this->jita(), 'Tritanium'),
                $this->action(PlacedActionCode::LaunchJob, $this->azbel(), 'Composant X'),
            ],
            [
                new CharacterLocation(self::JITA_SYSTEM_ID, self::JITA_STATION_ID, null, true, null),
                new CharacterLocation(self::PERIMETER_SYSTEM_ID, null, self::AZBEL_ID, false, null),
            ],
        );

        $hereByTypeName = [];
        foreach ($sorted as $located) {
            $hereByTypeName[$located->action->typeName] = $located->here;
        }
        self::assertEquals(['Mexallon' => false, 'Tritanium' => true, 'Composant X' => true], $hereByTypeName);
        self::assertSame('Mexallon', $sorted[2]->action->typeName);
    }

    public function testCharacterInSpaceMakesTheStationsOfItsSolarSystemComeSecondWithoutBeingHere(): void
    {
        $sorted = (new PlacedActionSorter())->sort(
            [
                $this->action(PlacedActionCode::SourceMaterial, $this->amarr(), 'Mexallon'),
                $this->action(PlacedActionCode::SourceMaterial, $this->tradingTower(), 'Isogen'),
            ],
            [new CharacterLocation(self::PERIMETER_SYSTEM_ID, null, null, true, null)],
        );

        self::assertSame([['Isogen', false], ['Mexallon', false]], array_map(static fn (array $row): array => [$row[1], $row[2]], $this->summary($sorted)));
    }

    public function testWithoutReadablePositionStationsAreOrderedByNameAndNoActionIsHere(): void
    {
        $sorted = (new PlacedActionSorter())->sort(
            [
                $this->action(PlacedActionCode::ReviewJoinRequests, ActionPlace::anywhere(), null),
                $this->action(PlacedActionCode::LaunchJob, $this->jita(), 'Composant X'),
                $this->action(PlacedActionCode::SourceMaterial, ActionPlace::unknown(ActionPlace::UNKNOWN_MARKET_NOT_CHOSEN), 'Zydrine'),
                $this->action(PlacedActionCode::SourceMaterial, $this->azbel(), 'Isogen'),
            ],
            [],
        );

        self::assertSame(
            [
                ['source_material', 'Isogen', null],
                ['launch_job', 'Composant X', null],
                ['source_material', 'Zydrine', null],
                ['review_join_requests', null, null],
            ],
            $this->summary($sorted),
        );
    }

    public function testStationsWithTheSameNameAreOrderedByLocation(): void
    {
        $sorted = (new PlacedActionSorter())->sort(
            [
                $this->action(PlacedActionCode::SourceMaterial, ActionPlace::station(self::TRADING_TOWER_ID, 'Azbel', null, null), 'Tritanium'),
                $this->action(PlacedActionCode::SourceMaterial, ActionPlace::station(self::AZBEL_ID, 'Azbel', null, null), 'Isogen'),
            ],
            [],
        );

        self::assertSame(['Isogen', 'Tritanium'], array_map(static fn (LocatedAction $located): ?string => $located->action->typeName, $sorted));
    }

    public function testActionsOfTheSamePlaceFollowTheRankOfTheirCode(): void
    {
        $sorted = (new PlacedActionSorter())->sort(
            [
                $this->action(PlacedActionCode::ReviewJoinRequests, ActionPlace::anywhere(), null),
                $this->action(PlacedActionCode::SourceMaterial, $this->jita(), 'Tritanium'),
                $this->action(PlacedActionCode::ReviewContributions, ActionPlace::anywhere(), null),
                $this->action(PlacedActionCode::LaunchJob, $this->jita(), 'Composant X'),
            ],
            [],
        );

        self::assertSame(
            ['launch_job', 'source_material', 'review_contributions', 'review_join_requests'],
            array_map(static fn (LocatedAction $located): string => $located->action->code->value, $sorted),
        );
    }

    public function testActionsOfTheSamePlaceAndCodeAreOrderedByProjectNameThenProjectIdThenTypeNameThenLineId(): void
    {
        $sorted = (new PlacedActionSorter())->sort(
            [
                $this->action(PlacedActionCode::SourceMaterial, $this->jita(), 'Tritanium', 'project-1', 'Bravo', 'line-a'),
                $this->action(PlacedActionCode::SourceMaterial, $this->jita(), 'Tritanium', 'project-3', 'Alpha', 'line-b'),
                $this->action(PlacedActionCode::SourceMaterial, $this->jita(), 'Tritanium', 'project-2', 'Alpha', 'line-c'),
                $this->action(PlacedActionCode::SourceMaterial, $this->jita(), 'Pyerite', 'project-2', 'Alpha', 'line-d'),
                $this->action(PlacedActionCode::SourceMaterial, $this->jita(), 'Tritanium', 'project-2', 'Alpha', 'line-a'),
            ],
            [],
        );

        self::assertSame(
            ['project-2:source_material:line-d', 'project-2:source_material:line-a', 'project-2:source_material:line-c', 'project-3:source_material:line-b', 'project-1:source_material:line-a'],
            array_map(static fn (LocatedAction $located): string => $located->action->id(), $sorted),
        );
    }

    public function testNothingToSortGivesNothing(): void
    {
        self::assertSame([], (new PlacedActionSorter())->sort([], [new CharacterLocation(self::JITA_SYSTEM_ID, null, null, true, null)]));
    }

    private function action(PlacedActionCode $code, ActionPlace $place, ?string $typeName, string $projectId = self::PROJECT_ID, string $projectName = 'Ishtar batch', ?string $lineId = null): PlacedAction
    {
        return new PlacedAction(
            $projectId,
            $projectName,
            $code,
            null === $typeName ? null : ($lineId ?? 'line-'.strtolower($typeName)),
            null,
            $typeName,
            null,
            $place,
        );
    }

    private function jita(): ActionPlace
    {
        return ActionPlace::station(self::JITA_STATION_ID, 'Jita IV - Moon 4 - Caldari Navy Assembly Plant', self::JITA_SYSTEM_ID, 'Jita');
    }

    private function azbel(): ActionPlace
    {
        return ActionPlace::station(self::AZBEL_ID, 'Azbel', self::PERIMETER_SYSTEM_ID, 'Perimeter');
    }

    private function tradingTower(): ActionPlace
    {
        return ActionPlace::station(self::TRADING_TOWER_ID, 'Tranquility Trading Tower', self::PERIMETER_SYSTEM_ID, 'Perimeter');
    }

    private function amarr(): ActionPlace
    {
        return ActionPlace::station(self::AMARR_STATION_ID, 'Amarr VIII (Oris) - Emperor Family Academy', self::AMARR_SYSTEM_ID, 'Amarr');
    }

    private function summary(array $sorted): array
    {
        return array_map(static fn (LocatedAction $located): array => [$located->action->code->value, $located->action->typeName, $located->here], $sorted);
    }
}
