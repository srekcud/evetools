<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary;

use App\Exception\PlanetaryTierMissingFromSdeException;
use App\Exception\PlanetSchematicIncompleteException;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\Planetary\PlanetaryProductionCalculator;
use App\Service\Planetary\PlanetaryProductTypeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetaryProductTypeResolver::class)]
#[Group('issue-1404')]
final class PlanetaryProductTypeResolverTest extends TestCase
{
    private const int AQUEOUS_LIQUIDS = 91_001;
    private const int BASE_METALS = 91_002;
    private const int WATER = 91_011;
    private const int REACTIVE_METALS = 91_012;
    private const int COOLANT = 91_021;
    private const int MECHANICAL_PARTS = 91_022;
    private const int ROBOTICS = 91_031;
    private const int BROADCAST_NODE = 91_041;

    public function testTheOutputsOfTheP2P3AndP4SchematicsComeBackInAscendingOrderWithoutTheP1(): void
    {
        $resolver = $this->resolverOver(
            [
                301 => $this->schematic([self::WATER => 40, self::REACTIVE_METALS => 40], self::COOLANT, 5),
                302 => $this->schematic([self::AQUEOUS_LIQUIDS => 3000], self::WATER, 20),
                303 => $this->schematic([self::COOLANT => 10, self::MECHANICAL_PARTS => 10], self::ROBOTICS, 3),
                304 => $this->schematic([self::ROBOTICS => 6], self::BROADCAST_NODE, 1),
                305 => $this->schematic([self::WATER => 20, self::BASE_METALS => 90], self::MECHANICAL_PARTS, 6),
            ],
            [self::WATER => 'P1', self::COOLANT => 'P2', self::MECHANICAL_PARTS => 'P2', self::ROBOTICS => 'P3', self::BROADCAST_NODE => 'P4'],
        );

        $this->assertSame([self::COOLANT, self::MECHANICAL_PARTS, self::ROBOTICS, self::BROADCAST_NODE], $resolver->factoryOutputTypeIds());
    }

    public function testAnOutputCitedBySeveralSchematicsAppearsOnce(): void
    {
        $resolver = $this->resolverOver(
            [
                301 => $this->schematic([self::WATER => 40, self::REACTIVE_METALS => 40], self::COOLANT, 5),
                302 => $this->schematic([self::WATER => 30, self::REACTIVE_METALS => 70], self::COOLANT, 4),
            ],
            [self::COOLANT => 'P2'],
        );

        $this->assertSame([self::COOLANT], $resolver->factoryOutputTypeIds());
    }

    public function testWithoutAnyP2P3OrP4SchematicTheListIsEmpty(): void
    {
        $resolver = $this->resolverOver(
            [302 => $this->schematic([self::AQUEOUS_LIQUIDS => 3000], self::WATER, 20)],
            [self::WATER => 'P1'],
        );

        $this->assertSame([], $resolver->factoryOutputTypeIds());
        $this->assertSame([], $this->resolverOver([], [])->factoryOutputTypeIds());
    }

    public function testASchematicWithoutOutputIsReportedAsIncomplete(): void
    {
        $resolver = $this->resolverOver(
            [
                301 => $this->schematic([self::WATER => 40, self::REACTIVE_METALS => 40], self::COOLANT, 5),
                306 => ['inputs' => [self::WATER => 40], 'output' => null],
            ],
            [self::COOLANT => 'P2'],
        );

        $this->expectException(PlanetSchematicIncompleteException::class);
        $this->expectExceptionMessage('Planet schematic 306 is incomplete in the SDE');

        $resolver->factoryOutputTypeIds();
    }

    public function testAnOutputWhoseTierIsMissingFromTheSdeIsReported(): void
    {
        $resolver = $this->resolverOver(
            [301 => $this->schematic([self::WATER => 40, self::REACTIVE_METALS => 40], self::COOLANT, 5)],
            [],
        );

        $this->expectException(PlanetaryTierMissingFromSdeException::class);
        $this->expectExceptionMessage('No PI tier found in the SDE market groups for type ' . self::COOLANT);

        $resolver->factoryOutputTypeIds();
    }

    private function schematic(array $inputs, int $outputTypeId, int $outputQuantity): array
    {
        return ['inputs' => $inputs, 'output' => ['typeId' => $outputTypeId, 'quantity' => $outputQuantity]];
    }

    private function resolverOver(array $schematicMap, array $tierByTypeId): PlanetaryProductTypeResolver
    {
        $schematicTypeRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $schematicTypeRepository->method('getSchematicMap')->willReturn($schematicMap);
        $productionCalculator = $this->createStub(PlanetaryProductionCalculator::class);
        $productionCalculator->method('findProductTier')->willReturnCallback(
            static fn (int $typeId): ?string => $tierByTypeId[$typeId] ?? null,
        );

        return new PlanetaryProductTypeResolver($schematicTypeRepository, $productionCalculator);
    }
}
