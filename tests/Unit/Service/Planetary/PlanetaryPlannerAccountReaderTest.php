<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary;

use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\User;
use App\Exception\PlanetaryTierMissingFromSdeException;
use App\Exception\PlanetSchematicIncompleteException;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\PlanetaryColonyRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\Planetary\ExtractorYieldCalculator;
use App\Service\Planetary\PlanetaryPlannerAccountReader;
use App\Service\Planetary\PlanetaryProductionCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetaryPlannerAccountReader::class)]
#[Group('issue-1403')]
final class PlanetaryPlannerAccountReaderTest extends TestCase
{
    private const int AQUEOUS_LIQUIDS = 91_001;
    private const int BASE_METALS = 91_002;
    private const int HEAVY_METALS = 91_003;
    private const int EXTRACTOR_CYCLE_SECONDS = 1800;
    private const int ZED_EVE_ID = 90_000_103;
    private const int ALMA_EVE_ID = 90_000_101;
    private const int BRAN_EVE_ID = 90_000_102;
    private const int CORA_EVE_ID = 90_000_104;
    private const int BASIC_SCHEMATIC = 92_101;
    private const int REFINED_SCHEMATIC = 92_102;
    private const int SECOND_REFINED_SCHEMATIC = 92_103;
    private const int SPECIALIZED_SCHEMATIC = 92_104;
    private const int ADVANCED_SCHEMATIC = 92_105;
    private const int UNKNOWN_SCHEMATIC = 92_199;
    private const int OUTPUT_WITHOUT_TIER_SCHEMATIC = 92_106;
    private const int OUTPUT_LESS_SCHEMATIC = 92_107;
    private const int RAW_OUTPUT_SCHEMATIC = 92_108;

    private int $nextPlanetId = 40_000_000;

    public function testUsedPlanetsIsTheNumberOfColoniesOfTheUser(): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $alma = $this->character('Alma Voss', self::ALMA_EVE_ID);
        $colonies = [$this->colony($zed, []), $this->colony($zed, []), $this->colony($alma, [])];

        $account = $this->readerOver($colonies, [])->read($this->userOf($zed, $alma));

