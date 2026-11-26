<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary;

use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\PlanetSchematic;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\PlanetSchematicRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\Planetary\ExtractorYieldCalculator;
use App\Service\Planetary\PlanetaryExtractionBalanceCalculator;
use App\Service\Planetary\PlanetaryInputNeedsCalculator;
use App\Service\TypeNameResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[Group('issue-1387')]
#[CoversClass(PlanetaryExtractionBalanceCalculator::class)]
final class PlanetaryExtractionBalanceCalculatorTest extends TestCase
{
    private const string NOW = '2026-11-26 12:00:00';
    private const string YESTERDAY = '2026-11-25 12:00:00';
    private const string TOMORROW = '2026-11-27 12:00:00';

    private const int COMMODITIES_GROUP_ID = 1042;
    private const int PROCESSORS_GROUP_ID = 1028;
    private const int EXTRACTOR_CONTROL_UNITS_GROUP_ID = 1063;

    private const int FACTORY_TYPE_ID = 2473;
    private const int EXTRACTOR_TYPE_ID = 2848;

    private const int BACTERIA = 2393;
    private const int REACTIVE_METALS = 2398;
    private const int PROTEINS = 2395;
    private const int MICROORGANISMS = 2073;
    private const int AQUEOUS_LIQUIDS = 2268;
    private const int BASE_METALS = 2267;

    private const int OXYGEN = 3683;
    private const int COOLANT = 9832;
    private const int WATER = 3645;
    private const int BIOFUELS = 2396;
    private const int ELECTROLYTES = 2327;
    private const int INDUSTRIAL_FIBERS = 2397;

    private const int SCHEMATIC_BACTERIA = 70;
    private const int SCHEMATIC_REACTIVE_METALS = 71;
    private const int SCHEMATIC_PROTEINS = 72;
    private const int SCHEMATIC_MICROORGANISMS = 73;
    private const int SCHEMATIC_AQUEOUS_LIQUIDS = 74;
    private const int SCHEMATIC_BASE_METALS = 75;

    private const int FIRST_FACTORY_PIN = 1_038_700_000_201;
    private const int FIRST_EXTRACTOR_PIN = 1_038_700_000_301;

    private PlanetaryExtractionBalanceCalculator $calculator;

    /** @var array<int, InvType> */
    private array $sdeTypes = [];

    /** @var array<int, array{inputs: array<int, int>, output: array{typeId: int, quantity: int}|null}> */
    private array $schematicMap = [];

    /** @var array<int, PlanetSchematic> */
    private array $schematics = [];

    private int $nextFactoryPin = self::FIRST_FACTORY_PIN;
    private int $nextExtractorPin = self::FIRST_EXTRACTOR_PIN;

