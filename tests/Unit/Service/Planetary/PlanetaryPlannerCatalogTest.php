<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary;

use App\Entity\Sde\InvMarketGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\PlanetSchematic;
use App\Exception\PlanetaryTierMissingFromSdeException;
use App\Exception\PlanetSchematicIncompleteException;
use App\Exception\TypeMissingFromSdeException;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\PlanetSchematicRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\JitaMarketService;
use App\Service\Planetary\PlanetaryPlannerCatalog;
use App\Service\Planetary\PlanetaryProductionCalculator;
use App\Service\TypeNameResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetaryPlannerCatalog::class)]
#[Group('issue-1402')]
final class PlanetaryPlannerCatalogTest extends TestCase
{
    private const int AQUEOUS_LIQUIDS = 91_001;
    private const int BASE_METALS = 91_002;
    private const int WATER = 91_011;
    private const int REACTIVE_METALS = 91_012;
    private const int COOLANT = 91_021;
    private const int ROBOTICS = 91_031;
    private const int RAW_MATERIALS_MARKET_GROUP_ID = 1333;

    public function testEachSchematicBecomesOneRecipeSortedByOutputWithItsInputsSortedByType(): void
    {
        $catalog = $this->catalogOver($this->schematics(), $this->schematicMap(), $this->types(), $this->sellPrices(), $this->buyPrices());

        $this->assertSame([
            ['outputTypeId' => self::WATER, 'outputQuantity' => 20, 'cycleSeconds' => 1800, 'inputs' => [['typeId' => self::AQUEOUS_LIQUIDS, 'quantity' => 3000]]],
            ['outputTypeId' => self::REACTIVE_METALS, 'outputQuantity' => 20, 'cycleSeconds' => 1800, 'inputs' => [['typeId' => self::BASE_METALS, 'quantity' => 3000]]],
            ['outputTypeId' => self::COOLANT, 'outputQuantity' => 5, 'cycleSeconds' => 3600, 'inputs' => [['typeId' => self::WATER, 'quantity' => 40], ['typeId' => self::REACTIVE_METALS, 'quantity' => 40]]],
            ['outputTypeId' => self::ROBOTICS, 'outputQuantity' => 3, 'cycleSeconds' => 7200, 'inputs' => [['typeId' => self::COOLANT, 'quantity' => 10]]],
        ], $catalog->build()['recipes']);
    }

    public function testEachTypeOfARecipeCarriesItsNameItsTierAndItsVolumeSortedByType(): void
    {
        $catalog = $this->catalogOver($this->schematics(), $this->schematicMap(), $this->types(), $this->sellPrices(), $this->buyPrices());

        $this->assertSame([
            ['typeId' => self::AQUEOUS_LIQUIDS, 'name' => 'Aqueous Liquids', 'tier' => 'P0', 'volume' => 0.01],
            ['typeId' => self::BASE_METALS, 'name' => 'Base Metals', 'tier' => 'P0', 'volume' => null],
            ['typeId' => self::WATER, 'name' => 'Water', 'tier' => 'P1', 'volume' => 0.19],
            ['typeId' => self::REACTIVE_METALS, 'name' => 'Reactive Metals', 'tier' => 'P1', 'volume' => 0.38],
            ['typeId' => self::COOLANT, 'name' => 'Coolant', 'tier' => 'P2', 'volume' => 0.75],
            ['typeId' => self::ROBOTICS, 'name' => 'Robotics', 'tier' => 'P3', 'volume' => 1.5],
        ], $catalog->build()['types']);
    }

    public function testThereIsOnePriceLineByTypeInTheOrderOfTheTypesAndAMissingPriceStaysNull(): void
    {
        $catalog = $this->catalogOver($this->schematics(), $this->schematicMap(), $this->types(), $this->sellPrices(), $this->buyPrices());

        $this->assertSame([
            ['typeId' => self::AQUEOUS_LIQUIDS, 'sell' => 6.0, 'buy' => 5.0],
            ['typeId' => self::BASE_METALS, 'sell' => null, 'buy' => null],
            ['typeId' => self::WATER, 'sell' => 60.0, 'buy' => 50.0],
            ['typeId' => self::REACTIVE_METALS, 'sell' => null, 'buy' => 120.0],
            ['typeId' => self::COOLANT, 'sell' => 1000.0, 'buy' => 900.0],
            ['typeId' => self::ROBOTICS, 'sell' => 30000.0, 'buy' => 28000.0],
        ], $catalog->build()['prices']);
    }