        $this->assertSame(3, $account['usedPlanets']);
    }

    public function testEveryCharacterIsListedByNameWithItsColoniesAndAMaximumOfOnePlusTheConsolidationLevelOrNullWithoutTheSkill(): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $alma = $this->character('Alma Voss', self::ALMA_EVE_ID);
        $bran = $this->character('Bran Tolk', self::BRAN_EVE_ID);
        $cora = $this->character('Cora Whent', self::CORA_EVE_ID);
        $colonies = [$this->colony($zed, []), $this->colony($alma, []), $this->colony($zed, [])];
        $levels = [
            self::ZED_EVE_ID => [CachedCharacterSkill::SKILL_INTERPLANETARY_CONSOLIDATION => 4, 3380 => 5],
            self::ALMA_EVE_ID => [],
            self::BRAN_EVE_ID => [3380 => 3],
            self::CORA_EVE_ID => [CachedCharacterSkill::SKILL_INTERPLANETARY_CONSOLIDATION => 0],
        ];

        $account = $this->readerOver($colonies, $levels)->read($this->userOf($zed, $cora, $alma, $bran));

        $this->assertSame([
            ['characterId' => self::ALMA_EVE_ID, 'characterName' => 'Alma Voss', 'usedPlanets' => 1, 'maxPlanets' => null],
            ['characterId' => self::BRAN_EVE_ID, 'characterName' => 'Bran Tolk', 'usedPlanets' => 0, 'maxPlanets' => null],
            ['characterId' => self::CORA_EVE_ID, 'characterName' => 'Cora Whent', 'usedPlanets' => 0, 'maxPlanets' => 1],
            ['characterId' => self::ZED_EVE_ID, 'characterName' => 'Zed Marrow', 'usedPlanets' => 2, 'maxPlanets' => 5],
        ], $account['characters']);
    }

    public function testTwoCharactersWithTheSameNameAreListedByEveCharacterId(): void
    {
        $secondAlma = $this->character('Alma Voss', self::CORA_EVE_ID);
        $firstAlma = $this->character('Alma Voss', self::ALMA_EVE_ID);
        $colonies = [$this->colony($firstAlma, []), $this->colony($secondAlma, []), $this->colony($secondAlma, [])];

        $account = $this->readerOver($colonies, [])->read($this->userOf($secondAlma, $firstAlma));

        $this->assertSame([
            ['characterId' => self::ALMA_EVE_ID, 'characterName' => 'Alma Voss', 'usedPlanets' => 1, 'maxPlanets' => null],
            ['characterId' => self::CORA_EVE_ID, 'characterName' => 'Alma Voss', 'usedPlanets' => 2, 'maxPlanets' => null],
        ], $account['characters']);
    }

    public function testTheYieldOfAResourceIsTheMeanOfTheHourlyOutputOfItsColonies(): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $colonies = [
            $this->colony($zed, [$this->extractor(self::AQUEOUS_LIQUIDS, 1500, self::EXTRACTOR_CYCLE_SECONDS)]),
            $this->colony($zed, [$this->extractor(self::AQUEOUS_LIQUIDS, 2500, self::EXTRACTOR_CYCLE_SECONDS)]),
        ];

        $account = $this->readerOver($colonies, [], [1500 => 3_000.0, 2500 => 5_000.0])->read($this->userOf($zed));

        $this->assertSame([['typeId' => self::AQUEOUS_LIQUIDS, 'perHourPerPlanet' => 4_000.0, 'planets' => 2]], $account['extractionYields']);
    }

    public function testSeveralExtractorsOfTheSameColonyAddUpInOneColonyYield(): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $colonies = [
            $this->colony($zed, [
                $this->extractor(self::BASE_METALS, 1200, self::EXTRACTOR_CYCLE_SECONDS),
                $this->extractor(self::BASE_METALS, 700, 3600),
                $this->launchpad(),
            ]),
        ];

        $account = $this->readerOver($colonies, [], [1200 => 2_100.0, 700 => 1_300.0])->read($this->userOf($zed));

        $this->assertSame([['typeId' => self::BASE_METALS, 'perHourPerPlanet' => 3_400.0, 'planets' => 1]], $account['extractionYields']);
    }

    public function testEachExtractorIsHandedToTheCalculatorWithItsOwnQuantityCycleInstallAndExpiry(): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $firstInstall = new \DateTimeImmutable('2026-11-24T12:00:00+00:00');
        $firstExpiry = new \DateTimeImmutable('2026-11-27T12:00:00+00:00');
        $secondInstall = new \DateTimeImmutable('2026-11-25T08:30:00+00:00');
        $secondExpiry = new \DateTimeImmutable('2026-11-26T08:30:00+00:00');
        $colonies = [
            $this->colony($zed, [
                $this->extractor(self::AQUEOUS_LIQUIDS, 1500, self::EXTRACTOR_CYCLE_SECONDS, $firstInstall, $firstExpiry),
                $this->extractor(self::HEAVY_METALS, 700, 3600, $secondInstall, $secondExpiry),
            ]),
        ];
        $calls = [];

        $this->readerOver($colonies, [], [1500 => 3_000.0, 700 => 1_300.0], $calls)->read($this->userOf($zed));

        $this->assertSame([
            [1500, self::EXTRACTOR_CYCLE_SECONDS, '2026-11-24T12:00:00+00:00', '2026-11-27T12:00:00+00:00'],
            [700, 3600, '2026-11-25T08:30:00+00:00', '2026-11-26T08:30:00+00:00'],
        ], $calls);
    }

    public function testAColonyWhoseExtractorYieldIsNotComputableIsLeftOutOfTheMean(): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $colonies = [
            $this->colony($zed, [$this->extractor(self::AQUEOUS_LIQUIDS, 1500, self::EXTRACTOR_CYCLE_SECONDS)]),
            $this->colony($zed, [$this->extractor(self::AQUEOUS_LIQUIDS, 2500, self::EXTRACTOR_CYCLE_SECONDS)]),
        ];

        $account = $this->readerOver($colonies, [], [1500 => 3_000.0, 2500 => null])->read($this->userOf($zed));

        $this->assertSame([['typeId' => self::AQUEOUS_LIQUIDS, 'perHourPerPlanet' => 3_000.0, 'planets' => 1]], $account['extractionYields']);
        $this->assertSame(2, $account['usedPlanets']);
    }

    public static function incompleteExtractors(): iterable
    {
        yield 'without cycle time' => [2500, null];
        yield 'without quantity per cycle' => [null, self::EXTRACTOR_CYCLE_SECONDS];
    }

    #[DataProvider('incompleteExtractors')]
    public function testAColonyWhoseExtractorLacksItsQuantityOrItsCycleIsLeftOutOfTheMean(?int $quantity, ?int $cycleSeconds): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $colonies = [
            $this->colony($zed, [$this->extractor(self::AQUEOUS_LIQUIDS, 1500, self::EXTRACTOR_CYCLE_SECONDS)]),
            $this->colony($zed, [$this->extractor(self::AQUEOUS_LIQUIDS, $quantity, $cycleSeconds)]),
        ];

        $account = $this->readerOver($colonies, [], [1500 => 3_000.0, 2500 => 5_000.0])->read($this->userOf($zed));

        $this->assertSame([['typeId' => self::AQUEOUS_LIQUIDS, 'perHourPerPlanet' => 3_000.0, 'planets' => 1]], $account['extractionYields']);
        $this->assertSame(2, $account['usedPlanets']);
    }

    public static function nonPositiveHourlyYields(): iterable
    {
        yield 'zero when the program is shorter than one cycle' => [0.0];
        yield 'negative' => [-250.0];
    }

    #[DataProvider('nonPositiveHourlyYields')]
    public function testAColonyWhoseExtractorYieldPerHourIsNotPositiveIsLeftOutOfTheMean(float $hourlyYield): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $colonies = [
            $this->colony($zed, [$this->extractor(self::AQUEOUS_LIQUIDS, 1500, self::EXTRACTOR_CYCLE_SECONDS)]),
            $this->colony($zed, [$this->extractor(self::AQUEOUS_LIQUIDS, 2500, self::EXTRACTOR_CYCLE_SECONDS)]),
        ];

        $account = $this->readerOver($colonies, [], [1500 => 3_000.0, 2500 => $hourlyYield])->read($this->userOf($zed));

        $this->assertSame([['typeId' => self::AQUEOUS_LIQUIDS, 'perHourPerPlanet' => 3_000.0, 'planets' => 1]], $account['extractionYields']);
        $this->assertSame(2, $account['usedPlanets']);
    }

    public function testAColonyWithOneProductiveAndOneZeroYieldExtractorOfTheSameResourceIsLeftOut(): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $colonies = [
            $this->colony($zed, [$this->extractor(self::HEAVY_METALS, 900, 3600)]),
            $this->colony($zed, [
                $this->extractor(self::HEAVY_METALS, 1000, self::EXTRACTOR_CYCLE_SECONDS),
                $this->extractor(self::HEAVY_METALS, 1100, self::EXTRACTOR_CYCLE_SECONDS),
            ]),
        ];

        $account = $this->readerOver($colonies, [], [900 => 1_300.0, 1000 => 2_000.0, 1100 => 0.0])->read($this->userOf($zed));

        $this->assertSame([['typeId' => self::HEAVY_METALS, 'perHourPerPlanet' => 1_300.0, 'planets' => 1]], $account['extractionYields']);
    }

    public function testAResourceWhoseEveryColonyYieldsZeroIsAbsentWhileAnotherResourceOfTheSameColonyStays(): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $colonies = [
            $this->colony($zed, [
                $this->extractor(self::BASE_METALS, 800, self::EXTRACTOR_CYCLE_SECONDS),
                $this->extractor(self::HEAVY_METALS, 900, 3600),
            ]),
        ];

        $account = $this->readerOver($colonies, [], [800 => 0.0, 900 => 1_300.0])->read($this->userOf($zed));

        $this->assertSame([['typeId' => self::HEAVY_METALS, 'perHourPerPlanet' => 1_300.0, 'planets' => 1]], $account['extractionYields']);
    }

    public function testAColonyWithOneComputableAndOneNonComputableExtractorOfTheSameResourceIsLeftOut(): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $colonies = [
            $this->colony($zed, [$this->extractor(self::HEAVY_METALS, 900, 3600)]),
            $this->colony($zed, [
                $this->extractor(self::HEAVY_METALS, 1000, self::EXTRACTOR_CYCLE_SECONDS),
                $this->extractor(self::HEAVY_METALS, 1100, self::EXTRACTOR_CYCLE_SECONDS),
            ]),
        ];

        $account = $this->readerOver($colonies, [], [900 => 1_300.0, 1000 => 2_000.0, 1100 => null])->read($this->userOf($zed));

        $this->assertSame([['typeId' => self::HEAVY_METALS, 'perHourPerPlanet' => 1_300.0, 'planets' => 1]], $account['extractionYields']);
    }

    public function testAResourceWhoseColoniesAreAllLeftOutIsAbsentWhileAnotherResourceOfTheSameColonyStays(): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $colonies = [
            $this->colony($zed, [
                $this->extractor(self::BASE_METALS, 800, self::EXTRACTOR_CYCLE_SECONDS),
                $this->extractor(self::HEAVY_METALS, 900, 3600),
            ]),
        ];

        $account = $this->readerOver($colonies, [], [800 => null, 900 => 1_300.0])->read($this->userOf($zed));

        $this->assertSame([['typeId' => self::HEAVY_METALS, 'perHourPerPlanet' => 1_300.0, 'planets' => 1]], $account['extractionYields']);
    }

    public function testAnExpiredExtractorIsHandedToTheCalculatorAndTheYieldsAreSortedByType(): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $expired = new \DateTimeImmutable('2020-01-01T00:00:00+00:00');
        $colonies = [
            $this->colony($zed, [$this->extractor(self::HEAVY_METALS, 900, 3600)]),
            $this->colony($zed, [$this->extractor(self::AQUEOUS_LIQUIDS, 1500, self::EXTRACTOR_CYCLE_SECONDS, new \DateTimeImmutable('2019-12-29T00:00:00+00:00'), $expired)]),
        ];

        $account = $this->readerOver($colonies, [], [900 => 1_300.0, 1500 => 3_000.0])->read($this->userOf($zed));

        $this->assertSame([
            ['typeId' => self::AQUEOUS_LIQUIDS, 'perHourPerPlanet' => 3_000.0, 'planets' => 1],
            ['typeId' => self::HEAVY_METALS, 'perHourPerPlanet' => 1_300.0, 'planets' => 1],
        ], $account['extractionYields']);
    }

    public function testAUserWithoutColonyGetsNoUsedPlanetAndNoYield(): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);

        $account = $this->readerOver([], [self::ZED_EVE_ID => [CachedCharacterSkill::SKILL_INTERPLANETARY_CONSOLIDATION => 3]])->read($this->userOf($zed));

        $this->assertSame(0, $account['usedPlanets']);
        $this->assertSame([], $account['extractionYields']);
        $this->assertSame([['characterId' => self::ZED_EVE_ID, 'characterName' => 'Zed Marrow', 'usedPlanets' => 0, 'maxPlanets' => 4]], $account['characters']);
    }

    public function testFactoriesCountsTheInstalledFactoriesByTierOfTheProductOfTheirSchematic(): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $colonies = [
            $this->colony($zed, [
                ...$this->factories(self::BASIC_SCHEMATIC, 3),
                ...$this->factories(self::REFINED_SCHEMATIC, 2),
            ]),
            $this->colony($zed, [
                ...$this->factories(self::SECOND_REFINED_SCHEMATIC, 1),
                ...$this->factories(self::SPECIALIZED_SCHEMATIC, 1),
                $this->extractor(self::AQUEOUS_LIQUIDS, 1500, self::EXTRACTOR_CYCLE_SECONDS),
                $this->launchpad(),
            ]),
            $this->colony($zed, [
                ...$this->factories(self::ADVANCED_SCHEMATIC, 4),
                ...$this->factories(self::BASIC_SCHEMATIC, 1),
            ]),
        ];

        $account = $this->factoryReaderOver($colonies, [1500 => 3_000.0])->read($this->userOf($zed));

        $this->assertSame(['P1' => 4, 'P2' => 3, 'P3' => 1, 'P4' => 4], $account['factories']);
    }

    public function testFactoriesIsZeroInEveryTierForAUserWithoutColonyOrWithoutFactory(): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $withoutFactory = [$this->colony($zed, [$this->extractor(self::BASE_METALS, 1200, self::EXTRACTOR_CYCLE_SECONDS), $this->launchpad()])];

        $withoutColony = $this->factoryReaderOver([], [])->read($this->userOf($zed));
        $extractorsOnly = $this->factoryReaderOver($withoutFactory, [1200 => 2_100.0])->read($this->userOf($zed));

        $this->assertSame(['P1' => 0, 'P2' => 0, 'P3' => 0, 'P4' => 0], $withoutColony['factories']);
        $this->assertSame(['P1' => 0, 'P2' => 0, 'P3' => 0, 'P4' => 0], $extractorsOnly['factories']);
    }

    public function testAnExtractorCarryingASchematicIdIsNotAFactoryAndItsSchematicIsNotLookedUp(): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $extractorWithKnownSchematic = $this->extractor(self::AQUEOUS_LIQUIDS, 1500, self::EXTRACTOR_CYCLE_SECONDS)->setSchematicId(self::ADVANCED_SCHEMATIC);
        $extractorWithUnknownSchematic = $this->extractor(self::AQUEOUS_LIQUIDS, 1500, self::EXTRACTOR_CYCLE_SECONDS)->setSchematicId(self::UNKNOWN_SCHEMATIC);
        $colonies = [$this->colony($zed, [$extractorWithKnownSchematic, $extractorWithUnknownSchematic])];

        $account = $this->factoryReaderOver($colonies, [1500 => 3_000.0])->read($this->userOf($zed));

        $this->assertSame(['P1' => 0, 'P2' => 0, 'P3' => 0, 'P4' => 0], $account['factories']);
    }

    public function testAFactoryWhoseSchematicIsUnknownIsAnExplicitErrorNotASilentCount(): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $colonies = [$this->colony($zed, [...$this->factories(self::REFINED_SCHEMATIC, 2), ...$this->factories(self::UNKNOWN_SCHEMATIC, 1)])];

        $this->expectException(PlanetSchematicIncompleteException::class);
        $this->expectExceptionMessage('Planet schematic 92199 is incomplete in the SDE');

        $this->factoryReaderOver($colonies, [])->read($this->userOf($zed));
    }

    public function testAFactoryWhoseSchematicHasNoOutputIsAnExplicitError(): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $colonies = [$this->colony($zed, $this->factories(self::OUTPUT_LESS_SCHEMATIC, 1))];

        $this->expectException(PlanetSchematicIncompleteException::class);
        $this->expectExceptionMessage('Planet schematic 92107 is incomplete in the SDE');

        $this->factoryReaderOver($colonies, [])->read($this->userOf($zed));
    }

    public function testAFactoryWhoseProductHasNoTierIsAnExplicitError(): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $colonies = [$this->colony($zed, $this->factories(self::OUTPUT_WITHOUT_TIER_SCHEMATIC, 1))];

        $this->expectException(PlanetaryTierMissingFromSdeException::class);
        $this->expectExceptionMessage('No PI tier found in the SDE market groups for type 92206');

        $this->factoryReaderOver($colonies, [])->read($this->userOf($zed));
    }

    public function testAFactoryWhoseProductIsOfTierP0IsAnExplicitErrorNotACountInAnyTier(): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $colonies = [$this->colony($zed, [...$this->factories(self::REFINED_SCHEMATIC, 2), ...$this->factories(self::RAW_OUTPUT_SCHEMATIC, 1)])];
        $schematicMap = $this->schematicMap() + [self::RAW_OUTPUT_SCHEMATIC => ['inputs' => [92_201 => 5], 'output' => ['typeId' => 92_207, 'quantity' => 1]]];
        $tiers = $this->tiersByOutput() + [92_207 => 'P0'];
        $calls = [];

        $this->expectException(PlanetaryTierMissingFromSdeException::class);
        $this->expectExceptionMessage('No PI tier found in the SDE market groups for type 92207');

        $this->readerOver($colonies, [], [], $calls, $schematicMap, $tiers)->read($this->userOf($zed));
    }

    public function testFactoriesKeepsEachTierInItsOwnKeyWhateverTheOrderOfTheColoniesWithADifferentCountPerTier(): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $colonies = [
            $this->colony($zed, [
                ...$this->factories(self::ADVANCED_SCHEMATIC, 7),
                ...$this->factories(self::REFINED_SCHEMATIC, 2),
            ]),
            $this->colony($zed, [
                ...$this->factories(self::SPECIALIZED_SCHEMATIC, 2),
                ...$this->factories(self::SECOND_REFINED_SCHEMATIC, 3),
                ...$this->factories(self::BASIC_SCHEMATIC, 3),
            ]),
        ];

        $account = $this->factoryReaderOver($colonies, [])->read($this->userOf($zed));

        $this->assertSame(['P1' => 3, 'P2' => 5, 'P3' => 2, 'P4' => 7], $account['factories']);
    }

    private function factories(int $schematicId, int $count): array
    {
        return array_map(
            fn (): PlanetaryPin => (new PlanetaryPin())->setPinId(++$this->nextPlanetId)->setTypeId(2473)->setSchematicId($schematicId),
            range(1, $count),
        );
    }

    private function schematicMap(): array
    {
        return [
            self::BASIC_SCHEMATIC => ['inputs' => [92_301 => 3_000], 'output' => ['typeId' => 92_201, 'quantity' => 20]],
            self::REFINED_SCHEMATIC => ['inputs' => [92_201 => 40], 'output' => ['typeId' => 92_202, 'quantity' => 5]],
            self::SECOND_REFINED_SCHEMATIC => ['inputs' => [92_201 => 30], 'output' => ['typeId' => 92_203, 'quantity' => 5]],
            self::SPECIALIZED_SCHEMATIC => ['inputs' => [92_202 => 10], 'output' => ['typeId' => 92_204, 'quantity' => 3]],
            self::ADVANCED_SCHEMATIC => ['inputs' => [92_204 => 6], 'output' => ['typeId' => 92_205, 'quantity' => 1]],
            self::OUTPUT_WITHOUT_TIER_SCHEMATIC => ['inputs' => [92_201 => 7], 'output' => ['typeId' => 92_206, 'quantity' => 2]],
            self::OUTPUT_LESS_SCHEMATIC => ['inputs' => [92_201 => 7], 'output' => null],
        ];
    }

    private function tiersByOutput(): array
    {
        return [92_201 => 'P1', 92_202 => 'P2', 92_203 => 'P2', 92_204 => 'P3', 92_205 => 'P4'];
    }

    private function character(string $name, int $eveCharacterId): Character
    {
        return (new Character())->setEveCharacterId($eveCharacterId)->setName($name);
    }

    private function userOf(Character ...$characters): User
    {
        $user = new User();
        foreach ($characters as $character) {
            $user->addCharacter($character);
        }

        return $user;
    }

    private function extractor(int $productTypeId, ?int $quantityPerCycle, ?int $cycleSeconds, ?\DateTimeImmutable $installTime = null, ?\DateTimeImmutable $expiryTime = null): PlanetaryPin
    {
        return (new PlanetaryPin())
            ->setPinId(++$this->nextPlanetId)
            ->setTypeId(2848)
            ->setExtractorProductTypeId($productTypeId)
            ->setExtractorQtyPerCycle($quantityPerCycle)
            ->setExtractorCycleTime($cycleSeconds)
            ->setInstallTime($installTime ?? new \DateTimeImmutable('2026-11-24T12:00:00+00:00'))
            ->setExpiryTime($expiryTime ?? new \DateTimeImmutable('2026-11-27T12:00:00+00:00'));
    }

    private function launchpad(): PlanetaryPin
    {
        return (new PlanetaryPin())->setPinId(++$this->nextPlanetId)->setTypeId(2544);
    }

    private function colony(Character $character, array $pins): PlanetaryColony
    {
        $colony = (new PlanetaryColony())
            ->setCharacter($character)
            ->setPlanetId(++$this->nextPlanetId)
            ->setPlanetType('temperate')
            ->setSolarSystemId(30_001_538);
        foreach ($pins as $pin) {
            $colony->addPin($pin);
        }

        return $colony;
    }

    private function factoryReaderOver(array $colonies, array $hourlyYieldByQuantity): PlanetaryPlannerAccountReader
    {
        $calls = [];

        return $this->readerOver($colonies, [], $hourlyYieldByQuantity, $calls, $this->schematicMap(), $this->tiersByOutput());
    }

    private function readerOver(array $colonies, array $levelsByEveCharacterId, array $hourlyYieldByQuantity = [], array &$calls = [], array $schematicMap = [], array $tierByOutputTypeId = []): PlanetaryPlannerAccountReader
    {
        $colonyRepository = $this->createStub(PlanetaryColonyRepository::class);
        $colonyRepository->method('findByUser')->willReturn($colonies);
        $skillRepository = $this->createStub(CachedCharacterSkillRepository::class);
        $skillRepository->method('findLevelsByEveCharacterId')->willReturnCallback(
            static fn (int $eveCharacterId): array => $levelsByEveCharacterId[$eveCharacterId] ?? [],
        );
        $calculator = $this->createStub(ExtractorYieldCalculator::class);
        $calculator->method('programDailyAverage')->willReturnCallback(
            static function (int $quantityPerCycle, int $cycleTime, ?\DateTimeImmutable $installTime, ?\DateTimeImmutable $expiryTime) use ($hourlyYieldByQuantity, &$calls): ?float {
                $calls[] = [$quantityPerCycle, $cycleTime, $installTime?->format(\DATE_ATOM), $expiryTime?->format(\DATE_ATOM)];
                $hourlyYield = $hourlyYieldByQuantity[$quantityPerCycle] ?? null;

                return $hourlyYield === null ? null : $hourlyYield * 24;
            },
        );

        $schematicTypeRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $schematicTypeRepository->method('getSchematicMap')->willReturn($schematicMap);
        $productionCalculator = $this->createStub(PlanetaryProductionCalculator::class);
        $productionCalculator->method('findProductTier')->willReturnCallback(
            static fn (int $typeId): ?string => $tierByOutputTypeId[$typeId] ?? null,
        );

        return new PlanetaryPlannerAccountReader($colonyRepository, $skillRepository, $calculator, $schematicTypeRepository, $productionCalculator);
    }
}