    protected function setUp(): void
    {
        $schematicRepository = $this->createStub(PlanetSchematicRepository::class);
        $schematicTypeRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $typeNameResolver = $this->createStub(TypeNameResolver::class);

        $schematicTypeRepository->method('getSchematicMap')->willReturnCallback(fn (): array => $this->schematicMap);
        $schematicRepository->method('findBySchematicId')->willReturnCallback(
            fn (int $schematicId): ?PlanetSchematic => $this->schematics[$schematicId] ?? null,
        );
        $invTypeRepository->method('findByTypeIds')->willReturnCallback(
            fn (array $typeIds): array => array_intersect_key($this->sdeTypes, array_flip($typeIds)),
        );
        $invTypeRepository->method('find')->willReturnCallback(fn (mixed $typeId): ?InvType => $this->sdeTypes[$typeId] ?? null);
        $invTypeRepository->method('findByTypeId')->willReturnCallback(fn (int $typeId): ?InvType => $this->sdeTypes[$typeId] ?? null);
        $typeNameResolver->method('findName')->willReturnCallback(
            fn (int $typeId): ?string => isset($this->sdeTypes[$typeId]) ? $this->sdeTypes[$typeId]->getTypeName() : null,
        );

        $clock = new MockClock(self::NOW);
        $this->calculator = new PlanetaryExtractionBalanceCalculator(
            new PlanetaryInputNeedsCalculator(
                $schematicRepository,
                $schematicTypeRepository,
                $invTypeRepository,
                $typeNameResolver,
                $clock,
                $this->constantRateExtractorYield(),
            ),
            $clock,
        );

        $this->registerType(self::FACTORY_TYPE_ID, 'Basic Industry Facility', self::PROCESSORS_GROUP_ID);
        $this->registerType(self::EXTRACTOR_TYPE_ID, 'Extractor Control Unit', self::EXTRACTOR_CONTROL_UNITS_GROUP_ID);
        $this->registerType(self::BACTERIA, 'Bacteria', self::COMMODITIES_GROUP_ID, 0.01);
        $this->registerType(self::REACTIVE_METALS, 'Reactive Metals', self::COMMODITIES_GROUP_ID, 0.02);
        $this->registerType(self::PROTEINS, 'Proteins', self::COMMODITIES_GROUP_ID, 0.03);
        $this->registerType(self::MICROORGANISMS, 'Microorganisms', self::COMMODITIES_GROUP_ID, 0.04);
        $this->registerType(self::AQUEOUS_LIQUIDS, 'Aqueous Liquids', self::COMMODITIES_GROUP_ID, 0.05);
        $this->registerType(self::BASE_METALS, 'Base Metals', self::COMMODITIES_GROUP_ID, 0.06);

        $this->defineBasicSchematic(self::SCHEMATIC_BACTERIA, self::BACTERIA, self::OXYGEN);
        $this->defineBasicSchematic(self::SCHEMATIC_REACTIVE_METALS, self::REACTIVE_METALS, self::COOLANT);
        $this->defineBasicSchematic(self::SCHEMATIC_PROTEINS, self::PROTEINS, self::WATER);
        $this->defineBasicSchematic(self::SCHEMATIC_MICROORGANISMS, self::MICROORGANISMS, self::BIOFUELS);
        $this->defineBasicSchematic(self::SCHEMATIC_AQUEOUS_LIQUIDS, self::AQUEOUS_LIQUIDS, self::ELECTROLYTES);
        $this->defineBasicSchematic(self::SCHEMATIC_BASE_METALS, self::BASE_METALS, self::INDUSTRIAL_FIBERS);
    }

    public function testTwoHeadsFeedingTwoFactoriesLeaveADeficitAdvisingHeadsOrFewerFactories(): void
    {
        $colony = $this->colony(
            $this->factory(self::SCHEMATIC_BACTERIA),
            $this->factory(self::SCHEMATIC_BACTERIA),
            $this->extractor(self::BACTERIA, 43200, 50000, self::TOMORROW, 2),
        );

        $row = $this->rowOf($this->calculator->calculate($colony), self::BACTERIA);

        self::assertSame('Bacteria', $row['typeName']);
        $this->assertQuantity(100000, $row, 'extractedPerDay');
        $this->assertQuantity(288000, $row, 'demandedPerDay');
        $this->assertQuantity(-188000, $row, 'balancePerDay');
        $this->assertQuantity(100000 / 288000, $row, 'runRate');
        self::assertSame(2, $row['factoryCount']);
        self::assertSame(2, $row['headCount']);
        self::assertSame('deficit', $row['advice']['kind']);
        self::assertSame(4, $row['advice']['heads']);
        self::assertSame(2, $row['advice']['factories']);
    }

    public function testExtractionEqualToTheDemandIsBalancedAtAFullRunRate(): void
    {
        $colony = $this->colony(
            $this->factory(self::SCHEMATIC_REACTIVE_METALS),
            $this->factory(self::SCHEMATIC_REACTIVE_METALS),
            $this->extractor(self::REACTIVE_METALS, 43200, 144000, self::TOMORROW, 3),
        );

        $row = $this->rowOf($this->calculator->calculate($colony), self::REACTIVE_METALS);

        $this->assertQuantity(288000, $row, 'extractedPerDay');
        $this->assertQuantity(288000, $row, 'demandedPerDay');
        $this->assertQuantity(0, $row, 'balancePerDay');
        $this->assertQuantity(1, $row, 'runRate');
        self::assertSame('balanced', $row['advice']['kind']);
    }

