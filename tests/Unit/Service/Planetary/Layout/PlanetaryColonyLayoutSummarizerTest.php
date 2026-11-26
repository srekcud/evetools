<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary\Layout;

use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Planetary\Layout\PlanetaryColonyLayoutSummarizer;
use App\Service\Planetary\PlanetaryPowerCalculator;
use App\Service\Planetary\PlanetaryProductionCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('issue-933')]
#[CoversClass(PlanetaryColonyLayoutSummarizer::class)]
final class PlanetaryColonyLayoutSummarizerTest extends TestCase
{
    private const int PLANET_GROUP = 7;
    private const int COMMAND_CENTER_GROUP = 1027;
    private const int PROCESSOR_GROUP = 1028;
    private const int STORAGE_GROUP = 1029;
    private const int LAUNCHPAD_GROUP = 1030;
    private const int RAW_RESOURCE_GROUP = 1032;
    private const int REFINED_COMMODITY_GROUP = 1034;
    private const int SPECIALIZED_COMMODITY_GROUP = 1035;
    private const int EXTRACTOR_CONTROL_UNIT_GROUP = 1063;

    private const int BARREN_PLANET = 2016;
    private const int BARREN_COMMAND_CENTER = 2524;
    private const int BARREN_LAUNCHPAD = 2544;
    private const int BARREN_ADVANCED_FACILITY = 2474;
    private const int BARREN_HIGH_TECH_PLANT = 2475;
    private const int BARREN_STORAGE_FACILITY = 2541;
    private const int BARREN_EXTRACTOR_CONTROL_UNIT = 2848;
    private const int FACILITY_WITHOUT_ATTRIBUTES = 2476;
    private const int AQUEOUS_LIQUIDS = 2268;
    private const int MICRO_ORGANISMS = 2073;
    private const int COOLANT = 9832;
    private const int ROBOTICS = 9848;
    private const int MISSING_TYPE = 999999;

    private const int EXTRACTOR_HEAD_CPU = 120;
    private const int EXTRACTOR_HEAD_POWERGRID = 560;

    private const string COMMAND_CENTER_UPGRADES = 'Command Center Upgrades';

    public function testTheSpecExampleIsSummarizedWithItsCountsLoadProductAndSkill(): void
    {
        $template = $this->template(
            pins: [
                $this->pin(self::BARREN_LAUNCHPAD),
                $this->pin(self::BARREN_ADVANCED_FACILITY, self::COOLANT),
                $this->pin(self::BARREN_ADVANCED_FACILITY, self::COOLANT),
            ],
            linkCount: 2,
            routeCount: 2,
        );

        self::assertSame([
            'planetTypeId' => self::BARREN_PLANET,
            'planetTypeName' => 'Barren',
            'commandCenterLevel' => 5,
            'buildings' => ['extractorControlUnits' => 0, 'extractorHeads' => 0, 'processors' => 2, 'storages' => 0, 'launchpads' => 1],
            'links' => 2,
            'routes' => 2,
            'load' => ['cpu' => 4600, 'powergrid' => 2100],
            'products' => [['typeId' => self::COOLANT, 'typeName' => 'Coolant', 'tier' => 'P2']],
            'extracted' => [],
            'skills' => [['name' => self::COMMAND_CENTER_UPGRADES, 'level' => 5]],
        ], $this->summarizer()->summarize($template));
    }

    public function testTheLoadAddsTheHeadsOfEachExtractorControlUnitAndLeavesTheCommandCenterOut(): void
    {
        $template = $this->template(
            pins: [
                $this->pin(self::BARREN_LAUNCHPAD),
                $this->pin(self::BARREN_STORAGE_FACILITY),
                $this->pin(self::BARREN_EXTRACTOR_CONTROL_UNIT, self::MICRO_ORGANISMS, 4),
                $this->pin(self::BARREN_EXTRACTOR_CONTROL_UNIT, self::AQUEOUS_LIQUIDS, 6),
                $this->pin(self::BARREN_ADVANCED_FACILITY, self::COOLANT),
                $this->pin(self::BARREN_HIGH_TECH_PLANT, self::ROBOTICS),
                $this->pin(self::BARREN_ADVANCED_FACILITY, self::COOLANT),
                $this->pin(self::BARREN_COMMAND_CENTER),
            ],
            linkCount: 4,
            routeCount: 3,
        );

        $summary = $this->summarizer()->summarize($template);

        self::assertSame(
            [
                'load' => ['cpu' => 8720, 'powergrid' => 14200],
                'buildings' => ['extractorControlUnits' => 2, 'extractorHeads' => 10, 'processors' => 3, 'storages' => 1, 'launchpads' => 1],
                'links' => 4,
                'routes' => 3,
            ],
            array_intersect_key($summary, array_flip(['load', 'buildings', 'links', 'routes'])),
        );
    }

