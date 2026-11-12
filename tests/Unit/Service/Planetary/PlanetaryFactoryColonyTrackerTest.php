<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary;

use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\Sde\InvType;
use App\Entity\Sde\PlanetSchematic;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\PlanetSchematicRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\Planetary\PlanetaryFactoryColonyTracker;
use App\Service\TypeNameResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetaryFactoryColonyTracker::class)]
#[Group('issue-744')]
final class PlanetaryFactoryColonyTrackerTest extends TestCase
{
    private const AQUEOUS_LIQUIDS = 2268;
    private const WATER = 3645;
    private const ELECTROLYTES = 2390;
    private const BASE_METALS = 2267;
    private const REACTIVE_METALS = 2398;
    private const COOLANT = 9832;

    private const SCHEMATIC_WATER = 121;
    private const SCHEMATIC_REACTIVE_METALS = 126;
    private const SCHEMATIC_COOLANT = 66;
    private const SCHEMATIC_ALLOY = 77;
    private const ALLOY = 3001;
    private const SCHEMATIC_BROKEN = 999;

    private const SCHEMATIC_MAP = [
        self::SCHEMATIC_WATER => [
            'inputs' => [self::AQUEOUS_LIQUIDS => 3000],
            'output' => ['typeId' => self::WATER, 'quantity' => 20],
        ],
        self::SCHEMATIC_REACTIVE_METALS => [
            'inputs' => [self::BASE_METALS => 3000],
            'output' => ['typeId' => self::REACTIVE_METALS, 'quantity' => 20],
        ],
        self::SCHEMATIC_COOLANT => [
            'inputs' => [self::ELECTROLYTES => 40, self::WATER => 40],
            'output' => ['typeId' => self::COOLANT, 'quantity' => 5],
        ],
        self::SCHEMATIC_ALLOY => [
            'inputs' => [self::WATER => 40, self::AQUEOUS_LIQUIDS => 3000],
            'output' => ['typeId' => self::ALLOY, 'quantity' => 1],
        ],
        self::SCHEMATIC_BROKEN => [
            'inputs' => [self::ELECTROLYTES => 40],
            'output' => null,
        ],
    ];

    private const CYCLE_TIMES = [
        self::SCHEMATIC_WATER => 1800,
        self::SCHEMATIC_REACTIVE_METALS => 1800,
        self::SCHEMATIC_COOLANT => 3600,
        self::SCHEMATIC_ALLOY => 3600,
    ];

    private int $nextPinId = 1;

    public function testColonyWithoutFactoryIsNotAFactoryColony(): void
    {
        $this->assertNull($this->tracker()->track($this->colony($this->storagePin([['type_id' => self::WATER, 'amount' => 100]]))));
    }

    public function testColonyWithAnExtractorIsNotAFactoryColony(): void
    {
        $colony = $this->colony(
            $this->extractorPin(self::AQUEOUS_LIQUIDS),
            $this->factoryPin(self::SCHEMATIC_WATER),
        );

        $this->assertNull($this->tracker()->track($colony));
    }

    public function testColonyWhoseFactoriesHaveNoKnownSchematicIsNotAFactoryColony(): void
    {
        $colony = $this->colony($this->factoryPin(self::SCHEMATIC_BROKEN), $this->factoryPin(4242));

        $this->assertNull($this->tracker()->track($colony));
    }

    public function testStockLimitsTheCyclesOfTheFinalProductByItsScarcestInput(): void
    {
        $colony = $this->colony(
            $this->storagePin([
                ['type_id' => self::ELECTROLYTES, 'amount' => 400],
                ['type_id' => self::WATER, 'amount' => 240],
            ]),
            $this->factoryPin(self::SCHEMATIC_COOLANT),
        );

        $progress = $this->tracker()->track($colony);

        $this->assertNotNull($progress);
        $this->assertSame(self::COOLANT, $progress['finalProductTypeId']);
        $this->assertSame('Coolant', $progress['finalProductName']);
        $this->assertSame(1, $progress['factoryCount']);
        $this->assertSame(30, $progress['maxProducts']);
        $this->assertSame(21600, $progress['remainingSeconds']);
    }