    public function testExtractionBeyondTheDemandByLessThanOneFactoryIsStillBalanced(): void
    {
        $colony = $this->colony(
            $this->factory(self::SCHEMATIC_AQUEOUS_LIQUIDS),
            $this->factory(self::SCHEMATIC_AQUEOUS_LIQUIDS),
            $this->extractor(self::AQUEOUS_LIQUIDS, 86400, 300000, self::TOMORROW, 4),
        );

        $row = $this->rowOf($this->calculator->calculate($colony), self::AQUEOUS_LIQUIDS);

        $this->assertQuantity(12000, $row, 'balancePerDay');
        $this->assertQuantity(1, $row, 'runRate');
        self::assertSame('balanced', $row['advice']['kind']);
    }

    public function testExtractionBeyondTheDemandByOneFactoryAdvisesTheFactoryWhichStillHolds(): void
    {
        $colony = $this->colony(
            $this->factory(self::SCHEMATIC_PROTEINS),
            $this->factory(self::SCHEMATIC_PROTEINS),
            $this->extractor(self::PROTEINS, 86400, 450000, self::TOMORROW, 3),
        );

        $row = $this->rowOf($this->calculator->calculate($colony), self::PROTEINS);

        $this->assertQuantity(450000, $row, 'extractedPerDay');
        $this->assertQuantity(288000, $row, 'demandedPerDay');
        $this->assertQuantity(162000, $row, 'balancePerDay');
        $this->assertQuantity(1, $row, 'runRate');
        self::assertSame('surplus', $row['advice']['kind']);
        self::assertSame(1, $row['advice']['factories']);
        self::assertNull($row['advice']['heads']);
    }

    public function testRunRateNeverExceedsOneWhateverTheSurplus(): void
    {
        $colony = $this->colony(
            $this->factory(self::SCHEMATIC_MICROORGANISMS),
            $this->extractor(self::MICROORGANISMS, 86400, 1000000, self::TOMORROW, 5),
        );

        $row = $this->rowOf($this->calculator->calculate($colony), self::MICROORGANISMS);

        $this->assertQuantity(856000, $row, 'balancePerDay');
        $this->assertQuantity(1, $row, 'runRate');
        self::assertSame('surplus', $row['advice']['kind']);
        self::assertSame(5, $row['advice']['factories']);
    }

    public function testExtractionWithoutInstallationLeavesTheFiguresAndTheAdviceUnknownButKeepsTheDemand(): void
    {
        $colony = $this->colony(
            $this->factory(self::SCHEMATIC_BASE_METALS),
            $this->extractor(self::BASE_METALS, 43200, 50000, null, 2),
        );

        $row = $this->rowOf($this->calculator->calculate($colony), self::BASE_METALS);

        $this->assertNullField($row, 'extractedPerDay');
        $this->assertNullField($row, 'balancePerDay');
        $this->assertNullField($row, 'runRate');
        $this->assertNullField($row, 'advice');
        $this->assertQuantity(144000, $row, 'demandedPerDay');
        self::assertSame(1, $row['factoryCount']);
    }

    public function testExpiredOnlyExtractorExtractsNothingAndHasNoHeadToAdvise(): void
    {
        $colony = $this->colony(
            $this->factory(self::SCHEMATIC_MICROORGANISMS),
            $this->extractor(self::MICROORGANISMS, 43200, 50000, self::YESTERDAY, 3),
        );

        $row = $this->rowOf($this->calculator->calculate($colony), self::MICROORGANISMS);

        $this->assertQuantity(0, $row, 'extractedPerDay');
        $this->assertQuantity(-144000, $row, 'balancePerDay');
        $this->assertQuantity(0, $row, 'runRate');
        self::assertSame('deficit', $row['advice']['kind']);
        self::assertNull($row['advice']['heads']);
        self::assertSame(1, $row['advice']['factories']);
    }

    public function testExpiredExtractorOfTheSameTypeAddsNeitherItsExtractionNorItsHeads(): void
    {
        $colony = $this->colony(
            $this->factory(self::SCHEMATIC_PROTEINS),
            $this->extractor(self::PROTEINS, 28800, 30000, self::TOMORROW, 2),
            $this->extractor(self::PROTEINS, 43200, 50000, self::YESTERDAY, 5),
        );

        $row = $this->rowOf($this->calculator->calculate($colony), self::PROTEINS);

        $this->assertQuantity(90000, $row, 'extractedPerDay');
        $this->assertQuantity(-54000, $row, 'balancePerDay');
        $this->assertQuantity(0.625, $row, 'runRate');
        self::assertSame(2, $row['headCount']);
        self::assertSame(2, $row['advice']['heads']);
        self::assertSame(1, $row['advice']['factories']);
    }

