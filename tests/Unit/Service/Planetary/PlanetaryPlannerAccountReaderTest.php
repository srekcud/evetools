<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary;

use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\User;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\PlanetaryColonyRepository;
use App\Service\Planetary\PlanetaryPlannerAccountReader;
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

    public function testTheYieldOfAResourceIsTheMeanOfTheHourlyOutputOfItsColonies(): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $colonies = [
            $this->colony($zed, [$this->extractor(self::AQUEOUS_LIQUIDS, 1500, self::EXTRACTOR_CYCLE_SECONDS)]),
            $this->colony($zed, [$this->extractor(self::AQUEOUS_LIQUIDS, 2500, self::EXTRACTOR_CYCLE_SECONDS)]),
        ];

        $account = $this->readerOver($colonies, [])->read($this->userOf($zed));

        $this->assertSame([['typeId' => self::AQUEOUS_LIQUIDS, 'perHourPerPlanet' => 4_000.0, 'planets' => 2]], $account['extractionYields']);
    }

    public function testSeveralExtractorsOfTheSameColonyAddUpInOneColonyYield(): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $colonies = [
            $this->colony($zed, [
                $this->extractor(self::BASE_METALS, 1500, self::EXTRACTOR_CYCLE_SECONDS),
                $this->extractor(self::BASE_METALS, 900, 3600),
                $this->launchpad(),
            ]),
        ];

        $account = $this->readerOver($colonies, [])->read($this->userOf($zed));

        $this->assertSame([['typeId' => self::BASE_METALS, 'perHourPerPlanet' => 3_900.0, 'planets' => 1]], $account['extractionYields']);
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

        $account = $this->readerOver($colonies, [])->read($this->userOf($zed));

        $this->assertSame([['typeId' => self::AQUEOUS_LIQUIDS, 'perHourPerPlanet' => 3_000.0, 'planets' => 1]], $account['extractionYields']);
        $this->assertSame(2, $account['usedPlanets']);
    }

    public function testAColonyWithOneCompleteAndOneIncompleteExtractorOfTheSameResourceIsLeftOut(): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $colonies = [
            $this->colony($zed, [$this->extractor(self::HEAVY_METALS, 900, 3600)]),
            $this->colony($zed, [
                $this->extractor(self::HEAVY_METALS, 1000, self::EXTRACTOR_CYCLE_SECONDS),
                $this->extractor(self::HEAVY_METALS, null, self::EXTRACTOR_CYCLE_SECONDS),
            ]),
        ];

        $account = $this->readerOver($colonies, [])->read($this->userOf($zed));

        $this->assertSame([['typeId' => self::HEAVY_METALS, 'perHourPerPlanet' => 900.0, 'planets' => 1]], $account['extractionYields']);
    }

    public function testAResourceWhoseColoniesAreAllLeftOutIsAbsentFromTheYields(): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $colonies = [
            $this->colony($zed, [$this->extractor(self::BASE_METALS, 800, null)]),
            $this->colony($zed, [$this->extractor(self::HEAVY_METALS, 900, 3600)]),
        ];

        $account = $this->readerOver($colonies, [])->read($this->userOf($zed));

        $this->assertSame([['typeId' => self::HEAVY_METALS, 'perHourPerPlanet' => 900.0, 'planets' => 1]], $account['extractionYields']);
    }

    public function testAnExpiredExtractorStillCountsAndTheYieldsAreSortedByType(): void
    {
        $zed = $this->character('Zed Marrow', self::ZED_EVE_ID);
        $colonies = [
            $this->colony($zed, [$this->extractor(self::HEAVY_METALS, 900, 3600)]),
            $this->colony($zed, [$this->extractor(self::AQUEOUS_LIQUIDS, 1500, self::EXTRACTOR_CYCLE_SECONDS, new \DateTimeImmutable('2020-01-01T00:00:00+00:00'))]),
        ];

        $account = $this->readerOver($colonies, [])->read($this->userOf($zed));

        $this->assertSame([
            ['typeId' => self::AQUEOUS_LIQUIDS, 'perHourPerPlanet' => 3_000.0, 'planets' => 1],
            ['typeId' => self::HEAVY_METALS, 'perHourPerPlanet' => 900.0, 'planets' => 1],
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

    private function extractor(int $productTypeId, ?int $quantityPerCycle, ?int $cycleSeconds, ?\DateTimeImmutable $expiryTime = null): PlanetaryPin
    {
        return (new PlanetaryPin())
            ->setPinId(++$this->nextPlanetId)
            ->setTypeId(2848)
            ->setExtractorProductTypeId($productTypeId)
            ->setExtractorQtyPerCycle($quantityPerCycle)
            ->setExtractorCycleTime($cycleSeconds)
            ->setExpiryTime($expiryTime);
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

    private function readerOver(array $colonies, array $levelsByEveCharacterId): PlanetaryPlannerAccountReader
    {
        $colonyRepository = $this->createStub(PlanetaryColonyRepository::class);
        $colonyRepository->method('findByUser')->willReturn($colonies);
        $skillRepository = $this->createStub(CachedCharacterSkillRepository::class);
        $skillRepository->method('findLevelsByEveCharacterId')->willReturnCallback(
            static fn (int $eveCharacterId): array => $levelsByEveCharacterId[$eveCharacterId] ?? [],
        );

        return new PlanetaryPlannerAccountReader($colonyRepository, $skillRepository);
    }
}
