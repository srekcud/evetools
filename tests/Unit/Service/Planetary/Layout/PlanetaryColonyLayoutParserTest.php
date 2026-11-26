<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary\Layout;

use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Exception\InvalidPlanetaryColonyLayoutException;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\Planetary\Layout\PlanetaryColonyLayoutParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('issue-933')]
#[CoversClass(PlanetaryColonyLayoutParser::class)]
final class PlanetaryColonyLayoutParserTest extends TestCase
{
    private const int PLANET_GROUP = 7;
    private const int STORAGE_GROUP = 1029;
    private const int PROCESSOR_GROUP = 1028;
    private const int LAUNCHPAD_GROUP = 1030;
    private const int RAW_RESOURCE_GROUP = 1032;
    private const int BASIC_COMMODITY_GROUP = 1033;
    private const int REFINED_COMMODITY_GROUP = 1034;
    private const int EXTRACTOR_CONTROL_UNIT_GROUP = 1063;

    private const int BARREN_PLANET = 2016;
    private const int BARREN_LAUNCHPAD = 2544;
    private const int BARREN_ADVANCED_FACILITY = 2474;
    private const int BARREN_STORAGE_FACILITY = 2541;
    private const int BARREN_EXTRACTOR_CONTROL_UNIT = 2848;
    private const int AQUEOUS_LIQUIDS = 2268;
    private const int WATER = 3645;
    private const int COOLANT = 9832;
    private const int COOLANT_SCHEMATIC = 121;
    private const int MISSING_TYPE = 999999;

    private const int TEMPLATE_BYTE_LIMIT = 65_536;
    private const int PIN_LIMIT = 200;

    private const string NOMINAL_LAYOUT = <<<'JSON'
        {
          "Zoom": 3,
          "R": [
            {"T": 3645, "Q": 40, "P": [2, 1], "Flow": "fast"},
            {"P": [3, 1], "Q": 40, "T": 3645}
          ],
          "Pln": 2016,
          "P": [
            {"T": 2544, "S": null, "La": 1.25, "Lo": -0.5, "H": 0, "Uid": 77},
            {"T": 2474, "S": 9832, "La": 1.5, "Lo": -0.25, "H": 0},
            {"S": 9832, "T": 2474, "Lo": 0.25, "La": 1.75, "H": 0}
          ],
          "L": [
            {"S": 1, "D": 2, "Lv": 0, "Width": 4},
            {"D": 3, "S": 1, "Lv": 0}
          ],
          "Diam": 0.5,
          "Cmt": "Coolant starter",
          "CmdCtrLv": 5
        }
        JSON;

    public function testTheNominalColonyLayoutIsNormalizedToTheKnownKeysInTheGameOrder(): void
    {
        $normalized = $this->parser()->parse(self::NOMINAL_LAYOUT);

        self::assertSame(['CmdCtrLv', 'Cmt', 'Diam', 'L', 'P', 'Pln', 'R'], array_keys($normalized));
        self::assertSame([
            'CmdCtrLv' => 5,
            'Cmt' => 'Coolant starter',
            'Diam' => 0.5,
            'L' => [
                ['D' => 2, 'Lv' => 0, 'S' => 1],
                ['D' => 3, 'Lv' => 0, 'S' => 1],
            ],
            'P' => [
                ['H' => 0, 'La' => 1.25, 'Lo' => -0.5, 'S' => null, 'T' => self::BARREN_LAUNCHPAD],
                ['H' => 0, 'La' => 1.5, 'Lo' => -0.25, 'S' => self::COOLANT, 'T' => self::BARREN_ADVANCED_FACILITY],
                ['H' => 0, 'La' => 1.75, 'Lo' => 0.25, 'S' => self::COOLANT, 'T' => self::BARREN_ADVANCED_FACILITY],
            ],
            'Pln' => self::BARREN_PLANET,
            'R' => [
                ['P' => [2, 1], 'Q' => 40, 'T' => self::WATER],
                ['P' => [3, 1], 'Q' => 40, 'T' => self::WATER],
            ],
        ], $normalized);
    }