    public function testProductsAreTheDistinctProcessorOutputsWithTheirTierAndExtractedAreTheDistinctRawResources(): void
    {
        $template = $this->template(
            pins: [
                $this->pin(self::BARREN_EXTRACTOR_CONTROL_UNIT, self::MICRO_ORGANISMS, 2),
                $this->pin(self::BARREN_EXTRACTOR_CONTROL_UNIT, self::AQUEOUS_LIQUIDS, 3),
                $this->pin(self::BARREN_EXTRACTOR_CONTROL_UNIT, self::MICRO_ORGANISMS, 5),
                $this->pin(self::BARREN_ADVANCED_FACILITY, self::COOLANT),
                $this->pin(self::BARREN_HIGH_TECH_PLANT, self::ROBOTICS),
                $this->pin(self::BARREN_ADVANCED_FACILITY, self::COOLANT),
            ],
        );

        $summary = $this->summarizer()->summarize($template);

        self::assertSame([
            'products' => [
                ['typeId' => self::COOLANT, 'typeName' => 'Coolant', 'tier' => 'P2'],
                ['typeId' => self::ROBOTICS, 'typeName' => 'Robotics', 'tier' => 'P3'],
            ],
            'extracted' => [
                ['typeId' => self::MICRO_ORGANISMS, 'typeName' => 'Micro-Organisms'],
                ['typeId' => self::AQUEOUS_LIQUIDS, 'typeName' => 'Aqueous Liquids'],
            ],
        ], array_intersect_key($summary, array_flip(['products', 'extracted'])));
    }

    public function testTheLoadIsNullAndNeverZeroWhenOneBuildingHasAnUnknownLoad(): void
    {
        $template = $this->template(
            pins: [
                $this->pin(self::BARREN_LAUNCHPAD),
                $this->pin(self::BARREN_ADVANCED_FACILITY, self::COOLANT),
                $this->pin(self::FACILITY_WITHOUT_ATTRIBUTES, self::ROBOTICS),
            ],
        );

        $summary = $this->summarizer()->summarize($template);

        self::assertSame(
            [
                'buildings' => ['extractorControlUnits' => 0, 'extractorHeads' => 0, 'processors' => 2, 'storages' => 0, 'launchpads' => 1],
                'load' => null,
            ],
            array_intersect_key($summary, array_flip(['buildings', 'load'])),
        );
    }

    public function testANameAbsentFromTheSdeIsNullAndTheTierOfAnUnknownProductIsNull(): void
    {
        $template = $this->template(
            pins: [
                $this->pin(self::BARREN_EXTRACTOR_CONTROL_UNIT, self::MISSING_TYPE, 1),
                $this->pin(self::BARREN_ADVANCED_FACILITY, self::MISSING_TYPE + 1),
            ],
            planetTypeId: self::MISSING_TYPE + 2,
        );

        $summary = $this->summarizer()->summarize($template);

        self::assertSame([
            'planetTypeId' => self::MISSING_TYPE + 2,
            'planetTypeName' => null,
            'products' => [['typeId' => self::MISSING_TYPE + 1, 'typeName' => null, 'tier' => null]],
            'extracted' => [['typeId' => self::MISSING_TYPE, 'typeName' => null]],
        ], array_intersect_key($summary, array_flip(['planetTypeId', 'planetTypeName', 'products', 'extracted'])));
    }

    public function testTheCommandCenterUpgradesSkillIsListedOnlyForAPositiveLevel(): void
    {
        $pins = [$this->pin(self::BARREN_ADVANCED_FACILITY, self::COOLANT)];

        $levelZero = $this->summarizer()->summarize($this->template(pins: $pins, commandCenterLevel: 0));
        $levelThree = $this->summarizer()->summarize($this->template(pins: $pins, commandCenterLevel: 3));

        self::assertSame(['commandCenterLevel' => 0, 'skills' => []], array_intersect_key($levelZero, array_flip(['commandCenterLevel', 'skills'])));
        self::assertSame(
            ['commandCenterLevel' => 3, 'skills' => [['name' => self::COMMAND_CENTER_UPGRADES, 'level' => 3]]],
            array_intersect_key($levelThree, array_flip(['commandCenterLevel', 'skills'])),
        );
    }

    /**
     * @return array{T: int, S: int|null, La: float, Lo: float, H: int}
     */
    private function pin(int $typeId, ?int $productTypeId = null, int $heads = 0): array
    {
        return ['T' => $typeId, 'S' => $productTypeId, 'La' => 0.5, 'Lo' => 0.25, 'H' => $heads];
    }