    public function testUnknownHeadCountKeepsTheFactoryOptionOnly(): void
    {
        $colony = $this->colony(
            $this->factory(self::SCHEMATIC_BACTERIA),
            $this->factory(self::SCHEMATIC_BACTERIA),
            $this->extractor(self::BACTERIA, 43200, 72000, self::TOMORROW, null),
        );

        $row = $this->rowOf($this->calculator->calculate($colony), self::BACTERIA);

        $this->assertQuantity(144000, $row, 'extractedPerDay');
        $this->assertQuantity(0.5, $row, 'runRate');
        $this->assertNullField($row, 'headCount');
        self::assertSame('deficit', $row['advice']['kind']);
        self::assertNull($row['advice']['heads']);
        self::assertSame(1, $row['advice']['factories']);
    }

    public function testExtractorsOfTheSameTypeAreAddedUpWithTheirHeads(): void
    {
        $colony = $this->colony(
            $this->factory(self::SCHEMATIC_BACTERIA),
            $this->factory(self::SCHEMATIC_BACTERIA),
            $this->extractor(self::BACTERIA, 43200, 50000, self::TOMORROW, 2),
            $this->extractor(self::BACTERIA, 86400, 60000, self::TOMORROW, 3),
        );

        $rows = $this->calculator->calculate($colony);

        self::assertCount(1, $rows);
        $row = $this->rowOf($rows, self::BACTERIA);
        $this->assertQuantity(160000, $row, 'extractedPerDay');
        $this->assertQuantity(-128000, $row, 'balancePerDay');
        $this->assertQuantity(160000 / 288000, $row, 'runRate');
        self::assertSame(5, $row['headCount']);
        self::assertSame(4, $row['advice']['heads']);
        self::assertSame(1, $row['advice']['factories']);
    }

    public function testEachExtractedAndDemandedTypeGetsItsOwnRowAndAnUndemandedTypeGetsNone(): void
    {
        $colony = $this->colony(
            $this->factory(self::SCHEMATIC_BACTERIA),
            $this->factory(self::SCHEMATIC_REACTIVE_METALS),
            $this->factory(self::SCHEMATIC_REACTIVE_METALS),
            $this->factory(self::SCHEMATIC_REACTIVE_METALS),
            $this->extractor(self::BACTERIA, 43200, 50000, self::TOMORROW, 2),
            $this->extractor(self::REACTIVE_METALS, 21600, 108000, self::TOMORROW, 3),
            $this->extractor(self::PROTEINS, 86400, 200000, self::TOMORROW, 6),
        );

        $rows = $this->calculator->calculate($colony);

        self::assertCount(2, $rows);
        $bacteria = $this->rowOf($rows, self::BACTERIA);
        $this->assertQuantity(100000, $bacteria, 'extractedPerDay');
        $this->assertQuantity(144000, $bacteria, 'demandedPerDay');
        $this->assertQuantity(100000 / 144000, $bacteria, 'runRate');
        self::assertSame(1, $bacteria['factoryCount']);
        self::assertSame(2, $bacteria['headCount']);
        self::assertSame(1, $bacteria['advice']['heads']);
        self::assertSame(1, $bacteria['advice']['factories']);
        $reactiveMetals = $this->rowOf($rows, self::REACTIVE_METALS);
        $this->assertQuantity(432000, $reactiveMetals, 'extractedPerDay');
        $this->assertQuantity(432000, $reactiveMetals, 'demandedPerDay');
        $this->assertQuantity(1, $reactiveMetals, 'runRate');
        self::assertSame(3, $reactiveMetals['factoryCount']);
        self::assertSame(3, $reactiveMetals['headCount']);
        self::assertSame('balanced', $reactiveMetals['advice']['kind']);
    }

    public function testColonyWithoutFactoryHasNoBalance(): void
    {
        $colony = $this->colony($this->extractor(self::BACTERIA, 43200, 50000, self::TOMORROW, 2));

        self::assertSame([], $this->calculator->calculate($colony));
    }

