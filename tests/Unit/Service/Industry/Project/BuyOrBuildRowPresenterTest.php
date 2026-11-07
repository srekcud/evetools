<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Industry\Application\BuyOrBuildChoice;
use App\Industry\Application\IntermediateComparison;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\OrderBookCoverage;
use App\Industry\Domain\PartialPrice;
use App\Industry\Domain\Quantity;
use App\Service\Industry\Project\BuyOrBuildRowPresenter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuyOrBuildRowPresenter::class)]
#[Group('issue-343')]
final class BuyOrBuildRowPresenterTest extends TestCase
{
    private const int HOBGOBLIN_II = 2456;
    private const int FERNITE_COMPOSITE = 16670;
    private const int WARRIOR_II = 2488;
    private const int OGRE_II = 2446;

    public function testExampleABuiltDroneIsPresentedPerUnitOfTheDemand(): void
    {
        $comparison = new IntermediateComparison(
            self::HOBGOBLIN_II,
            ActivityKind::Manufacturing,
            5,
            new Quantity(50),
            new Quantity(50),
            new Quantity(0),
            Cost::known(new Isk(1_687_000.0)),
            Cost::known(new Isk(1_595_000.0)),
            Cost::known(new Isk(675_000.0)),
            10.0,
            9,
            BuyOrBuildChoice::Build,
            BuyOrBuildChoice::Buy,
            new Isk(583_000.0),
            null,
        );

        self::assertSame(
            $this->sorted([
                'typeId' => self::HOBGOBLIN_II,
                'typeName' => 'Hobgoblin II',
                'activity' => 'manufacturing',
                'quantity' => 50,
                'runs' => 5,
                'producedQuantity' => 50,
                'surplus' => 0,
                'volumePerUnit' => 10.0,
                'jumps' => 9,
                'buildPerUnit' => 33_740.0,
                'buildPerUnitIfSurplusReused' => null,
                'jitaPerUnit' => 31_900.0,
                'importPerUnit' => 13_500.0,
                'buyPerUnit' => 45_400.0,
                'choice' => 'build',
                'choiceWithoutImport' => 'buy',
                'saving' => 583_000.0,
                'savingIfSurplusReused' => null,
                'missingData' => [],
                'warnings' => [],
            ]),
            $this->sorted(BuyOrBuildRowPresenter::of($comparison, 'Hobgoblin II')),
        );
    }

    public function testExampleBBoughtReactionPresentsTheCostIfTheSurplusIsReusedPerProducedUnit(): void
    {
        $comparison = new IntermediateComparison(
            self::FERNITE_COMPOSITE,
            ActivityKind::Reaction,
            5,
            new Quantity(50),
            new Quantity(500),
            new Quantity(450),
            Cost::known(new Isk(15_700_000.0)),
            Cost::known(new Isk(1_545_000.0)),
            Cost::known(new Isk(67_500.0)),
            1.0,
            9,
            BuyOrBuildChoice::Buy,
            BuyOrBuildChoice::Buy,
            new Isk(14_087_500.0),
            new Isk(42_500.0),
        );

        self::assertSame(
            $this->sorted([
                'typeId' => self::FERNITE_COMPOSITE,
                'typeName' => 'Fernite Composite',
                'activity' => 'reaction',
                'quantity' => 50,
                'runs' => 5,
                'producedQuantity' => 500,
                'surplus' => 450,
                'volumePerUnit' => 1.0,
                'jumps' => 9,
                'buildPerUnit' => 314_000.0,
                'buildPerUnitIfSurplusReused' => 31_400.0,
                'jitaPerUnit' => 30_900.0,
                'importPerUnit' => 1_350.0,
                'buyPerUnit' => 32_250.0,
                'choice' => 'buy',
                'choiceWithoutImport' => 'buy',
                'saving' => 14_087_500.0,
                'savingIfSurplusReused' => 42_500.0,
                'missingData' => [],
                'warnings' => [],
            ]),
            $this->sorted(BuyOrBuildRowPresenter::of($comparison, 'Fernite Composite')),
        );
    }