    public function testAnEmptySdeGivesThreeEmptyLists(): void
    {
        $catalog = $this->catalogOver([], [], [], [], []);

        $this->assertSame(['recipes' => [], 'types' => [], 'prices' => []], $catalog->build());
    }

    public function testAnOutputTypeWithoutAPiMarketGroupRaisesAnExplicitErrorInsteadOfFallingBackToP0(): void
    {
        $types = $this->types();
        $types[self::COOLANT]['marketGroups'] = [self::RAW_MATERIALS_MARKET_GROUP_ID];
        $catalog = $this->catalogOver($this->schematics(), $this->schematicMap(), $types, $this->sellPrices(), $this->buyPrices());

        $this->expectException(PlanetaryTierMissingFromSdeException::class);
        $this->expectExceptionMessage((string) self::COOLANT);

        $catalog->build();
    }

    public function testATypeCitedByARecipeAndAbsentFromTheSdeRaisesTheMissingTypeError(): void
    {
        $types = $this->types();
        unset($types[self::BASE_METALS]);
        $catalog = $this->catalogOver($this->schematics(), $this->schematicMap(), $types, $this->sellPrices(), $this->buyPrices());

        try {
            $catalog->build();
            $this->fail('A type missing from the SDE must raise an error');
        } catch (TypeMissingFromSdeException $exception) {
            $this->assertSame([self::BASE_METALS], $exception->getMissingTypeIds());
        }
    }

    public static function incompleteSchematics(): iterable
    {
        $complete = ['inputs' => [self::WATER => 40, self::REACTIVE_METALS => 40], 'output' => ['typeId' => self::COOLANT, 'quantity' => 5]];
        $cycles = [203 => 3600];

        yield 'without output' => [[203 => ['inputs' => $complete['inputs'], 'output' => null]], $cycles];
        yield 'without inputs' => [[203 => ['inputs' => [], 'output' => $complete['output']]], $cycles];
        yield 'without any type line' => [[], $cycles];
        yield 'with a zero cycle time' => [[203 => $complete], [203 => 0]];
        yield 'with a negative cycle time' => [[203 => $complete], [203 => -1800]];
    }

    #[DataProvider('incompleteSchematics')]
    public function testAnIncompleteSchematicRaisesAnExplicitErrorNamingIt(array $schematicMap, array $cycleTimes): void
    {
        $catalog = $this->catalogOver($cycleTimes, $schematicMap, $this->types(), $this->sellPrices(), $this->buyPrices());

        $this->expectException(PlanetSchematicIncompleteException::class);
        $this->expectExceptionMessage('203');

        $catalog->build();
    }

    public function testASchematicOfTheMapWithoutItsSdeRowRaisesTheIncompleteError(): void
    {
        $catalog = $this->catalogOver([], $this->schematicMap(), $this->types(), $this->sellPrices(), $this->buyPrices());

        $this->expectException(PlanetSchematicIncompleteException::class);

        $catalog->build();
    }

    private function schematics(): array
    {
        return [201 => 1800, 202 => 1800, 203 => 3600, 204 => 7200];
    }

    private function schematicMap(): array
    {
        return [
            204 => ['inputs' => [self::COOLANT => 10], 'output' => ['typeId' => self::ROBOTICS, 'quantity' => 3]],
            202 => ['inputs' => [self::BASE_METALS => 3000], 'output' => ['typeId' => self::REACTIVE_METALS, 'quantity' => 20]],
            203 => ['inputs' => [self::REACTIVE_METALS => 40, self::WATER => 40], 'output' => ['typeId' => self::COOLANT, 'quantity' => 5]],
            201 => ['inputs' => [self::AQUEOUS_LIQUIDS => 3000], 'output' => ['typeId' => self::WATER, 'quantity' => 20]],
        ];
    }

