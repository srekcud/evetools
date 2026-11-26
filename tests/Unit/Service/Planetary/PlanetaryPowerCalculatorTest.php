<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary;

use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\PlanetaryRoute;
use App\Entity\Sde\DgmTypeAttribute;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Repository\Sde\DgmTypeAttributeRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Planetary\PlanetaryPowerCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('issue-1385')]
#[CoversClass(PlanetaryPowerCalculator::class)]
final class PlanetaryPowerCalculatorTest extends TestCase
{
    private const int COMMAND_CENTER_GROUP = 1027;
    private const int FACTORY_GROUP = 1028;
    private const int STORAGE_GROUP = 1029;
    private const int LAUNCHPAD_GROUP = 1030;
    private const int EXTRACTOR_CONTROL_UNIT_GROUP = 1063;

    private const int POWER_OUTPUT = 11;
    private const int POWER_LOAD = 15;
    private const int CPU_OUTPUT = 48;
    private const int CPU_LOAD = 49;
    private const int EXTRACTOR_HEAD_CPU = 1690;
    private const int EXTRACTOR_HEAD_POWER = 1691;

    private const int BARREN_COMMAND_CENTER = 2524;
    private const int LIMITED_BARREN_COMMAND_CENTER = 2129;
    private const int STANDARD_BARREN_COMMAND_CENTER = 2130;
    private const int IMPROVED_BARREN_COMMAND_CENTER = 2131;
    private const int ADVANCED_BARREN_COMMAND_CENTER = 2132;
    private const int ELITE_BARREN_COMMAND_CENTER = 2133;
    private const int ICE_COMMAND_CENTER = 2533;
    private const int BARREN_LAUNCHPAD = 2544;
    private const int BARREN_BASIC_INDUSTRY_FACILITY = 2473;
    private const int BARREN_STORAGE_FACILITY = 2541;
    private const int BARREN_EXTRACTOR_CONTROL_UNIT = 2848;
    private const int ICE_EXTRACTOR_CONTROL_UNIT = 3061;

    private const int MISSING_TYPE = 999999;

    private array $sdeTypes = [];

    private int $nextPinId = 1;

    protected function setUp(): void
    {
        $this->sdeTypes = [
            self::BARREN_COMMAND_CENTER => $this->sdeType('Barren Command Center', self::COMMAND_CENTER_GROUP, [self::CPU_OUTPUT => 1675, self::POWER_OUTPUT => 6000]),
            self::LIMITED_BARREN_COMMAND_CENTER => $this->sdeType('Limited Barren Command Center', self::COMMAND_CENTER_GROUP, [self::CPU_OUTPUT => 7057, self::POWER_OUTPUT => 9000]),
            self::STANDARD_BARREN_COMMAND_CENTER => $this->sdeType('Standard Barren Command Center', self::COMMAND_CENTER_GROUP, [self::CPU_OUTPUT => 12136, self::POWER_OUTPUT => 12000]),
            self::IMPROVED_BARREN_COMMAND_CENTER => $this->sdeType('Improved Barren Command Center', self::COMMAND_CENTER_GROUP, [self::CPU_OUTPUT => 17215, self::POWER_OUTPUT => 15000]),
            self::ADVANCED_BARREN_COMMAND_CENTER => $this->sdeType('Advanced Barren Command Center', self::COMMAND_CENTER_GROUP, [self::CPU_OUTPUT => 21315, self::POWER_OUTPUT => 17000]),
            self::ELITE_BARREN_COMMAND_CENTER => $this->sdeType('Elite Barren Command Center', self::COMMAND_CENTER_GROUP, [self::CPU_OUTPUT => 25415, self::POWER_OUTPUT => 19000]),
            self::ICE_COMMAND_CENTER => $this->sdeType('Ice Command Center', self::COMMAND_CENTER_GROUP, [self::CPU_OUTPUT => 1675, self::POWER_OUTPUT => 6000]),
            self::BARREN_LAUNCHPAD => $this->sdeType('Barren Launchpad', self::LAUNCHPAD_GROUP, [self::CPU_LOAD => 3600, self::POWER_LOAD => 700]),
            self::BARREN_BASIC_INDUSTRY_FACILITY => $this->sdeType('Barren Basic Industry Facility', self::FACTORY_GROUP, [self::CPU_LOAD => 200, self::POWER_LOAD => 800]),
            self::BARREN_STORAGE_FACILITY => $this->sdeType('Barren Storage Facility', self::STORAGE_GROUP, [self::CPU_LOAD => 500, self::POWER_LOAD => 700]),
            self::BARREN_EXTRACTOR_CONTROL_UNIT => $this->sdeType('Barren Extractor Control Unit', self::EXTRACTOR_CONTROL_UNIT_GROUP, [self::CPU_LOAD => 400, self::POWER_LOAD => 2600, self::EXTRACTOR_HEAD_CPU => 110, self::EXTRACTOR_HEAD_POWER => 550]),
            self::ICE_EXTRACTOR_CONTROL_UNIT => $this->sdeType('Ice Extractor Control Unit', self::EXTRACTOR_CONTROL_UNIT_GROUP, [self::CPU_LOAD => 400, self::POWER_LOAD => 2600, self::EXTRACTOR_HEAD_CPU => 110, self::EXTRACTOR_HEAD_POWER => 550]),
        ];
        $this->nextPinId = 1;
    }