    public function testColonyWithoutExtractorHasNoBalance(): void
    {
        $colony = $this->colony($this->factory(self::SCHEMATIC_BACTERIA));

        self::assertSame([], $this->calculator->calculate($colony));
    }

    public function testColonyWhoseExtractorAndFactoriesShareNoTypeHasNoBalance(): void
    {
        $colony = $this->colony(
            $this->factory(self::SCHEMATIC_BACTERIA),
            $this->extractor(self::PROTEINS, 43200, 50000, self::TOMORROW, 2),
        );

        self::assertSame([], $this->calculator->calculate($colony));
    }

    private function constantRateExtractorYield(): ExtractorYieldCalculator
    {
        $yield = $this->createStub(ExtractorYieldCalculator::class);
        $yield->method('programDailyAverage')->willReturnCallback(
            static fn (int $quantityPerCycle, int $cycleTime, ?\DateTimeImmutable $installTime, ?\DateTimeImmutable $expiryTime): ?float => $expiryTime === null
                ? null
                : $quantityPerCycle * (86400 / $cycleTime),
        );

        return $yield;
    }

    private function registerType(int $typeId, string $typeName, int $groupId, ?float $volume = null): void
    {
        $group = (new InvGroup())->setGroupId($groupId)->setGroupName('Group '.$groupId);
        $this->sdeTypes[$typeId] = (new InvType())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setGroup($group)
            ->setVolume($volume);
    }

    private function defineBasicSchematic(int $schematicId, int $inputTypeId, int $outputTypeId): void
    {
        $this->schematicMap[$schematicId] = [
            'inputs' => [$inputTypeId => 3000],
            'output' => ['typeId' => $outputTypeId, 'quantity' => 20],
        ];
        $this->schematics[$schematicId] = (new PlanetSchematic())
            ->setSchematicId($schematicId)
            ->setSchematicName('Schematic '.$schematicId)
            ->setCycleTime(1800);
    }

    private function colony(PlanetaryPin ...$pins): PlanetaryColony
    {
        $character = (new Character())
            ->setEveCharacterId(91_387_001)
            ->setName('Ilyra Castell');
        $colony = (new PlanetaryColony())
            ->setCharacter($character)
            ->setPlanetId(40_138_701)
            ->setPlanetName('Aldranette IV')
            ->setPlanetType('barren')
            ->setSolarSystemName('Aldranette')
            ->setLastUpdate(new \DateTimeImmutable(self::NOW));
        (new \ReflectionProperty(PlanetaryColony::class, 'id'))->setValue($colony, Uuid::v4());
        foreach ($pins as $pin) {
            $colony->addPin($pin);
        }

        return $colony;
    }

    private function factory(int $schematicId): PlanetaryPin
    {
        return (new PlanetaryPin())
            ->setPinId($this->nextFactoryPin++)
            ->setTypeId(self::FACTORY_TYPE_ID)
            ->setSchematicId($schematicId);
    }

    private function extractor(int $productTypeId, int $cycleTime, int $quantityPerCycle, ?string $expiryTime, ?int $numHeads): PlanetaryPin
    {
        return (new PlanetaryPin())
            ->setPinId($this->nextExtractorPin++)
            ->setTypeId(self::EXTRACTOR_TYPE_ID)
            ->setExtractorProductTypeId($productTypeId)
            ->setExtractorCycleTime($cycleTime)
            ->setExtractorQtyPerCycle($quantityPerCycle)
            ->setExtractorNumHeads($numHeads)
            ->setInstallTime($expiryTime === null ? null : (new \DateTimeImmutable($expiryTime))->modify('-24 hours'))
            ->setExpiryTime($expiryTime === null ? null : new \DateTimeImmutable($expiryTime));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function rowOf(array $rows, int $typeId): array
    {
        $byType = array_column($rows, null, 'typeId');
        self::assertArrayHasKey($typeId, $byType);

        return $byType[$typeId];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertQuantity(int|float $expected, array $row, string $key): void
    {
        self::assertArrayHasKey($key, $row);
        self::assertIsNumeric($row[$key]);
        self::assertEqualsWithDelta($expected, $row[$key], 0.0001);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertNullField(array $row, string $key): void
    {
        self::assertArrayHasKey($key, $row);
        self::assertNull($row[$key]);
    }
}
