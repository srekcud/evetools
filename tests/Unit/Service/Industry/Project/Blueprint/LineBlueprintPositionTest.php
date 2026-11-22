<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\Blueprint;

use App\Service\Industry\Project\Action\ActionPlace;
use App\Service\Industry\Project\AssetStation;
use App\Service\Industry\Project\Blueprint\LineBlueprintPosition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('issue-1296')]
final class LineBlueprintPositionTest extends TestCase
{
    private const int STEP_STATION_ID = 60_003_760;
    private const int OTHER_STATION_ID = 60_008_494;

    public function testBlueprintInTheStationOfTheStepIsHere(): void
    {
        $position = LineBlueprintPosition::of($this->stepStation(), new AssetStation(self::STEP_STATION_ID, 'Perimeter hangar', 'Perimeter'), null);

        self::assertSame(LineBlueprintPosition::HERE, $position->state);
        self::assertNull($position->unknownReason);
    }

    public function testBlueprintInAnotherStationIsElsewhere(): void
    {
        $position = LineBlueprintPosition::of($this->stepStation(), new AssetStation(self::OTHER_STATION_ID, 'Amarr hangar', 'Amarr'), null);

        self::assertSame(LineBlueprintPosition::ELSEWHERE, $position->state);
        self::assertNull($position->unknownReason);
    }

    public function testBlueprintWithoutStationReadsUnknownWithTheGivenReasonAndIsNeverHere(): void
    {
        $position = LineBlueprintPosition::of($this->stepStation(), null, 'asset_not_found');

        self::assertSame([LineBlueprintPosition::UNKNOWN, 'asset_not_found'], [$position->state, $position->unknownReason]);
    }

    public function testStepStationThatIsUnknownMakesTheStateUnknownWhateverTheStationOfTheBlueprint(): void
    {
        $unresolvedStep = ActionPlace::unknown(ActionPlace::UNKNOWN_STATION_UNRESOLVED);

        $withStation = LineBlueprintPosition::of($unresolvedStep, new AssetStation(self::STEP_STATION_ID, 'Perimeter hangar', 'Perimeter'), null);
        $withoutStation = LineBlueprintPosition::of($unresolvedStep, null, 'asset_not_found');

        self::assertSame([LineBlueprintPosition::UNKNOWN, 'step_station_unresolved'], [$withStation->state, $withStation->unknownReason]);
        self::assertSame([LineBlueprintPosition::UNKNOWN, 'step_station_unresolved'], [$withoutStation->state, $withoutStation->unknownReason]);
    }

    public function testStationReasonOfTheBlueprintIsTakenAsIsForEachReason(): void
    {
        $reasons = [];
        foreach (['blueprint_not_synced', 'station_unresolved', 'no_visible_division'] as $reason) {
            $reasons[] = LineBlueprintPosition::of($this->stepStation(), null, $reason)->unknownReason;
        }

        self::assertSame(['blueprint_not_synced', 'station_unresolved', 'no_visible_division'], $reasons);
    }

    private function stepStation(): ActionPlace
    {
        return ActionPlace::station(self::STEP_STATION_ID, 'Alpha', 30_000_144, 'Perimeter');
    }
}