    public function testLevelZeroOutputIsTheBaseCommandCenterTypeOfThePin(): void
    {
        $colony = $this->colony(0, $this->pin(self::ICE_COMMAND_CENTER));

        self::assertSame(['cpu' => 1675, 'powergrid' => 6000], $this->calculator()->outputs($colony));
    }

    public function testLevelFiveOutputIsTheEliteTypeNamedAfterTheBaseType(): void
    {
        $colony = $this->colony(5, $this->pin(self::BARREN_COMMAND_CENTER));

        self::assertSame(['cpu' => 25415, 'powergrid' => 19000], $this->calculator()->outputs($colony));
    }

    #[DataProvider('intermediateLevelOutputs')]
    public function testIntermediateLevelOutputIsTheTypeCarryingTheLevelPrefix(int $upgradeLevel, array $expectedOutputs): void
    {
        $colony = $this->colony($upgradeLevel, $this->pin(self::BARREN_COMMAND_CENTER));

        self::assertSame($expectedOutputs, $this->calculator()->outputs($colony));
    }

    public static function intermediateLevelOutputs(): iterable
    {
        yield 'level 1 is Limited' => [1, ['cpu' => 7057, 'powergrid' => 9000]];
        yield 'level 2 is Standard' => [2, ['cpu' => 12136, 'powergrid' => 12000]];
        yield 'level 3 is Improved' => [3, ['cpu' => 17215, 'powergrid' => 15000]];
        yield 'level 4 is Advanced' => [4, ['cpu' => 21315, 'powergrid' => 17000]];
    }

    public function testLevelTypeAbsentFromTheSdeMakesTheOutputUnknown(): void
    {
        unset($this->sdeTypes[self::ELITE_BARREN_COMMAND_CENTER]);
        $colony = $this->colony(5, $this->pin(self::BARREN_COMMAND_CENTER));

        self::assertNull($this->calculator()->outputs($colony));
    }

    public function testCommandCenterTypeAbsentFromTheSdeMakesTheOutputUnknown(): void
    {
        $colony = $this->colony(0, $this->pin(self::MISSING_TYPE));

        self::assertNull($this->calculator()->outputs($colony));
    }

    public function testMissingPowerOutputAttributeMakesTheOutputUnknown(): void
    {
        unset($this->sdeTypes[self::ELITE_BARREN_COMMAND_CENTER]['attributes'][self::POWER_OUTPUT]);
        $colony = $this->colony(5, $this->pin(self::BARREN_COMMAND_CENTER));

        self::assertNull($this->calculator()->outputs($colony));
    }

