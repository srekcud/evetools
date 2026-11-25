<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary;

use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\User;
use App\Repository\PlanetaryColonyRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\Planetary\PlanetaryProductTypes;
use App\Service\TypeNameResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetaryProductTypes::class)]
#[Group('issue-1401')]
final class PlanetaryProductTypesTest extends TestCase
{
    private const int WATER = 3645;
    private const int COOLANT = 9832;
    private const int ROBOTICS = 9848;
    private const int AQUEOUS_LIQUIDS = 2268;
    private const int SCHEMATIC_WATER = 121;
    private const int SCHEMATIC_COOLANT = 66;
    private const int SCHEMATIC_ROBOTICS = 100;
    private const int SCHEMATIC_BROKEN = 999;

    private const array SCHEMATIC_MAP = [
        self::SCHEMATIC_WATER => ['inputs' => [self::AQUEOUS_LIQUIDS => 3000], 'output' => ['typeId' => self::WATER, 'quantity' => 20]],
        self::SCHEMATIC_COOLANT => ['inputs' => [self::WATER => 40], 'output' => ['typeId' => self::COOLANT, 'quantity' => 5]],
        self::SCHEMATIC_ROBOTICS => ['inputs' => [self::COOLANT => 10], 'output' => ['typeId' => self::ROBOTICS, 'quantity' => 3]],
        self::SCHEMATIC_BROKEN => ['inputs' => [self::WATER => 40], 'output' => null],
    ];

    private const array NAMES = [self::WATER => 'Water', self::COOLANT => 'Coolant', self::ROBOTICS => 'Robotics'];

    private int $nextPinId = 1;

    public function testTheOutputOfEveryFactoryOfTheColoniesIsAProductWithItsName(): void
    {
        $colonies = [
            $this->colony($this->factoryPin(self::SCHEMATIC_WATER), $this->factoryPin(self::SCHEMATIC_COOLANT)),
            $this->colony($this->factoryPin(self::SCHEMATIC_ROBOTICS)),
        ];

        self::assertSame(
            [self::WATER => 'Water', self::COOLANT => 'Coolant', self::ROBOTICS => 'Robotics'],
            $this->productTypes($colonies),
        );
    }

    public function testATypeProducedByTwoColoniesAppearsOnce(): void
    {
        $colonies = [$this->colony($this->factoryPin(self::SCHEMATIC_COOLANT)), $this->colony($this->factoryPin(self::SCHEMATIC_COOLANT))];

        self::assertSame([self::COOLANT => 'Coolant'], $this->productTypes($colonies));
    }

    public function testAnExtractorAloneAndAnEmptyColonyProduceNoProduct(): void
    {
        $extractorOnly = $this->colony($this->extractorPin());

        self::assertSame([], $this->productTypes([$extractorOnly, $this->colony()]));
    }

    public function testASchematicWithoutOutputGivesNoProduct(): void
    {
        self::assertSame([], $this->productTypes([$this->colony($this->factoryPin(self::SCHEMATIC_BROKEN))]));
    }

    public function testAUserWithoutColonyHasNoProduct(): void
    {
        self::assertSame([], $this->productTypes([]));
    }

    /**
     * @param list<PlanetaryColony> $colonies
     *
     * @return array<int, string>
     */
    private function productTypes(array $colonies): array
    {
        $colonyRepository = $this->createStub(PlanetaryColonyRepository::class);
        $colonyRepository->method('findByUser')->willReturn($colonies);
        $schematicTypeRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $schematicTypeRepository->method('getSchematicMap')->willReturn(self::SCHEMATIC_MAP);
        $names = $this->createStub(TypeNameResolver::class);
        $names->method('findNames')->willReturnCallback(
            static fn (array $typeIds): array => array_intersect_key(self::NAMES, array_flip($typeIds)),
        );

        return (new PlanetaryProductTypes($colonyRepository, $schematicTypeRepository, $names))->of(new User());
    }

    private function colony(PlanetaryPin ...$pins): PlanetaryColony
    {
        $colony = (new PlanetaryColony())->setCharacter(new Character());
        foreach ($pins as $pin) {
            $colony->addPin($pin);
        }

        return $colony;
    }

    private function extractorPin(): PlanetaryPin
    {
        $pin = new PlanetaryPin();
        $pin->setPinId($this->nextPinId++);
        $pin->setTypeId(2848);
        $pin->setExtractorProductTypeId(self::AQUEOUS_LIQUIDS);
        $pin->setExtractorCycleTime(1800);
        $pin->setExtractorQtyPerCycle(3000);
        $pin->setExpiryTime(new \DateTimeImmutable('+2 days'));

        return $pin;
    }

    private function factoryPin(int $schematicId): PlanetaryPin
    {
        $pin = new PlanetaryPin();
        $pin->setPinId($this->nextPinId++);
        $pin->setTypeId(2473);
        $pin->setSchematicId($schematicId);

        return $pin;
    }
}
