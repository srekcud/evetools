<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary;

use App\Entity\Sde\DgmTypeAttribute;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Repository\Sde\DgmTypeAttributeRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Planetary\PlanetaryPowerCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('issue-933')]
#[CoversClass(PlanetaryPowerCalculator::class)]
final class PlanetaryPowerCalculatorLoadOfInstallationTest extends TestCase
{
    private const int PROCESSOR_GROUP = 1028;
    private const int LAUNCHPAD_GROUP = 1030;
    private const int EXTRACTOR_CONTROL_UNIT_GROUP = 1063;

    private const int POWER_LOAD = 15;
    private const int CPU_LOAD = 49;
    private const int EXTRACTOR_HEAD_CPU = 1690;
    private const int EXTRACTOR_HEAD_POWER = 1691;

    private const int BARREN_LAUNCHPAD = 2544;
    private const int BARREN_ADVANCED_FACILITY = 2474;
    private const int BARREN_EXTRACTOR_CONTROL_UNIT = 2848;
    private const int ICE_EXTRACTOR_CONTROL_UNIT = 3061;
    private const int FACILITY_WITHOUT_CPU = 2475;
    private const int FACILITY_WITHOUT_POWERGRID = 2476;
    private const int MISSING_TYPE = 999999;

    /** @var array<int, array{name: string, group: int, attributes: array<int, int|float>}> */
    private array $sdeTypes = [];

    protected function setUp(): void
    {
        $this->sdeTypes = [
            self::BARREN_LAUNCHPAD => $this->sdeType('Barren Launchpad', self::LAUNCHPAD_GROUP, [self::CPU_LOAD => 3600, self::POWER_LOAD => 700]),
            self::BARREN_ADVANCED_FACILITY => $this->sdeType('Barren Advanced Industry Facility', self::PROCESSOR_GROUP, [self::CPU_LOAD => 500, self::POWER_LOAD => 700]),
            self::BARREN_EXTRACTOR_CONTROL_UNIT => $this->sdeType('Barren Extractor Control Unit', self::EXTRACTOR_CONTROL_UNIT_GROUP, [
                self::CPU_LOAD => 410,
                self::POWER_LOAD => 2650,
                self::EXTRACTOR_HEAD_CPU => 120,
                self::EXTRACTOR_HEAD_POWER => 560,
            ]),
            self::ICE_EXTRACTOR_CONTROL_UNIT => $this->sdeType('Ice Extractor Control Unit', self::EXTRACTOR_CONTROL_UNIT_GROUP, [
                self::CPU_LOAD => 430,
                self::POWER_LOAD => 2700,
            ]),
            self::FACILITY_WITHOUT_CPU => $this->sdeType('Facility Without CPU', self::PROCESSOR_GROUP, [self::POWER_LOAD => 700]),
            self::FACILITY_WITHOUT_POWERGRID => $this->sdeType('Facility Without Powergrid', self::PROCESSOR_GROUP, [self::CPU_LOAD => 500]),
        ];
    }

    public function testAnOrdinaryBuildingLoadsItsCpuAndPowergridAttributes(): void
    {
        self::assertSame(['cpu' => 3600, 'powergrid' => 700], $this->calculator()->loadOfInstallation(self::BARREN_LAUNCHPAD, null));
        self::assertSame(['cpu' => 500, 'powergrid' => 700], $this->calculator()->loadOfInstallation(self::BARREN_ADVANCED_FACILITY, null));
    }

    public function testAnOrdinaryBuildingIgnoresTheHeadsPassedToIt(): void
    {
        self::assertSame(['cpu' => 3600, 'powergrid' => 700], $this->calculator()->loadOfInstallation(self::BARREN_LAUNCHPAD, 4));
    }

    public function testAnExtractorControlUnitAddsItsHeadsTimesTheHeadAttributes(): void
    {
        self::assertSame(['cpu' => 890, 'powergrid' => 4890], $this->calculator()->loadOfInstallation(self::BARREN_EXTRACTOR_CONTROL_UNIT, 4));
    }

