<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Enum\ProjectStatus;
use App\Service\Industry\Project\CockpitBomLine;
use App\Service\Industry\Project\CockpitPhase;
use App\Service\Industry\Project\ProjectCockpit;
use App\Service\Industry\Project\ProjectCockpitCalculator;
use App\Service\Industry\Project\ProjectCockpitInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectCockpitCalculator::class)]
#[Group('issue-1227')]
final class ProjectCockpitCalculatorValueAndCloseTest extends TestCase
{
    private const int TRITANIUM_TYPE_ID = 34;
    private const int PYERITE_TYPE_ID = 35;
    private const int MORPHITE_TYPE_ID = 11399;
    private const int ISHTAR_TYPE_ID = 12005;

    private ProjectCockpitCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ProjectCockpitCalculator();
    }

    public function testPublishedProjectSourcesTheUncoveredMaterialWithTheHighestRemainingValueNotTheMostUnits(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::Published, [
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 25_000, 0, 5.0),
            $this->material('line-morphite', self::MORPHITE_TYPE_ID, 'Morphite', 100, 80, 20_000.0),
            $this->finalJob('line-ishtar', 1),
        ]);

        self::assertSame(['source_material', 'line-morphite', self::MORPHITE_TYPE_ID, 20], $this->actionRow($cockpit));
    }

    public function testInProgressProjectValuesTheRemainingQuantityNotTheRequiredQuantity(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, [
            $this->material('line-pyerite', self::PYERITE_TYPE_ID, 'Pyerite', 10_000, 9_000, 10.0),
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 2_000, 0, 6.0),
            $this->finalJob('line-ishtar', 1),
        ]);

        self::assertSame(['source_material', 'line-tritanium', self::TRITANIUM_TYPE_ID, 2_000], $this->actionRow($cockpit));
    }

    public function testAMaterialWithAKnownValueIsSourcedBeforeAnUnpricedMaterialWithMoreUnits(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::Published, [
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 25_000, 0, null),
            $this->material('line-morphite', self::MORPHITE_TYPE_ID, 'Morphite', 100, 0, 1.0),
            $this->finalJob('line-ishtar', 1),
        ]);

        self::assertSame(['source_material', 'line-morphite', self::MORPHITE_TYPE_ID, 100], $this->actionRow($cockpit));
    }

    public function testUnpricedMaterialsKeepTheLargestRemainderThenTheNameOrder(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::Published, [
            $this->material('line-pyerite', self::PYERITE_TYPE_ID, 'Pyerite', 6_000, 0, null),
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 24_000, 0, null),
            $this->finalJob('line-ishtar', 1),
        ]);

        self::assertSame(['source_material', 'line-tritanium', self::TRITANIUM_TYPE_ID, 24_000], $this->actionRow($cockpit));
    }

    public function testEqualValuesAreOrderedByTypeName(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::Published, [
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 100, 0, 10.0),
            $this->material('line-pyerite', self::PYERITE_TYPE_ID, 'Pyerite', 50, 0, 20.0),
            $this->finalJob('line-ishtar', 1),
        ]);

        self::assertSame(['source_material', 'line-pyerite', self::PYERITE_TYPE_ID, 50], $this->actionRow($cockpit));
    }

    public function testSellingProjectWithEveryUnitSoldHasSellDoneAndCloseCurrent(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::Selling, [
            $this->finalJob('line-ishtar', 2, 2),
        ], [self::ISHTAR_TYPE_ID => 2]);

        self::assertSame([
            ['prepare', 'done', 1, 1],
            ['supply', 'done', 0, 0],
            ['produce', 'done', 1, 1],
            ['sell', 'done', 2, 2],
            ['close', 'current', 0, 1],
        ], $this->phaseRows($cockpit));
        self::assertSame('complete_project', $cockpit->nextAction->code);
    }

    public function testSellingProjectWithUnitsLeftKeepsSellCurrentAndCloseUpcoming(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::Selling, [
            $this->finalJob('line-ishtar', 2, 2),
        ], [self::ISHTAR_TYPE_ID => 1]);

        self::assertSame(['sell', 'current', 1, 2], $this->phaseRows($cockpit)[3]);
        self::assertSame(['close', 'upcoming', 0, 1], $this->phaseRows($cockpit)[4]);
        self::assertSame('record_sale', $cockpit->nextAction->code);
    }

    private function material(string $id, int $typeId, string $typeName, int $requiredQuantity, int $fulfilledQuantity, ?float $estimatedPrice): CockpitBomLine
    {
        return new CockpitBomLine($id, $typeId, $typeName, $requiredQuantity, $fulfilledQuantity, false, null, null, 0, 0, null, $estimatedPrice);
    }

    private function finalJob(string $id, int $quantity, int $deliveredRuns = 0): CockpitBomLine
    {
        return new CockpitBomLine($id, self::ISHTAR_TYPE_ID, 'Ishtar', $quantity, 0, true, 'final', $quantity, $deliveredRuns, $deliveredRuns);
    }

    /**
     * @param list<CockpitBomLine> $bomLines
     * @param array<int, int>      $soldQuantityByTypeId
     */
    private function cockpitOf(ProjectStatus $status, array $bomLines, array $soldQuantityByTypeId = []): ProjectCockpit
    {
        return $this->calculator->calculate(new ProjectCockpitInput($status, 1, $bomLines, $soldQuantityByTypeId, 0, true));
    }

    /**
     * @return list<array{string, string, int, int}>
     */
    private function phaseRows(ProjectCockpit $cockpit): array
    {
        return array_map(
            static fn (CockpitPhase $phase): array => [$phase->key, $phase->state, $phase->counterDone, $phase->counterTotal],
            $cockpit->phases,
        );
    }

    /**
     * @return array{string, ?string, ?int, ?int}
     */
    private function actionRow(ProjectCockpit $cockpit): array
    {
        $action = $cockpit->nextAction;

        return [$action->code, $action->bomLineId, $action->typeId, $action->quantity];
    }
}