    public function testExampleDUnknownJitaPriceIsPresentedAsNullsWithItsMissingData(): void
    {
        $comparison = new IntermediateComparison(
            self::WARRIOR_II,
            ActivityKind::Manufacturing,
            5,
            new Quantity(50),
            new Quantity(50),
            new Quantity(0),
            Cost::known(new Isk(1_687_000.0)),
            Cost::unknown(MissingData::marketPrice(self::WARRIOR_II)),
            Cost::known(new Isk(675_000.0)),
            10.0,
            9,
            BuyOrBuildChoice::Unknown,
            BuyOrBuildChoice::Unknown,
            null,
            null,
        );

        self::assertSame(
            $this->sorted([
                'typeId' => self::WARRIOR_II,
                'typeName' => 'Warrior II',
                'activity' => 'manufacturing',
                'quantity' => 50,
                'runs' => 5,
                'producedQuantity' => 50,
                'surplus' => 0,
                'volumePerUnit' => 10.0,
                'jumps' => 9,
                'buildPerUnit' => 33_740.0,
                'buildPerUnitIfSurplusReused' => null,
                'jitaPerUnit' => null,
                'importPerUnit' => 13_500.0,
                'buyPerUnit' => null,
                'choice' => 'unknown',
                'choiceWithoutImport' => 'unknown',
                'saving' => null,
                'savingIfSurplusReused' => null,
                'missingData' => [['reason' => 'market_price', 'typeId' => self::WARRIOR_II]],
                'warnings' => [],
            ]),
            $this->sorted(BuyOrBuildRowPresenter::of($comparison, 'Warrior II')),
        );
    }

    public function testUnknownImportAndUnknownTypeNameAreNullAndTheMissingDataIsListed(): void
    {
        $comparison = new IntermediateComparison(
            self::OGRE_II,
            ActivityKind::Manufacturing,
            5,
            new Quantity(50),
            new Quantity(50),
            new Quantity(0),
            Cost::known(new Isk(1_687_000.0)),
            Cost::known(new Isk(1_595_000.0)),
            Cost::unknown(MissingData::jumpRoute()),
            10.0,
            null,
            BuyOrBuildChoice::Unknown,
            BuyOrBuildChoice::Buy,
            null,
            null,
        );

        $row = BuyOrBuildRowPresenter::of($comparison, null);

        self::assertSame(
            [null, 31_900.0, null, null, null, 'unknown', 'buy', [['reason' => 'jump_route', 'typeId' => null]]],
            $this->pick($row, 'typeName', 'jitaPerUnit', 'importPerUnit', 'buyPerUnit', 'jumps', 'choice', 'choiceWithoutImport', 'missingData'),
        );
    }

    public function testPartialJitaPriceIsListedInTheWarningsOfTheRow(): void
    {
        $comparison = new IntermediateComparison(
            self::HOBGOBLIN_II,
            ActivityKind::Manufacturing,
            5,
            new Quantity(50),
            new Quantity(50),
            new Quantity(0),
            Cost::known(new Isk(1_687_000.0)),
            Cost::known(new Isk(1_595_000.0))->withPartialPrice(new PartialPrice(self::HOBGOBLIN_II, new OrderBookCoverage(0.6))),
            Cost::known(new Isk(675_000.0)),
            10.0,
            9,
            BuyOrBuildChoice::Build,
            BuyOrBuildChoice::Buy,
            new Isk(583_000.0),
            null,
        );

        self::assertSame(
            [[['reason' => 'partial_market_depth', 'typeId' => self::HOBGOBLIN_II, 'coveragePercent' => 60.0]]],
            $this->pick(BuyOrBuildRowPresenter::of($comparison, 'Hobgoblin II'), 'warnings'),
        );
    }

    public function testAmountsPerUnitAreRoundedToTwoDecimals(): void
    {
        $comparison = new IntermediateComparison(
            self::HOBGOBLIN_II,
            ActivityKind::Manufacturing,
            1,
            new Quantity(3),
            new Quantity(7),
            new Quantity(4),
            Cost::known(new Isk(100.0)),
            Cost::known(new Isk(200.0)),
            Cost::known(new Isk(50.0)),
            1.0,
            2,
            BuyOrBuildChoice::Build,
            BuyOrBuildChoice::Build,
            new Isk(150.0),
            null,
        );

        $row = BuyOrBuildRowPresenter::of($comparison, 'Hobgoblin II');

        self::assertSame(
            [33.33, 14.29, 66.67, 16.67, 83.33],
            $this->pick($row, 'buildPerUnit', 'buildPerUnitIfSurplusReused', 'jitaPerUnit', 'importPerUnit', 'buyPerUnit'),
        );
    }

    private function pick(array $row, string ...$keys): array
    {
        return array_map(static fn (string $key): mixed => \array_key_exists($key, $row) ? $row[$key] : 'absent', $keys);
    }

    private function sorted(array $row): array
    {
        ksort($row);

        return $row;
    }
}
