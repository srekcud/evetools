<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary;

use App\Dto\Assets\AssetScope;
use App\Entity\Character;
use App\Entity\User;
use App\Repository\AssetBrowseRepository;
use App\Service\Planetary\PlanetaryHomeLocationCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(PlanetaryHomeLocationCatalog::class)]
#[Group('issue-1394')]
final class PlanetaryHomeLocationCatalogTest extends TestCase
{
    private const string ALMA_UUID = '0193a000-0000-7000-8000-000000001394';
    private const string CORA_UUID = '0193a000-0000-7000-8000-000000001395';
    private const string BOB_UUID = '0193a000-0000-7000-8000-000000001396';

    private const int JITA_STATION_ID = 60_003_760;
    private const int AMARR_STATION_ID = 60_008_494;
    private const int ASHAB_FORTIZAR_ID = 1_030_000_000_123;
    private const int OTHER_USER_KEEPSTAR_ID = 1_049_588_174_021;
    private const int LATE_HOME_KEEPSTAR_ID = 1_030_000_000_900;
    private const int EARLY_HOME_KEEPSTAR_ID = 1_030_000_000_100;

    private const string JITA_STATION_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant';
    private const string AMARR_STATION_NAME = 'Amarr VIII (Oris) - Emperor Family Academy';
    private const string ASHAB_FORTIZAR_NAME = 'Ashab - Market Fortizar';
    private const string OTHER_USER_KEEPSTAR_NAME = 'C-J6MT - 1st Taj Mahgoon (Keepstar)';
    private const string HOME_KEEPSTAR_NAME = 'Home Keepstar';

    public function testALocationHeldByTwoCharactersIsListedOnceAndTheListIsSortedByName(): void
    {
        $alma = $this->character('Alma Voss', self::ALMA_UUID);
        $cora = $this->character('Cora Voss', self::CORA_UUID);
        $user = (new User())->addCharacter($alma)->addCharacter($cora);

        $locations = $this->catalog([
            self::ALMA_UUID => [
                $this->summary(self::JITA_STATION_ID, 'station', self::JITA_STATION_NAME, 'Jita'),
                $this->summary(self::AMARR_STATION_ID, 'station', self::AMARR_STATION_NAME, 'Amarr'),
            ],
            self::CORA_UUID => [
                $this->summary(self::AMARR_STATION_ID, 'station', self::AMARR_STATION_NAME, 'Amarr'),
                $this->summary(self::ASHAB_FORTIZAR_ID, 'structure', self::ASHAB_FORTIZAR_NAME, 'Ashab'),
            ],
        ])->forUser($user);

        $this->assertSame([
            ['locationId' => self::AMARR_STATION_ID, 'locationName' => self::AMARR_STATION_NAME, 'solarSystemName' => 'Amarr'],
            ['locationId' => self::ASHAB_FORTIZAR_ID, 'locationName' => self::ASHAB_FORTIZAR_NAME, 'solarSystemName' => 'Ashab'],
            ['locationId' => self::JITA_STATION_ID, 'locationName' => self::JITA_STATION_NAME, 'solarSystemName' => 'Jita'],
        ], $locations);
    }

    public function testLocationsWithTheSameNameAreSortedByIdentifier(): void
    {
        $alma = $this->character('Alma Voss', self::ALMA_UUID);
        $user = (new User())->addCharacter($alma);

        $locations = $this->catalog([
            self::ALMA_UUID => [
                $this->summary(self::LATE_HOME_KEEPSTAR_ID, 'structure', self::HOME_KEEPSTAR_NAME, 'Ashab'),
                $this->summary(self::EARLY_HOME_KEEPSTAR_ID, 'structure', self::HOME_KEEPSTAR_NAME, 'Amarr'),
            ],
        ])->forUser($user);

        $this->assertSame([
            ['locationId' => self::EARLY_HOME_KEEPSTAR_ID, 'locationName' => self::HOME_KEEPSTAR_NAME, 'solarSystemName' => 'Amarr'],
            ['locationId' => self::LATE_HOME_KEEPSTAR_ID, 'locationName' => self::HOME_KEEPSTAR_NAME, 'solarSystemName' => 'Ashab'],
        ], $locations);
    }

    public function testALocationWithoutSolarSystemNameKeepsAnExplicitNull(): void
    {
        $alma = $this->character('Alma Voss', self::ALMA_UUID);
        $user = (new User())->addCharacter($alma);

        $locations = $this->catalog([
            self::ALMA_UUID => [$this->summary(self::ASHAB_FORTIZAR_ID, 'structure', self::ASHAB_FORTIZAR_NAME, null)],
        ])->forUser($user);

        $this->assertCount(1, $locations);
        $this->assertArrayHasKey('solarSystemName', $locations[0]);
        $this->assertNull($locations[0]['solarSystemName']);
    }

    public function testACharacterWithoutAssetGivesAnEmptyList(): void
    {
        $alma = $this->character('Alma Voss', self::ALMA_UUID);
        $user = (new User())->addCharacter($alma);

        $this->assertSame([], $this->catalog([self::ALMA_UUID => []])->forUser($user));
    }

    public function testAUserWithoutCharacterGivesAnEmptyList(): void
    {
        $this->assertSame([], $this->catalog([
            self::BOB_UUID => [$this->summary(self::OTHER_USER_KEEPSTAR_ID, 'structure', self::OTHER_USER_KEEPSTAR_NAME, 'C-J6MT')],
        ])->forUser(new User()));
    }

    public function testTheLocationsOfAnotherUserAreAbsent(): void
    {
        $alma = $this->character('Alma Voss', self::ALMA_UUID);
        $user = (new User())->addCharacter($alma);

        $locations = $this->catalog([
            self::ALMA_UUID => [$this->summary(self::JITA_STATION_ID, 'station', self::JITA_STATION_NAME, 'Jita')],
            self::BOB_UUID => [$this->summary(self::OTHER_USER_KEEPSTAR_ID, 'structure', self::OTHER_USER_KEEPSTAR_NAME, 'C-J6MT')],
        ])->forUser($user);

        $this->assertSame([self::JITA_STATION_ID], array_column($locations, 'locationId'));
    }

    private function character(string $name, string $uuid): Character
    {
        $character = (new Character())->setName($name);
        (new \ReflectionProperty(Character::class, 'id'))->setValue($character, Uuid::fromString($uuid));

        return $character;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $summariesByCharacterUuid
     */
    private function catalog(array $summariesByCharacterUuid): PlanetaryHomeLocationCatalog
    {
        $repository = $this->createStub(AssetBrowseRepository::class);
        $repository->method('summarizeLocations')->willReturnCallback(
            static fn (AssetScope $scope): array => $summariesByCharacterUuid[$scope->characterId] ?? [],
        );

        return new PlanetaryHomeLocationCatalog($repository);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(int $locationId, string $locationType, string $locationName, ?string $solarSystemName): array
    {
        return [
            'locationId' => $locationId,
            'locationType' => $locationType,
            'locationName' => $locationName,
            'solarSystemName' => $solarSystemName,
            'itemCount' => 12,
            'shipCount' => 1,
            'divisions' => [],
            'cachedAt' => '2026-11-26T08:00:00+00:00',
        ];
    }
}