    private function types(): array
    {
        return [
            self::ROBOTICS => ['name' => 'Robotics', 'volume' => 1.5, 'marketGroups' => [2810, 1336]],
            self::AQUEOUS_LIQUIDS => ['name' => 'Aqueous Liquids', 'volume' => 0.01, 'marketGroups' => [self::RAW_MATERIALS_MARKET_GROUP_ID]],
            self::BASE_METALS => ['name' => 'Base Metals', 'volume' => null, 'marketGroups' => [self::RAW_MATERIALS_MARKET_GROUP_ID]],
            self::WATER => ['name' => 'Water', 'volume' => 0.19, 'marketGroups' => [1334]],
            self::REACTIVE_METALS => ['name' => 'Reactive Metals', 'volume' => 0.38, 'marketGroups' => [2801, 1334]],
            self::COOLANT => ['name' => 'Coolant', 'volume' => 0.75, 'marketGroups' => [1335]],
        ];
    }

    private function sellPrices(): array
    {
        return [self::AQUEOUS_LIQUIDS => 6.0, self::BASE_METALS => null, self::WATER => 60.0, self::REACTIVE_METALS => null, self::COOLANT => 1000.0, self::ROBOTICS => 30000.0];
    }

    private function buyPrices(): array
    {
        return [self::AQUEOUS_LIQUIDS => 5.0, self::BASE_METALS => null, self::WATER => 50.0, self::REACTIVE_METALS => 120.0, self::COOLANT => 900.0, self::ROBOTICS => 28000.0];
    }

    private function catalogOver(array $cycleTimes, array $schematicMap, array $types, array $sellPrices, array $buyPrices): PlanetaryPlannerCatalog
    {
        $schematicRepository = $this->createStub(PlanetSchematicRepository::class);
        $schematicRepository->method('findAll')->willReturn(array_map(
            static fn (int $schematicId, int $cycleTime): PlanetSchematic => (new PlanetSchematic())->setSchematicId($schematicId)->setCycleTime($cycleTime),
            array_keys($cycleTimes),
            array_values($cycleTimes),
        ));
        $schematicTypeRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $schematicTypeRepository->method('getSchematicMap')->willReturn($schematicMap);

        $invTypes = [];
        foreach ($types as $typeId => $definition) {
            $invTypes[$typeId] = (new InvType())->setTypeId($typeId)->setTypeName($definition['name'])->setVolume($definition['volume'])->setMarketGroup($this->marketGroupChain($definition['marketGroups']));
        }
        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('findByTypeIds')->willReturnCallback(
            static fn (array $typeIds): array => array_intersect_key($invTypes, array_flip($typeIds)),
        );
        $invTypeRepository->method('find')->willReturnCallback(static fn (mixed $typeId): ?InvType => $invTypes[$typeId] ?? null);

        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getPrices')->willReturnCallback(
            static fn (array $typeIds): array => array_combine($typeIds, array_map(static fn (int $typeId): ?float => $sellPrices[$typeId] ?? null, $typeIds)),
        );
        $jitaMarketService->method('getBuyPrices')->willReturnCallback(
            static fn (array $typeIds): array => array_combine($typeIds, array_map(static fn (int $typeId): ?float => $buyPrices[$typeId] ?? null, $typeIds)),
        );

        return new PlanetaryPlannerCatalog(
            $schematicRepository,
            $schematicTypeRepository,
            $invTypeRepository,
            new PlanetaryProductionCalculator($schematicRepository, $schematicTypeRepository, $invTypeRepository, $this->createStub(TypeNameResolver::class), $jitaMarketService),
            $jitaMarketService,
        );
    }

    private function marketGroupChain(array $marketGroupIds): InvMarketGroup
    {
        $parent = null;
        foreach (array_reverse($marketGroupIds) as $marketGroupId) {
            $group = (new InvMarketGroup())->setMarketGroupId($marketGroupId)->setParentGroup($parent);
            $parent = $group;
        }

        return $parent;
    }
}