    public function testAnExtractorControlUnitWithTenHeadsAndAStorageFacilityAreAccepted(): void
    {
        $json = $this->mutated(static function (array $layout): array {
            $layout['P'][] = ['T' => self::BARREN_EXTRACTOR_CONTROL_UNIT, 'S' => self::AQUEOUS_LIQUIDS, 'La' => 0.75, 'Lo' => 0.5, 'H' => 10];
            $layout['P'][] = ['T' => self::BARREN_STORAGE_FACILITY, 'S' => null, 'La' => 0.8, 'Lo' => 0.6, 'H' => 0];

            return $layout;
        });

        $normalized = $this->parser()->parse($json);

        self::assertArrayHasKey('P', $normalized);
        self::assertCount(5, $normalized['P']);
        self::assertSame(['H' => 10, 'La' => 0.75, 'Lo' => 0.5, 'S' => self::AQUEOUS_LIQUIDS, 'T' => self::BARREN_EXTRACTOR_CONTROL_UNIT], $normalized['P'][3]);
        self::assertSame(['H' => 0, 'La' => 0.8, 'Lo' => 0.6, 'S' => null, 'T' => self::BARREN_STORAGE_FACILITY], $normalized['P'][4]);
    }

    public function testALayoutWithAnExtractorControlUnitAndNoProcessorIsNotEmpty(): void
    {
        $json = $this->mutated(static function (array $layout): array {
            $layout['P'] = [
                ['T' => self::BARREN_LAUNCHPAD, 'S' => null, 'La' => 1.25, 'Lo' => -0.5, 'H' => 0],
                ['T' => self::BARREN_EXTRACTOR_CONTROL_UNIT, 'S' => self::AQUEOUS_LIQUIDS, 'La' => 0.75, 'Lo' => 0.5, 'H' => 4],
            ];
            $layout['L'] = [['S' => 1, 'D' => 2, 'Lv' => 0]];
            $layout['R'] = [['P' => [2, 1], 'Q' => 600, 'T' => self::AQUEOUS_LIQUIDS]];

            return $layout;
        });

        $normalized = $this->parser()->parse($json);

        self::assertArrayHasKey('P', $normalized);
        self::assertCount(2, $normalized['P']);
    }

    public function testALayoutOfExactlyTheByteLimitIsAccepted(): void
    {
        $json = $this->paddedTo(self::TEMPLATE_BYTE_LIMIT);
        self::assertSame(self::TEMPLATE_BYTE_LIMIT, \strlen($json));

        $normalized = $this->parser()->parse($json);

        self::assertArrayHasKey('Pln', $normalized);
        self::assertSame(self::BARREN_PLANET, $normalized['Pln']);
    }

    public function testALayoutOneByteOverTheLimitIsRefusedAsTooLarge(): void
    {
        $json = $this->paddedTo(self::TEMPLATE_BYTE_LIMIT + 1);
        self::assertSame(self::TEMPLATE_BYTE_LIMIT + 1, \strlen($json));

        self::assertSame([['path' => '$', 'code' => 'too_large']], $this->refusedErrors($json));
    }