    public function testMissingCpuOutputAttributeMakesTheOutputUnknown(): void
    {
        unset($this->sdeTypes[self::BARREN_COMMAND_CENTER]['attributes'][self::CPU_OUTPUT]);
        $colony = $this->colony(0, $this->pin(self::BARREN_COMMAND_CENTER));

        self::assertNull($this->calculator()->outputs($colony));
    }

    public function testColonyWithoutCommandCenterPinHasAnUnknownOutput(): void
    {
        $colony = $this->colony(5, $this->pin(self::BARREN_LAUNCHPAD));

        self::assertNull($this->calculator()->outputs($colony));
    }

    public function testBarrenColonyAtLevelFiveWithLaunchpadAndTwoFactoriesLoadsFourThousandCpuAndTwoThousandThreeHundredPowergrid(): void
    {
        $colony = $this->colony(
            5,
            $this->pin(self::BARREN_COMMAND_CENTER),
            $this->pin(self::BARREN_LAUNCHPAD),
            $this->pin(self::BARREN_BASIC_INDUSTRY_FACILITY),
            $this->pin(self::BARREN_BASIC_INDUSTRY_FACILITY),
        );

        self::assertSame(['cpu' => 4000, 'powergrid' => 2300], $this->calculator()->load($colony));
    }

    public function testIceColonyWithAFiveHeadExtractorLoadsNineHundredFiftyCpuAndFiveThousandThreeHundredFiftyPowergrid(): void
    {
        $colony = $this->colony(
            0,
            $this->pin(self::ICE_COMMAND_CENTER),
            $this->extractorPin(self::ICE_EXTRACTOR_CONTROL_UNIT, 5),
        );

        self::assertSame(['cpu' => 950, 'powergrid' => 5350], $this->calculator()->load($colony));
    }

    public function testEachExtractorAddsItsOwnHeadCountToTheLoad(): void
    {
        $colony = $this->colony(
            4,
            $this->pin(self::BARREN_COMMAND_CENTER),
            $this->extractorPin(self::BARREN_EXTRACTOR_CONTROL_UNIT, 3),
            $this->extractorPin(self::BARREN_EXTRACTOR_CONTROL_UNIT, 8),
            $this->pin(self::BARREN_STORAGE_FACILITY),
        );

        self::assertSame(['cpu' => 2510, 'powergrid' => 11950], $this->calculator()->load($colony));
    }

    public function testColonyWithOnlyItsCommandCenterLoadsNothing(): void
    {
        $colony = $this->colony(2, $this->pin(self::BARREN_COMMAND_CENTER));

        self::assertSame(['cpu' => 0, 'powergrid' => 0], $this->calculator()->load($colony));
    }

    public function testRoutesDoNotEnterTheLoad(): void
    {
        $colony = $this->colony(
            5,
            $this->pin(self::BARREN_COMMAND_CENTER),
            $this->pin(self::BARREN_LAUNCHPAD),
            $this->pin(self::BARREN_BASIC_INDUSTRY_FACILITY),
        );
        $colony->addRoute($this->route(501, 11, 12));
        $colony->addRoute($this->route(502, 12, 11));

        self::assertSame(['cpu' => 3800, 'powergrid' => 1500], $this->calculator()->load($colony));
    }

    public function testPinTypeWithoutCpuLoadMakesTheLoadUnknown(): void
    {
        unset($this->sdeTypes[self::BARREN_BASIC_INDUSTRY_FACILITY]['attributes'][self::CPU_LOAD]);
        $colony = $this->colony(
            5,
            $this->pin(self::BARREN_COMMAND_CENTER),
            $this->pin(self::BARREN_LAUNCHPAD),
            $this->pin(self::BARREN_BASIC_INDUSTRY_FACILITY),
        );

        self::assertNull($this->calculator()->load($colony));
    }