    public function testTheRemainingTimeIsSplitBetweenTheFactoriesOfTheFinalProduct(): void
    {
        $colony = $this->colony(
            $this->storagePin([
                ['type_id' => self::ELECTROLYTES, 'amount' => 400],
                ['type_id' => self::WATER, 'amount' => 1000],
            ]),
            $this->factoryPin(self::SCHEMATIC_COOLANT),
            $this->factoryPin(self::SCHEMATIC_COOLANT),
        );

        $progress = $this->tracker()->track($colony);

        $this->assertNotNull($progress);
        $this->assertSame(2, $progress['factoryCount']);
        $this->assertSame(50, $progress['maxProducts']);
        $this->assertSame(18000, $progress['remainingSeconds']);
    }

    public function testAnOddNumberOfCyclesRoundsTheTimePerFactoryUp(): void
    {
        $colony = $this->colony(
            $this->storagePin([
                ['type_id' => self::ELECTROLYTES, 'amount' => 120],
                ['type_id' => self::WATER, 'amount' => 1000],
            ]),
            $this->factoryPin(self::SCHEMATIC_COOLANT),
            $this->factoryPin(self::SCHEMATIC_COOLANT),
        );

        $progress = $this->tracker()->track($colony);

        $this->assertNotNull($progress);
        $this->assertSame(15, $progress['maxProducts']);
        $this->assertSame(7200, $progress['remainingSeconds']);
    }

    public function testAnIntermediateMissingFromTheStockIsCountedThroughItsOwnFactories(): void
    {
        $colony = $this->colony(
            $this->storagePin([
                ['type_id' => self::AQUEOUS_LIQUIDS, 'amount' => 12000],
                ['type_id' => self::ELECTROLYTES, 'amount' => 400],
            ]),
            $this->factoryPin(self::SCHEMATIC_WATER),
            $this->factoryPin(self::SCHEMATIC_COOLANT),
        );

        $progress = $this->tracker()->track($colony);

        $this->assertNotNull($progress);
        $this->assertSame(self::COOLANT, $progress['finalProductTypeId']);
        $this->assertSame(10, $progress['maxProducts']);
        $this->assertSame(7200, $progress['remainingSeconds']);
    }

    public function testAnIntermediateWithoutFactoryInTheColonyIsNotCompletedFromNothing(): void
    {
        $colony = $this->colony(
            $this->storagePin([['type_id' => self::AQUEOUS_LIQUIDS, 'amount' => 90000], ['type_id' => self::ELECTROLYTES, 'amount' => 400]]),
            $this->factoryPin(self::SCHEMATIC_COOLANT),
        );

        $progress = $this->tracker()->track($colony);

        $this->assertNotNull($progress);
        $this->assertSame(0, $progress['maxProducts']);
        $this->assertSame(0, $progress['remainingSeconds']);
    }

    public function testARawStockSharedByTwoInputsOfTheFinalProductIsSpentOnlyOnce(): void
    {
        $colony = $this->colony(
            $this->storagePin([['type_id' => self::AQUEOUS_LIQUIDS, 'amount' => 18000]]),
            $this->factoryPin(self::SCHEMATIC_WATER),
            $this->factoryPin(self::SCHEMATIC_ALLOY),
        );

        $progress = $this->tracker()->track($colony);

        $this->assertNotNull($progress);
        $this->assertSame(self::ALLOY, $progress['finalProductTypeId']);
        $this->assertSame(2, $progress['maxProducts']);
        $this->assertSame(7200, $progress['remainingSeconds']);
    }

    public function testTheStockOfTheFinalProductIsReportedAndNeverConsumed(): void
    {
        $colony = $this->colony(
            $this->storagePin([
                ['type_id' => self::AQUEOUS_LIQUIDS, 'amount' => 12000],
                ['type_id' => self::ELECTROLYTES, 'amount' => 400],
                ['type_id' => self::COOLANT, 'amount' => 5],
            ]),
            $this->factoryPin(self::SCHEMATIC_WATER),
            $this->factoryPin(self::SCHEMATIC_COOLANT),
        );

        $progress = $this->tracker()->track($colony);

        $this->assertNotNull($progress);
        $this->assertSame(5, $progress['stockedFinalProduct']);
        $this->assertSame(10, $progress['maxProducts']);
    }

