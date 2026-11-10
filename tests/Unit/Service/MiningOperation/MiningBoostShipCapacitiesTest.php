<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\Sde\DgmTypeAttribute;
use App\Enum\MiningBoostShip;
use App\Exception\BoostShipCapacityMissingFromSdeException;
use App\Industry\Infrastructure\Sde\DogmaAttributeValues;
use App\Repository\Sde\DgmTypeAttributeRepository;
use App\Service\MiningOperation\MiningBoostShipCapacities;
use App\Service\MiningOperation\MiningBoostShipCapacity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningBoostShipCapacities::class)]
#[CoversClass(MiningBoostShipCapacity::class)]
#[CoversClass(BoostShipCapacityMissingFromSdeException::class)]
#[Group('issue-312')]
final class MiningBoostShipCapacitiesTest extends TestCase
{
    private const int FLEET_HANGAR_CAPACITY = 912;
    private const int GENERAL_MINING_HOLD_CAPACITY = 1556;

    public function testPorpoiseCapacitiesAreReadFromTheSdeAttributes(): void
    {
        $capacities = $this->capacitiesOf([
            MiningBoostShip::Porpoise->value => [self::FLEET_HANGAR_CAPACITY => 5_000, self::GENERAL_MINING_HOLD_CAPACITY => 50_000],
        ]);

        $capacity = $capacities->of(MiningBoostShip::Porpoise);

        self::assertSame([5_000.0, 50_000.0], [$capacity->hangarM3, $capacity->holdM3]);
    }

    public function testOrcaCapacitiesStoredAsFloatsAreRead(): void
    {
        $capacities = $this->capacitiesOf([
            MiningBoostShip::Porpoise->value => [self::FLEET_HANGAR_CAPACITY => 5_000, self::GENERAL_MINING_HOLD_CAPACITY => 50_000],
            MiningBoostShip::Orca->value => [self::FLEET_HANGAR_CAPACITY => 40_000.5, self::GENERAL_MINING_HOLD_CAPACITY => 150_000.25],
        ]);

        $capacity = $capacities->of(MiningBoostShip::Orca);

        self::assertSame([40_000.5, 150_000.25], [$capacity->hangarM3, $capacity->holdM3]);
    }

    public function testMissingHangarCapacityIsAnExplicitError(): void
    {
        $capacities = $this->capacitiesOf([MiningBoostShip::Rorqual->value => [self::GENERAL_MINING_HOLD_CAPACITY => 300_000]]);

        $this->expectException(BoostShipCapacityMissingFromSdeException::class);
        $this->expectExceptionMessage('Attribute 912 of the Rorqual (type 28352) is missing from the SDE');

        $capacities->of(MiningBoostShip::Rorqual);
    }

    public function testMissingHoldCapacityIsAnExplicitError(): void
    {
        $capacities = $this->capacitiesOf([MiningBoostShip::Orca->value => [self::FLEET_HANGAR_CAPACITY => 40_000]]);

        $this->expectException(BoostShipCapacityMissingFromSdeException::class);
        $this->expectExceptionMessage('Attribute 1556 of the Orca (type 28606) is missing from the SDE');

        $capacities->of(MiningBoostShip::Orca);
    }

    public function testShipAbsentFromTheSdeIsAnExplicitError(): void
    {
        $capacities = $this->capacitiesOf([]);

        $this->expectException(BoostShipCapacityMissingFromSdeException::class);

        $capacities->of(MiningBoostShip::Porpoise);
    }

    /**
     * @param array<int, array<int, int|float>> $valueByAttributeIdByTypeId
     */
    private function capacitiesOf(array $valueByAttributeIdByTypeId): MiningBoostShipCapacities
    {
        $repository = $this->createStub(DgmTypeAttributeRepository::class);
        $repository->method('findBy')->willReturnCallback(
            static function (array $criteria) use ($valueByAttributeIdByTypeId): array {
                $attributes = [];
                foreach ($valueByAttributeIdByTypeId[$criteria['typeId']] ?? [] as $attributeId => $value) {
                    if (!in_array($attributeId, $criteria['attributeId'], true)) {
                        continue;
                    }
                    $attribute = (new DgmTypeAttribute())->setTypeId($criteria['typeId'])->setAttributeId($attributeId);
                    \is_int($value) ? $attribute->setValueInt($value) : $attribute->setValueFloat($value);
                    $attributes[] = $attribute;
                }

                return $attributes;
            },
        );

        return new MiningBoostShipCapacities(new DogmaAttributeValues($repository));
    }
}