    public function testAnExtractorControlUnitWithoutHeadsHasAnUnknownLoad(): void
    {
        self::assertNull($this->calculator()->loadOfInstallation(self::BARREN_EXTRACTOR_CONTROL_UNIT, null));
    }

    public function testAnExtractorControlUnitWithoutHeadAttributesHasAnUnknownLoad(): void
    {
        self::assertNull($this->calculator()->loadOfInstallation(self::ICE_EXTRACTOR_CONTROL_UNIT, 3));
    }

    public function testABuildingWithoutCpuLoadAttributeHasAnUnknownLoad(): void
    {
        self::assertNull($this->calculator()->loadOfInstallation(self::FACILITY_WITHOUT_CPU, null));
    }

    public function testABuildingWithoutPowergridLoadAttributeHasAnUnknownLoad(): void
    {
        self::assertNull($this->calculator()->loadOfInstallation(self::FACILITY_WITHOUT_POWERGRID, null));
    }

    public function testATypeAbsentFromTheSdeHasAnUnknownLoad(): void
    {
        self::assertNull($this->calculator()->loadOfInstallation(self::MISSING_TYPE, null));
    }

    private function calculator(): PlanetaryPowerCalculator
    {
        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('find')->willReturnCallback(fn (mixed $typeId): ?InvType => $this->invType($typeId));
        $invTypeRepository->method('findByTypeId')->willReturnCallback(fn (int $typeId): ?InvType => $this->invType($typeId));
        $invTypeRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?InvType => isset($criteria['typeId']) ? $this->invType($criteria['typeId']) : null,
        );
        $invTypeRepository->method('findByTypeIds')->willReturnCallback(function (array $typeIds): array {
            $types = [];
            foreach ($typeIds as $typeId) {
                $type = $this->invType($typeId);
                if ($type !== null) {
                    $types[$typeId] = $type;
                }
            }

            return $types;
        });

        $attributeRepository = $this->createStub(DgmTypeAttributeRepository::class);
        $attributeRepository->method('findByTypeId')->willReturnCallback(fn (int $typeId): array => $this->typeAttributes($typeId, null));
        $attributeRepository->method('findBy')->willReturnCallback(
            fn (array $criteria): array => $this->typeAttributes($criteria['typeId'], isset($criteria['attributeId']) ? (array) $criteria['attributeId'] : null),
        );
        $attributeRepository->method('findOneBy')->willReturnCallback(function (array $criteria): ?DgmTypeAttribute {
            $found = $this->typeAttributes($criteria['typeId'], isset($criteria['attributeId']) ? (array) $criteria['attributeId'] : null);

            return $found[0] ?? null;
        });

        return new PlanetaryPowerCalculator($attributeRepository, $invTypeRepository);
    }

    /**
     * @param array<int, int|float> $attributes
     *
     * @return array{name: string, group: int, attributes: array<int, int|float>}
     */
    private function sdeType(string $name, int $groupId, array $attributes): array
    {
        return ['name' => $name, 'group' => $groupId, 'attributes' => $attributes];
    }

    private function invType(mixed $typeId): ?InvType
    {
        if (!isset($this->sdeTypes[$typeId])) {
            return null;
        }

        return (new InvType())
            ->setTypeId($typeId)
            ->setTypeName($this->sdeTypes[$typeId]['name'])
            ->setPublished(true)
            ->setGroup((new InvGroup())->setGroupId($this->sdeTypes[$typeId]['group']));
    }

    /**
     * @param list<int>|null $attributeIds
     *
     * @return list<DgmTypeAttribute>
     */
    private function typeAttributes(int $typeId, ?array $attributeIds): array
    {
        $attributes = [];
        foreach ($this->sdeTypes[$typeId]['attributes'] ?? [] as $attributeId => $value) {
            if ($attributeIds !== null && !\in_array($attributeId, $attributeIds, true)) {
                continue;
            }
            $attributes[] = (new DgmTypeAttribute())->setTypeId($typeId)->setAttributeId($attributeId)->setValueInt($value);
        }

        return $attributes;
    }
}