    public function testStockHeldInsideAFactoryCountsLikeStorageStock(): void
    {
        $colony = $this->colony(
            $this->factoryPin(self::SCHEMATIC_COOLANT, [
                ['type_id' => self::ELECTROLYTES, 'amount' => 80],
                ['type_id' => self::WATER, 'amount' => 80],
            ]),
        );

        $progress = $this->tracker()->track($colony);

        $this->assertNotNull($progress);
        $this->assertSame(10, $progress['maxProducts']);
        $this->assertSame(7200, $progress['remainingSeconds']);
    }

    public function testNoStockAtAllLeavesNothingToMakeAndNoTimeRemaining(): void
    {
        $progress = $this->tracker()->track($this->colony($this->factoryPin(self::SCHEMATIC_COOLANT)));

        $this->assertNotNull($progress);
        $this->assertSame(0, $progress['stockedFinalProduct']);
        $this->assertSame(0, $progress['maxProducts']);
        $this->assertSame(0, $progress['remainingSeconds']);
    }

    public function testTheDeepestUnconsumedProductIsTheFinalProduct(): void
    {
        $colony = $this->colony(
            $this->factoryPin(self::SCHEMATIC_REACTIVE_METALS),
            $this->factoryPin(self::SCHEMATIC_REACTIVE_METALS),
            $this->factoryPin(self::SCHEMATIC_WATER),
            $this->factoryPin(self::SCHEMATIC_COOLANT),
        );

        $progress = $this->tracker()->track($colony);

        $this->assertNotNull($progress);
        $this->assertSame(self::COOLANT, $progress['finalProductTypeId']);
        $this->assertSame(1, $progress['factoryCount']);
    }

    private function tracker(): PlanetaryFactoryColonyTracker
    {
        $schematicTypeRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $schematicTypeRepository->method('getSchematicMap')->willReturn(self::SCHEMATIC_MAP);

        $schematicRepository = $this->createStub(PlanetSchematicRepository::class);
        $schematicRepository->method('findBySchematicId')->willReturnCallback(
            static function (int $schematicId): ?PlanetSchematic {
                if (!isset(self::CYCLE_TIMES[$schematicId])) {
                    return null;
                }
                $schematic = new PlanetSchematic();
                $schematic->setSchematicId($schematicId);
                $schematic->setSchematicName('Schematic '.$schematicId);
                $schematic->setCycleTime(self::CYCLE_TIMES[$schematicId]);

                return $schematic;
            },
        );

        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('find')->willReturnCallback(
            static fn (mixed $typeId): ?InvType => $typeId === self::COOLANT
                ? (new InvType())->setTypeId(self::COOLANT)->setTypeName('Coolant')
                : null,
        );

        return new PlanetaryFactoryColonyTracker(
            $schematicTypeRepository,
            $schematicRepository,
            new TypeNameResolver($invTypeRepository),
        );
    }

    private function colony(PlanetaryPin ...$pins): PlanetaryColony
    {
        $colony = new PlanetaryColony();
        foreach ($pins as $pin) {
            $colony->addPin($pin);
        }

        return $colony;
    }

    private function extractorPin(int $productTypeId): PlanetaryPin
    {
        $pin = new PlanetaryPin();
        $pin->setPinId($this->nextPinId++);
        $pin->setTypeId(2848);
        $pin->setExtractorProductTypeId($productTypeId);
        $pin->setExtractorCycleTime(1800);
        $pin->setExtractorQtyPerCycle(3000);
        $pin->setExpiryTime(new \DateTimeImmutable('+2 days'));

        return $pin;
    }

    private function factoryPin(int $schematicId, ?array $contents = null): PlanetaryPin
    {
        $pin = new PlanetaryPin();
        $pin->setPinId($this->nextPinId++);
        $pin->setTypeId(2473);
        $pin->setSchematicId($schematicId);
        $pin->setContents($contents);

        return $pin;
    }

    private function storagePin(array $contents): PlanetaryPin
    {
        $pin = new PlanetaryPin();
        $pin->setPinId($this->nextPinId++);
        $pin->setTypeId(2541);
        $pin->setContents($contents);

        return $pin;
    }
}