    /**
     * @param list<array<string, mixed>> $pins
     *
     * @return array<string, mixed>
     */
    private function template(array $pins, int $commandCenterLevel = 5, int $linkCount = 0, int $routeCount = 0, int $planetTypeId = self::BARREN_PLANET): array
    {
        return [
            'CmdCtrLv' => $commandCenterLevel,
            'Cmt' => 'Summary fixture',
            'Diam' => 0.5,
            'L' => array_fill(0, $linkCount, ['D' => 2, 'Lv' => 0, 'S' => 1]),
            'P' => $pins,
            'Pln' => $planetTypeId,
            'R' => array_fill(0, $routeCount, ['P' => [2, 1], 'Q' => 40, 'T' => self::COOLANT]),
        ];
    }

    private function summarizer(): PlanetaryColonyLayoutSummarizer
    {
        $sdeTypes = [
            self::BARREN_PLANET => ['Barren', self::PLANET_GROUP],
            self::BARREN_COMMAND_CENTER => ['Barren Command Center', self::COMMAND_CENTER_GROUP],
            self::BARREN_LAUNCHPAD => ['Barren Launchpad', self::LAUNCHPAD_GROUP],
            self::BARREN_ADVANCED_FACILITY => ['Barren Advanced Industry Facility', self::PROCESSOR_GROUP],
            self::BARREN_HIGH_TECH_PLANT => ['Barren High-Tech Production Plant', self::PROCESSOR_GROUP],
            self::BARREN_STORAGE_FACILITY => ['Barren Storage Facility', self::STORAGE_GROUP],
            self::FACILITY_WITHOUT_ATTRIBUTES => ['Barren Facility Without Attributes', self::PROCESSOR_GROUP],
            self::BARREN_EXTRACTOR_CONTROL_UNIT => ['Barren Extractor Control Unit', self::EXTRACTOR_CONTROL_UNIT_GROUP],
            self::AQUEOUS_LIQUIDS => ['Aqueous Liquids', self::RAW_RESOURCE_GROUP],
            self::MICRO_ORGANISMS => ['Micro-Organisms', self::RAW_RESOURCE_GROUP],
            self::COOLANT => ['Coolant', self::REFINED_COMMODITY_GROUP],
            self::ROBOTICS => ['Robotics', self::SPECIALIZED_COMMODITY_GROUP],
        ];
        $invTypes = [];
        foreach ($sdeTypes as $typeId => [$name, $groupId]) {
            $invTypes[$typeId] = (new InvType())
                ->setTypeId($typeId)
                ->setTypeName($name)
                ->setGroup((new InvGroup())->setGroupId($groupId));
        }

        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('find')->willReturnCallback(static fn (mixed $typeId): ?InvType => $invTypes[$typeId] ?? null);
        $invTypeRepository->method('findByTypeId')->willReturnCallback(static fn (int $typeId): ?InvType => $invTypes[$typeId] ?? null);
        $invTypeRepository->method('findByTypeIds')->willReturnCallback(
            static fn (array $typeIds): array => array_intersect_key($invTypes, array_flip($typeIds)),
        );

        $loads = [
            self::BARREN_LAUNCHPAD => ['cpu' => 3600, 'powergrid' => 700],
            self::BARREN_ADVANCED_FACILITY => ['cpu' => 500, 'powergrid' => 700],
            self::BARREN_HIGH_TECH_PLANT => ['cpu' => 1100, 'powergrid' => 400],
            self::BARREN_STORAGE_FACILITY => ['cpu' => 1000, 'powergrid' => 800],
            self::BARREN_COMMAND_CENTER => ['cpu' => 7777, 'powergrid' => 8888],
        ];
        $powerCalculator = $this->createStub(PlanetaryPowerCalculator::class);
        $powerCalculator->method('loadOfInstallation')->willReturnCallback(
            static function (int $typeId, ?int $extractorHeads) use ($loads): ?array {
                if ($typeId === self::BARREN_EXTRACTOR_CONTROL_UNIT) {
                    return $extractorHeads === null ? null : [
                        'cpu' => 410 + $extractorHeads * self::EXTRACTOR_HEAD_CPU,
                        'powergrid' => 2650 + $extractorHeads * self::EXTRACTOR_HEAD_POWERGRID,
                    ];
                }

                return $loads[$typeId] ?? null;
            },
        );

        $tiers = [self::COOLANT => 'P2', self::ROBOTICS => 'P3'];
        $productionCalculator = $this->createStub(PlanetaryProductionCalculator::class);
        $productionCalculator->method('findProductTier')->willReturnCallback(static fn (int $typeId): ?string => $tiers[$typeId] ?? null);

        return new PlanetaryColonyLayoutSummarizer($invTypeRepository, $powerCalculator, $productionCalculator);
    }
}