    public function testPinTypeWithoutPowerLoadMakesTheLoadUnknown(): void
    {
        unset($this->sdeTypes[self::BARREN_LAUNCHPAD]['attributes'][self::POWER_LOAD]);
        $colony = $this->colony(
            5,
            $this->pin(self::BARREN_COMMAND_CENTER),
            $this->pin(self::BARREN_LAUNCHPAD),
        );

        self::assertNull($this->calculator()->load($colony));
    }

    public function testExtractorWithoutHeadCountMakesTheLoadUnknown(): void
    {
        $colony = $this->colony(
            0,
            $this->pin(self::ICE_COMMAND_CENTER),
            $this->pin(self::ICE_EXTRACTOR_CONTROL_UNIT)->setExtractorProductTypeId(2268),
        );

        self::assertNull($this->calculator()->load($colony));
    }

    public function testPinTypeAbsentFromTheSdeMakesTheLoadUnknown(): void
    {
        $colony = $this->colony(
            5,
            $this->pin(self::BARREN_COMMAND_CENTER),
            $this->pin(self::MISSING_TYPE),
        );

        self::assertNull($this->calculator()->load($colony));
    }

    public function testResetForgetsTheSdeValuesAlreadyRead(): void
    {
        $calculator = $this->calculator();
        $colony = $this->colony(5, $this->pin(self::BARREN_COMMAND_CENTER));
        self::assertSame(['cpu' => 25415, 'powergrid' => 19000], $calculator->outputs($colony));

        $this->sdeTypes[self::ELITE_BARREN_COMMAND_CENTER]['attributes'][self::CPU_OUTPUT] = 26000;
        $calculator->reset();

        self::assertSame(['cpu' => 26000, 'powergrid' => 19000], $calculator->outputs($colony));
    }

    private function calculator(): PlanetaryPowerCalculator
    {
        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('find')->willReturnCallback(fn (mixed $typeId): ?InvType => $this->invType($typeId));
        $invTypeRepository->method('findByTypeId')->willReturnCallback(fn (int $typeId): ?InvType => $this->invType($typeId));
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
        $invTypeRepository->method('findOneByName')->willReturnCallback(fn (string $name): ?InvType => $this->invTypeNamed($name));
        $invTypeRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?InvType => isset($criteria['typeName']) ? $this->invTypeNamed($criteria['typeName']) : (isset($criteria['typeId']) ? $this->invType($criteria['typeId']) : null),
        );
        $invTypeRepository->method('findByNames')->willReturnCallback(function (array $names): array {
            $types = [];
            foreach ($names as $name) {
                $type = $this->invTypeNamed($name);
                if ($type !== null) {
                    $types[$name] = $type;
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

    private function invTypeNamed(string $name): ?InvType
    {
        foreach ($this->sdeTypes as $typeId => $definition) {
            if (mb_strtolower($definition['name']) === mb_strtolower($name)) {
                return $this->invType($typeId);
            }
        }

        return null;
    }

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

    private function colony(int $upgradeLevel, PlanetaryPin ...$pins): PlanetaryColony
    {
        $colony = (new PlanetaryColony())->setUpgradeLevel($upgradeLevel)->setNumPins(\count($pins));
        foreach ($pins as $pin) {
            $colony->addPin($pin);
        }

        return $colony;
    }

    private function pin(int $typeId): PlanetaryPin
    {
        return (new PlanetaryPin())->setPinId($this->nextPinId++)->setTypeId($typeId);
    }

    private function extractorPin(int $typeId, int $heads): PlanetaryPin
    {
        return $this->pin($typeId)->setExtractorProductTypeId(2268)->setExtractorNumHeads($heads);
    }

    private function route(int $routeId, int $sourcePinId, int $destinationPinId): PlanetaryRoute
    {
        return (new PlanetaryRoute())
            ->setRouteId($routeId)
            ->setSourcePinId($sourcePinId)
            ->setDestinationPinId($destinationPinId)
            ->setContentTypeId(2393)
            ->setQuantity(1200.0);
    }
}