    #[DataProvider('unreadableTexts')]
    public function testAnUnreadableOrNonObjectTextIsASingleInvalidJsonError(string $text): void
    {
        self::assertSame([['path' => '$', 'code' => 'invalid_json']], $this->refusedErrors($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableTexts(): iterable
    {
        yield 'a truncated object' => ['{"CmdCtrLv": 5, "P": ['];
        yield 'plain text' => ['coolant starter'];
        yield 'a list of pins' => ['[{"T": 2544}]'];
        yield 'a number' => ['42'];
        yield 'an empty text' => [''];
    }

    public function testAPinOfATypeAbsentFromTheSdeIsAnUnknownInstallation(): void
    {
        $json = $this->mutated(static function (array $layout): array {
            $layout['P'][0]['T'] = self::MISSING_TYPE;

            return $layout;
        });

        self::assertSame([['path' => 'P[0].T', 'code' => 'unknown_installation']], $this->refusedErrors($json));
    }

    public function testAPinWhoseTypeIsNotAnInstallationGroupIsAnUnknownInstallation(): void
    {
        $json = $this->mutated(static function (array $layout): array {
            $layout['P'][0]['T'] = self::COOLANT;

            return $layout;
        });

        self::assertSame([['path' => 'P[0].T', 'code' => 'unknown_installation']], $this->refusedErrors($json));
    }

    public function testAProcessorWhoseProductIsNotTheOutputOfAnySchematicIsAnUnknownProduct(): void
    {
        $json = $this->mutated(static function (array $layout): array {
            $layout['P'][2]['S'] = self::WATER;

            return $layout;
        });

        self::assertSame([['path' => 'P[2].S', 'code' => 'unknown_product']], $this->refusedErrors($json));
    }

    public function testTwoHundredAndOnePinsAreTooManyPins(): void
    {
        $json = $this->mutated(fn (array $layout): array => $this->withPinCount($layout, self::PIN_LIMIT + 1));

        self::assertSame([['path' => 'P', 'code' => 'too_many_pins']], $this->refusedErrors($json));
    }

    public function testTwoHundredPinsAreAccepted(): void
    {
        $json = $this->mutated(fn (array $layout): array => $this->withPinCount($layout, self::PIN_LIMIT));

        $normalized = $this->parser()->parse($json);

        self::assertArrayHasKey('P', $normalized);
        self::assertCount(self::PIN_LIMIT, $normalized['P']);
    }

    public function testARouteBetweenTwoPinsThatAreNotLinkedIsRefused(): void
    {
        $json = $this->mutated(static function (array $layout): array {
            $layout['R'][0]['P'] = [2, 3];

            return $layout;
        });

        self::assertSame([['path' => 'R[0].P', 'code' => 'route_not_linked']], $this->refusedErrors($json));
    }

    public function testALayoutWithoutProcessorNorExtractorControlUnitIsEmpty(): void
    {
        $json = $this->mutated(static function (array $layout): array {
            $layout['P'] = [
                ['T' => self::BARREN_LAUNCHPAD, 'S' => null, 'La' => 1.25, 'Lo' => -0.5, 'H' => 0],
                ['T' => self::BARREN_STORAGE_FACILITY, 'S' => null, 'La' => 1.5, 'Lo' => -0.25, 'H' => 0],
            ];
            $layout['L'] = [['S' => 1, 'D' => 2, 'Lv' => 0]];
            $layout['R'] = [];

            return $layout;
        });

        self::assertSame([['path' => 'P', 'code' => 'empty_layout']], $this->refusedErrors($json));
    }

    public function testACommandCenterLevelOutsideZeroToFiveIsRefused(): void
    {
        foreach ([6, -1] as $commandCenterLevel) {
            $json = $this->mutated(static function (array $layout) use ($commandCenterLevel): array {
                $layout['CmdCtrLv'] = $commandCenterLevel;

                return $layout;
            });

            self::assertSame([['path' => 'CmdCtrLv', 'code' => 'out_of_range']], $this->refusedErrors($json), (string) $commandCenterLevel);
        }
    }

    public function testACommandCenterLevelOfZeroAndOfFiveAreAccepted(): void
    {
        $levelZero = $this->parser()->parse($this->mutated(static fn (array $layout): array => [...$layout, 'CmdCtrLv' => 0]));
        $levelFive = $this->parser()->parse(self::NOMINAL_LAYOUT);

        self::assertArrayHasKey('CmdCtrLv', $levelZero);
        self::assertArrayHasKey('CmdCtrLv', $levelFive);
        self::assertSame(0, $levelZero['CmdCtrLv']);
        self::assertSame(5, $levelFive['CmdCtrLv']);
    }

    public function testACommandCenterLevelWrittenAsATextIsRefusedAndNeverCoerced(): void
    {
        $json = $this->mutated(static fn (array $layout): array => [...$layout, 'CmdCtrLv' => '5']);

        self::assertSame([['path' => 'CmdCtrLv', 'code' => 'invalid_type']], $this->refusedErrors($json));
    }

    public function testALayoutWithoutCommandCenterLevelIsRefusedAndNeverDefaulted(): void
    {
        $json = $this->mutated(static function (array $layout): array {
            unset($layout['CmdCtrLv']);

            return $layout;
        });

        self::assertSame([['path' => 'CmdCtrLv', 'code' => 'missing']], $this->refusedErrors($json));
    }

    public function testAPlanetTypeAbsentFromTheSdeIsRefused(): void
    {
        $json = $this->mutated(static fn (array $layout): array => [...$layout, 'Pln' => self::MISSING_TYPE]);

        self::assertSame([['path' => 'Pln', 'code' => 'unknown_planet']], $this->refusedErrors($json));
    }

    public function testALinkEndOutsideOneToPinCountIsRefused(): void
    {
        $pastTheLastPin = $this->mutated(static function (array $layout): array {
            $layout['L'][] = ['S' => 1, 'D' => 4, 'Lv' => 0];

            return $layout;
        });
        $zeroBased = $this->mutated(static function (array $layout): array {
            $layout['L'][] = ['S' => 0, 'D' => 2, 'Lv' => 0];

            return $layout;
        });

        self::assertSame([['path' => 'L[2].D', 'code' => 'out_of_range']], $this->refusedErrors($pastTheLastPin));
        self::assertSame([['path' => 'L[2].S', 'code' => 'out_of_range']], $this->refusedErrors($zeroBased));
    }

    #[DataProvider('extractorHeadCountsOutsideOneToTen')]
    public function testAnExtractorControlUnitWithoutHeadsOrWithTooManyIsRefused(int $heads): void
    {
        $json = $this->mutated(static function (array $layout) use ($heads): array {
            $layout['P'][] = ['T' => self::BARREN_EXTRACTOR_CONTROL_UNIT, 'S' => self::AQUEOUS_LIQUIDS, 'La' => 0.75, 'Lo' => 0.5, 'H' => $heads];

            return $layout;
        });

        self::assertSame([['path' => 'P[3].H', 'code' => 'out_of_range']], $this->refusedErrors($json));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function extractorHeadCountsOutsideOneToTen(): iterable
    {
        yield 'no head' => [0];
        yield 'eleven heads' => [11];
    }

    public function testEveryErrorOfALayoutIsReportedAndNotOnlyTheFirst(): void
    {
        $json = $this->mutated(static function (array $layout): array {
            $layout['CmdCtrLv'] = 6;
            $layout['P'][0]['T'] = self::MISSING_TYPE;
            $layout['P'][2]['S'] = self::WATER;
            $layout['L'][] = ['S' => 1, 'D' => 4, 'Lv' => 0];
            $layout['R'][0]['P'] = [2, 3];

            return $layout;
        });

        self::assertSame([
            ['path' => 'CmdCtrLv', 'code' => 'out_of_range'],
            ['path' => 'L[2].D', 'code' => 'out_of_range'],
            ['path' => 'P[0].T', 'code' => 'unknown_installation'],
            ['path' => 'P[2].S', 'code' => 'unknown_product'],
            ['path' => 'R[0].P', 'code' => 'route_not_linked'],
        ], $this->sortedByPath($this->refusedErrors($json)));
    }

    /**
     * @return list<array{path: string, code: string}>
     */
    private function refusedErrors(string $json): array
    {
        try {
            $this->parser()->parse($json);
        } catch (InvalidPlanetaryColonyLayoutException $exception) {
            return $exception->errors();
        }

        self::fail('The colony layout was accepted');
    }

    /**
     * @param list<array{path: string, code: string}> $errors
     *
     * @return list<array{path: string, code: string}>
     */
    private function sortedByPath(array $errors): array
    {
        usort($errors, static fn (array $left, array $right): int => [$left['path'], $left['code']] <=> [$right['path'], $right['code']]);

        return $errors;
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $edit
     */
    private function mutated(callable $edit): string
    {
        $layout = json_decode(self::NOMINAL_LAYOUT, true, flags: \JSON_THROW_ON_ERROR);

        return json_encode($edit($layout), \JSON_THROW_ON_ERROR);
    }

    private function paddedTo(int $byteCount): string
    {
        $unpadded = $this->mutated(static fn (array $layout): array => [...$layout, 'Cmt' => '']);

        return $this->mutated(static fn (array $layout): array => [...$layout, 'Cmt' => str_repeat('a', $byteCount - \strlen($unpadded))]);
    }

    /**
     * @param array<string, mixed> $layout
     *
     * @return array<string, mixed>
     */
    private function withPinCount(array $layout, int $pinCount): array
    {
        $pins = array_slice($layout['P'], 0, 3);
        while (\count($pins) < $pinCount) {
            $pins[] = ['T' => self::BARREN_LAUNCHPAD, 'S' => null, 'La' => 0.5, 'Lo' => 0.5, 'H' => 0];
        }
        $layout['P'] = $pins;

        return $layout;
    }

    private function parser(): PlanetaryColonyLayoutParser
    {
        $sdeTypes = [
            self::BARREN_PLANET => ['Barren', self::PLANET_GROUP],
            self::BARREN_LAUNCHPAD => ['Barren Launchpad', self::LAUNCHPAD_GROUP],
            self::BARREN_ADVANCED_FACILITY => ['Barren Advanced Industry Facility', self::PROCESSOR_GROUP],
            self::BARREN_STORAGE_FACILITY => ['Barren Storage Facility', self::STORAGE_GROUP],
            self::BARREN_EXTRACTOR_CONTROL_UNIT => ['Barren Extractor Control Unit', self::EXTRACTOR_CONTROL_UNIT_GROUP],
            self::AQUEOUS_LIQUIDS => ['Aqueous Liquids', self::RAW_RESOURCE_GROUP],
            self::WATER => ['Water', self::BASIC_COMMODITY_GROUP],
            self::COOLANT => ['Coolant', self::REFINED_COMMODITY_GROUP],
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

        $schematicTypeRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $schematicTypeRepository->method('getSchematicMap')->willReturn([
            self::COOLANT_SCHEMATIC => [
                'inputs' => [self::WATER => 40, self::AQUEOUS_LIQUIDS => 40],
                'output' => ['typeId' => self::COOLANT, 'quantity' => 5],
            ],
        ]);

        return new PlanetaryColonyLayoutParser($invTypeRepository, $schematicTypeRepository);
    }
}
