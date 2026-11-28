<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Planetary;

use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\PlanetaryRoute;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\PlanetSchematic;
use App\Entity\Sde\PlanetSchematicType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[Group('issue-1460')]
final class PlanetarySupplyHoldersApiTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string SUPPLY_HOLDERS_URI = '/api/planetary/supply-holders';
    private const string NOW = '2026-11-28T12:00:00+00:00';
    private const string ALICE_SYNCED_AT = '2026-11-28T11:00:00+00:00';
    private const string BOB_SYNCED_AT = '2026-11-28T10:30:00+00:00';
    private const string ASSETS_SCOPE = 'esi-assets.read_assets.v1';

    private const int SPACEPORTS_GROUP_ID = 1030;
    private const int COMMODITIES_GROUP_ID = 996_042;
    private const int STRUCTURES_CATEGORY_ID = 996_066;
    private const int CONTAINER_TYPE_ID = 17_366;

    private const int LAUNCHPAD_TYPE_ID = 996_256;
    private const int FACTORY_TYPE_ID = 996_473;
    private const int BACTERIA = 996_001;
    private const int REACTIVE_METALS = 996_002;
    private const int OXYGEN = 996_101;
    private const int COOLANT = 996_102;

    private const int ALICE_SCHEMATIC = 996_001;
    private const int BOB_SCHEMATIC = 996_002;

    private const int ALICE_PLANET = 40_146_001;
    private const int BOB_PLANET = 40_146_002;
    private const int ALICE_LAUNCHPAD = 1_046_000_000_101;
    private const int ALICE_FACTORY = 1_046_000_000_102;
    private const int BOB_LAUNCHPAD = 1_046_000_000_201;
    private const int BOB_FACTORY = 1_046_000_000_202;

    private const int JITA_STATION_ID = 60_003_760;
    private const string JITA_STATION_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant';
    private const int AMARR_STATION_ID = 60_008_494;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $alice;
    private User $bob;
    private int $nextEveCharacterId = 2_146_001_001;
    private int $nextItemId = 1_146_000_000_001;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->seedSde();
        $this->alice = $this->persistPilot('Alice Tarn', [self::ASSETS_SCOPE], self::ALICE_SYNCED_AT);
        $aldo = $this->persistSecondCharacter($this->alice, 'Aldo Tarn', null, null);
        $this->bob = $this->persistPilot('Bob Kess', [self::ASSETS_SCOPE], self::BOB_SYNCED_AT);

        $this->alice->setPlanetaryHomeLocation(self::JITA_STATION_ID, self::JITA_STATION_NAME);
        $this->em->flush();

        $this->persistColony($this->alice, self::ALICE_PLANET, 'Aldranette II', self::ALICE_LAUNCHPAD, self::ALICE_FACTORY, self::ALICE_SCHEMATIC, self::BACTERIA);
        $this->persistColony($this->bob, self::BOB_PLANET, 'Jaspet IV', self::BOB_LAUNCHPAD, self::BOB_FACTORY, self::BOB_SCHEMATIC, self::REACTIVE_METALS);

        $aliceMain = $this->alice->getMainCharacter();
        self::assertNotNull($aliceMain);
        $box = $this->persistAsset($aliceMain, self::CONTAINER_TYPE_ID, 1, self::JITA_STATION_ID, 'station', true);
        $this->persistAsset($aliceMain, self::BACTERIA, 1_000, $box, 'item');
        $this->persistAsset($aliceMain, self::BACTERIA, 200, self::AMARR_STATION_ID, 'station');
        $this->persistAsset($aliceMain, self::REACTIVE_METALS, 77, self::JITA_STATION_ID, 'station');
        $this->persistAsset($aldo, self::BACTERIA, 9_999, self::JITA_STATION_ID, 'station');

        $bobMain = $this->bob->getMainCharacter();
        self::assertNotNull($bobMain);
        $this->persistAsset($bobMain, self::BACTERIA, 5_000, self::JITA_STATION_ID, 'station');
        $this->persistAsset($bobMain, self::REACTIVE_METALS, 300, self::JITA_STATION_ID, 'station');
        $this->em->flush();
    }

    public function testTheSupplyHoldersRequireAuthentication(): void
    {
        $this->client->request('GET', self::SUPPLY_HOLDERS_URI, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testOnlyTheCharactersOfTheConnectedUserAreListedAndTheirQuantitiesCountContainersAtTheHomeLocationRootOnly(): void
    {
        $body = $this->read($this->alice);

        self::assertSame('supply-holders', $body['id']);
        self::assertSame(self::JITA_STATION_ID, $body['homeLocationId']);
        self::assertSame(self::JITA_STATION_NAME, $body['homeLocationName']);
        self::assertSame(['Aldo Tarn', 'Alice Tarn'], array_column($body['characters'], 'characterName'));
        self::assertSame(
            [$this->alice->getCharacters()->get(1)?->getEveCharacterId(), $this->alice->getMainCharacter()?->getEveCharacterId()],
            array_column($body['characters'], 'characterId'),
        );

        [$aldo, $aliceMain] = $body['characters'];
        self::assertSame('unknown', $aldo['status']);
        self::assertSame('missing_assets_scope', $aldo['unknownReason']);
        self::assertArrayHasKey('assetsReadAt', $aldo);
        self::assertNull($aldo['assetsReadAt']);
        self::assertFalse($aldo['stale']);
        self::assertSame([], $aldo['quantities']);

        self::assertSame('known', $aliceMain['status']);
        self::assertArrayHasKey('unknownReason', $aliceMain);
        self::assertNull($aliceMain['unknownReason']);
        self::assertSame(self::ALICE_SYNCED_AT, $aliceMain['assetsReadAt']);
        self::assertFalse($aliceMain['stale']);
        self::assertSame([['typeId' => self::BACTERIA, 'quantity' => 1_000]], $aliceMain['quantities']);
    }

    public function testAnotherUserSeesOnlyTheirOwnCharacterAndWithoutHomeLocationNoQuantityIsReported(): void
    {
        $body = $this->read($this->bob);

        self::assertArrayHasKey('homeLocationId', $body);
        self::assertNull($body['homeLocationId']);
        self::assertArrayHasKey('homeLocationName', $body);
        self::assertNull($body['homeLocationName']);
        self::assertSame(['Bob Kess'], array_column($body['characters'], 'characterName'));
        self::assertSame('known', $body['characters'][0]['status']);
        self::assertSame([], $body['characters'][0]['quantities']);
    }

    private function actAs(User $user): void
    {
        $this->client->getKernel()->boot();
        $this->client->loginUser($this->em->find(User::class, $user->getId()), 'api');
    }

    /**
     * @return array<string, mixed>
     */
    private function read(User $user): array
    {
        $this->actAs($user);
        $this->client->request('GET', self::SUPPLY_HOLDERS_URI, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function seedSde(): void
    {
        $category = $this->em->find(InvCategory::class, self::STRUCTURES_CATEGORY_ID)
            ?? (new InvCategory())->setCategoryId(self::STRUCTURES_CATEGORY_ID)->setCategoryName('Planetary Infrastructure');
        $this->em->persist($category);
        $spaceports = $this->em->find(InvGroup::class, self::SPACEPORTS_GROUP_ID)
            ?? (new InvGroup())->setGroupId(self::SPACEPORTS_GROUP_ID)->setGroupName('Spaceports')->setCategory($category);
        $this->em->persist($spaceports);
        $commodities = (new InvGroup())->setGroupId(self::COMMODITIES_GROUP_ID)->setGroupName('Basic Commodities')->setCategory($category);
        $this->em->persist($commodities);

        $this->persistType(self::LAUNCHPAD_TYPE_ID, 'Launchpad', $spaceports, null, 10000.0);
        $this->persistType(self::FACTORY_TYPE_ID, 'Basic Industry Facility', $commodities, null, null);
        $this->persistType(self::BACTERIA, 'Bacteria', $commodities, 0.38, null);
        $this->persistType(self::REACTIVE_METALS, 'Reactive Metals', $commodities, 0.52, null);
        $this->persistType(self::OXYGEN, 'Oxygen', $commodities, 1.5, null);
        $this->persistType(self::COOLANT, 'Coolant', $commodities, 1.75, null);

        $this->persistSchematic(self::ALICE_SCHEMATIC, 3600, [self::BACTERIA => 40], self::OXYGEN);
        $this->persistSchematic(self::BOB_SCHEMATIC, 1800, [self::REACTIVE_METALS => 10], self::COOLANT);
        $this->em->flush();
    }

    private function persistType(int $typeId, string $typeName, InvGroup $group, ?float $volume, ?float $capacity): void
    {
        $this->em->persist(
            (new InvType())
                ->setTypeId($typeId)
                ->setTypeName($typeName)
                ->setGroup($group)
                ->setVolume($volume)
                ->setCapacity($capacity)
                ->setPublished(true),
        );
    }

    /**
     * @param array<int, int> $inputs
     */
    private function persistSchematic(int $schematicId, int $cycleTime, array $inputs, int $outputTypeId): void
    {
        $schematic = (new PlanetSchematic())
            ->setSchematicId($schematicId)
            ->setSchematicName('Schematic '.$schematicId)
            ->setCycleTime($cycleTime);
        $this->em->persist($schematic);
        foreach ($inputs as $typeId => $quantity) {
            $this->em->persist((new PlanetSchematicType())->setSchematic($schematic)->setTypeId($typeId)->setIsInput(true)->setQuantity($quantity));
        }
        $this->em->persist((new PlanetSchematicType())->setSchematic($schematic)->setTypeId($outputTypeId)->setIsInput(false)->setQuantity(1));
    }

    /**
     * @param list<string> $scopes
     */
    private function persistPilot(string $name, array $scopes, string $lastSyncAt): User
    {
        $user = new User();
        $this->em->persist($user);
        $character = $this->newCharacter($name, $scopes, $lastSyncAt);
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }

    /**
     * @param list<string>|null $scopes
     */
    private function persistSecondCharacter(User $user, string $name, ?array $scopes, ?string $lastSyncAt): Character
    {
        $character = $this->newCharacter($name, $scopes, $lastSyncAt);
        $user->addCharacter($character);
        $this->em->persist($character);
        $this->em->flush();

        return $character;
    }

    /**
     * @param list<string>|null $scopes
     */
    private function newCharacter(string $name, ?array $scopes, ?string $lastSyncAt): Character
    {
        $character = (new Character())
            ->setEveCharacterId($this->nextEveCharacterId++)
            ->setName($name)
            ->setCorporationId(98_546_001)
            ->setCorporationName('Verhoeven Deep Core')
            ->setLastSyncAt(null === $lastSyncAt ? null : new \DateTimeImmutable($lastSyncAt));
        if (null !== $scopes) {
            $character->setEveToken(
                (new EveToken())
                    ->setAccessToken('access-token')
                    ->setRefreshTokenEncrypted('refresh-token')
                    ->setAccessTokenExpiresAt(new \DateTimeImmutable('2026-11-28T13:00:00+00:00'))
                    ->setScopes($scopes),
            );
        }

        return $character;
    }

    private function persistColony(User $user, int $planetId, string $planetName, int $launchpadPinId, int $factoryPinId, int $schematicId, int $routedTypeId): void
    {
        $character = $user->getMainCharacter();
        self::assertNotNull($character);

        $colony = (new PlanetaryColony())
            ->setCharacter($character)
            ->setPlanetId($planetId)
            ->setPlanetName($planetName)
            ->setPlanetType('temperate')
            ->setSolarSystemId(30_001_538)
            ->setSolarSystemName('Aldranette')
            ->setUpgradeLevel(4)
            ->setNumPins(2)
            ->setLastUpdate(new \DateTimeImmutable(self::NOW));
        $colony->addPin((new PlanetaryPin())->setPinId($launchpadPinId)->setTypeId(self::LAUNCHPAD_TYPE_ID));
        $colony->addPin((new PlanetaryPin())->setPinId($factoryPinId)->setTypeId(self::FACTORY_TYPE_ID)->setSchematicId($schematicId));
        $colony->addRoute((new PlanetaryRoute())
            ->setRouteId(1)
            ->setSourcePinId($launchpadPinId)
            ->setDestinationPinId($factoryPinId)
            ->setContentTypeId($routedTypeId)
            ->setQuantity(1800.0));
        $this->em->persist($colony);
        $this->em->flush();
    }

    private function persistAsset(Character $character, int $typeId, int $quantity, int $locationId, string $locationType, bool $singleton = false): int
    {
        $itemId = $this->nextItemId++;
        $this->em->persist((new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId($typeId)
            ->setTypeName('Type '.$typeId)
            ->setQuantity($quantity)
            ->setLocationId($locationId)
            ->setLocationName('Location '.$locationId)
            ->setLocationType($locationType)
            ->setLocationFlag('Hangar')
            ->setIsSingleton($singleton)
            ->setCharacter($character)
            ->setIsCorporationAsset(false));

        return $itemId;
    }
}
