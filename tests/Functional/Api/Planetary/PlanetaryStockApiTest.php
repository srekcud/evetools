<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Planetary;

use App\Entity\CachedAsset;
use App\Entity\CachedCharacterBuyOrder;
use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
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

#[Group('issue-1395')]
final class PlanetaryStockApiTest extends WebTestCase
{
    private const string STOCK_URI = '/api/planetary/stock';

    private const int COMMODITIES_GROUP_ID = 995_042;
    private const int STRUCTURES_CATEGORY_ID = 995_066;
    private const int CONTAINER_TYPE_ID = 17_366;

    private const int BACTERIA = 995_001;
    private const int REACTIVE_METALS = 995_002;
    private const int OXYGEN = 995_003;
    private const int COOLANT = 995_004;
    private const int MECHANICAL_PARTS = 995_005;
    private const int FACTORY_TYPE_ID = 995_473;

    private const int ALICE_CAROL_SCHEMATIC = 995_001;
    private const int BOB_SCHEMATIC = 995_002;

    private const int JITA_STATION_ID = 60_003_760;
    private const string JITA_STATION_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant';
    private const int AMARR_STATION_ID = 60_008_494;
    private const int HUB_STRUCTURE_ID = 1_049_588_174_021;
    private const string HUB_STRUCTURE_NAME = 'C-J6MT - 1st Taj Mahgoon (Keepstar)';

    private const int ALICE_MAIN_EVE_ID = 2_113_950_001;
    private const int ALICE_SECOND_EVE_ID = 2_113_950_002;
    private const int BOB_EVE_ID = 2_113_950_003;
    private const int CAROL_EVE_ID = 2_113_950_004;
    private const int DAN_EVE_ID = 2_113_950_005;
    private const int ERIN_EVE_ID = 2_113_950_006;
    private const int ERIN_SECOND_EVE_ID = 2_113_950_007;

    private const int UNRESOLVED_CUSTOMS_OFFICE_ID = 1_020_000_000_001;
    private const int UNRESOLVED_LOCATION_ID = 1_020_000_000_002;
    private const int NAMED_STRUCTURE_ID = 1_020_000_000_003;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $alice;
    private User $bob;
    private User $carol;
    private User $dan;
    private User $erin;
    private int $nextItemId = 1_139_500_000_001;
    private int $nextOrderId = 1_139_500_900_001;
    private int $nextPlanetId = 40_139_501;
    private int $nextPinId = 1_039_500_000_001;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->seedSde();
        $this->alice = $this->persistPilot('Alice Tarn', self::ALICE_MAIN_EVE_ID);
        $aliceSecond = $this->persistSecondCharacter($this->alice, 'Aldo Tarn', self::ALICE_SECOND_EVE_ID);
        $this->bob = $this->persistPilot('Bob Kess', self::BOB_EVE_ID);
        $this->carol = $this->persistPilot('Carol Vane', self::CAROL_EVE_ID);
        $this->dan = $this->persistPilot('Dan Orsk', self::DAN_EVE_ID);
        $this->erin = $this->persistPilot('Erin Holt', self::ERIN_EVE_ID);
        $erinSecond = $this->persistSecondCharacter($this->erin, 'Eli Holt', self::ERIN_SECOND_EVE_ID);

        $this->alice->setPlanetaryHomeLocation(self::JITA_STATION_ID, self::JITA_STATION_NAME);
        $this->carol->setPlanetaryHomeLocation(self::JITA_STATION_ID, self::JITA_STATION_NAME);
        $this->erin->setPlanetaryHomeLocation(self::JITA_STATION_ID, self::JITA_STATION_NAME);
        $this->em->flush();

        $this->persistFactoryColony($this->alice, self::ALICE_CAROL_SCHEMATIC);
        $this->persistFactoryColony($this->bob, self::BOB_SCHEMATIC);
        $this->persistFactoryColony($this->carol, self::ALICE_CAROL_SCHEMATIC);
        $this->persistFactoryColony($this->erin, self::ALICE_CAROL_SCHEMATIC);

        $this->persistAliceStock($this->alice->getMainCharacter(), $aliceSecond);
        $this->persistBobStock($this->bob->getMainCharacter());
        $this->persistErinStock($this->erin->getMainCharacter(), $erinSecond);
    }

    public function testTheStockRequiresAuthentication(): void
    {
        $this->client->request('GET', self::STOCK_URI, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testACompleteLineIsComputedFromTheAssetsContainersAndOrdersOfAllTheCharactersOfTheUser(): void
    {
        $body = $this->read($this->alice);

        self::assertSame('stock', $body['id']);
        self::assertSame(self::JITA_STATION_ID, $body['homeLocationId']);
        self::assertSame(self::JITA_STATION_NAME, $body['homeLocationName']);
        self::assertSame(self::HUB_STRUCTURE_ID, $body['hubStructureId']);
        self::assertSame(self::HUB_STRUCTURE_NAME, $body['hubStructureName']);
        self::assertSame(3, $body['coverageWeeks']);
        self::assertArrayHasKey('corpUnknownReason', $body);
        self::assertNull($body['corpUnknownReason']);
        self::assertTrue($body['complete']);
        self::assertCount(2, $body['rows']);
        self::assertSame([self::BACTERIA, self::REACTIVE_METALS], array_column($body['rows'], 'typeId'));

        $bacteria = $this->rowOf($body, self::BACTERIA);
        self::assertSame('Bacteria', $bacteria['typeName']);
        self::assertEqualsWithDelta(0.38, $bacteria['volumePerUnit'], 0.000001);
        self::assertEqualsWithDelta(6720.0, $bacteria['weeklyConsumption'], 0.000001);
        self::assertSame([3000, 500, 0, 400, 16260], [$bacteria['home'], $bacteria['hub'], $bacteria['corp'], $bacteria['openOrders'], $bacteria['toBuy']]);
        self::assertEqualsWithDelta(0.5208333, $bacteria['coverageWeeks'], 0.0000001);
        self::assertEqualsWithDelta(0.5803571, $bacteria['coverageWithOrdersWeeks'], 0.0000001);

        $metals = $this->rowOf($body, self::REACTIVE_METALS);
        self::assertSame('Reactive Metals', $metals['typeName']);
        self::assertEqualsWithDelta(0.52, $metals['volumePerUnit'], 0.000001);
        self::assertEqualsWithDelta(4200.0, $metals['weeklyConsumption'], 0.000001);
        self::assertSame([13_300, 200, 0, 150, 0], [$metals['home'], $metals['hub'], $metals['corp'], $metals['openOrders'], $metals['toBuy']]);
        self::assertEqualsWithDelta(3.2142857, $metals['coverageWeeks'], 0.0000001);
        self::assertEqualsWithDelta(3.25, $metals['coverageWithOrdersWeeks'], 0.0000001);
    }

    public function testAnotherUserSeesTheirOwnFiguresAndNothingOfTheAssetsContainersAndOrdersOfTheFirst(): void
    {
        $body = $this->read($this->bob);

        self::assertSame([self::BACTERIA, self::COOLANT], array_column($body['rows'], 'typeId'));
        $bacteria = $this->rowOf($body, self::BACTERIA);
        self::assertEqualsWithDelta(1344.0, $bacteria['weeklyConsumption'], 0.000001);
        self::assertSame([90, 9_000], [$bacteria['hub'], $bacteria['openOrders']]);
        $coolant = $this->rowOf($body, self::COOLANT);
        self::assertEqualsWithDelta(1680.0, $coolant['weeklyConsumption'], 0.000001);
        self::assertEqualsWithDelta(1.75, $coolant['volumePerUnit'], 0.000001);
        self::assertSame([55, 66], [$coolant['openOrders'], $coolant['hub']]);
    }

    public function testNullsAreSerializedAsExplicitNullsWhenTheHomeLocationIsNotChosen(): void
    {
        $body = $this->read($this->bob);

        self::assertArrayHasKey('homeLocationId', $body);
        self::assertNull($body['homeLocationId']);
        self::assertArrayHasKey('homeLocationName', $body);
        self::assertNull($body['homeLocationName']);
        self::assertArrayHasKey('corpUnknownReason', $body);
        self::assertNull($body['corpUnknownReason']);
        self::assertFalse($body['complete']);
        $bacteria = $this->rowOf($body, self::BACTERIA);
        foreach (['home', 'corp', 'coverageWeeks', 'coverageWithOrdersWeeks', 'toBuy'] as $field) {
            self::assertArrayHasKey($field, $bacteria);
            self::assertNull($bacteria[$field], $field);
        }
        self::assertArrayHasKey('weeklyConsumption', $bacteria);
        self::assertEqualsWithDelta(1344.0, $bacteria['weeklyConsumption'], 0.000001);
    }

    public function testNothingInStockAnywhereCountsZeroAndEverythingIsToBuy(): void
    {
        $body = $this->read($this->carol);

        self::assertTrue($body['complete']);
        self::assertSame([self::BACTERIA, self::REACTIVE_METALS], array_column($body['rows'], 'typeId'));
        $bacteria = $this->rowOf($body, self::BACTERIA);
        self::assertSame([0, 0, 0, 0, 20_160], [$bacteria['home'], $bacteria['hub'], $bacteria['corp'], $bacteria['openOrders'], $bacteria['toBuy']]);
        self::assertEqualsWithDelta(0.0, $bacteria['coverageWeeks'], 0.000001);
        self::assertEqualsWithDelta(0.0, $bacteria['coverageWithOrdersWeeks'], 0.000001);
        $metals = $this->rowOf($body, self::REACTIVE_METALS);
        self::assertSame([0, 0, 0, 0, 12_600], [$metals['home'], $metals['hub'], $metals['corp'], $metals['openOrders'], $metals['toBuy']]);
    }

    public function testAUserWithoutColonyGetsNoRowAndAnIncompleteStockWithoutHome(): void
    {
        $body = $this->read($this->dan);

        self::assertArrayHasKey('rows', $body);
        self::assertSame([], $body['rows']);
        self::assertFalse($body['complete']);
        self::assertNull($body['homeLocationId']);
    }

    #[Group('issue-1390')]
    public function testCustomsOfficeStockOfAllTheCharactersIsHeldStockInTheCoverageAndWhatToBuy(): void
    {
        $body = $this->read($this->erin);

        self::assertTrue($body['complete']);
        self::assertSame([self::BACTERIA, self::REACTIVE_METALS], array_column($body['rows'], 'typeId'));

        $bacteria = $this->rowOf($body, self::BACTERIA);
        self::assertArrayHasKey('customsOffice', $bacteria);
        self::assertSame([1_000, 0, 0, 2_000, 0, 17_160], [$bacteria['home'], $bacteria['hub'], $bacteria['corp'], $bacteria['customsOffice'], $bacteria['openOrders'], $bacteria['toBuy']]);
        self::assertEqualsWithDelta(0.4464286, $bacteria['coverageWeeks'], 0.0000001);
        self::assertEqualsWithDelta(0.4464286, $bacteria['coverageWithOrdersWeeks'], 0.0000001);

        $metals = $this->rowOf($body, self::REACTIVE_METALS);
        self::assertArrayHasKey('customsOffice', $metals);
        self::assertSame([0, 0, 0, 80, 0, 12_520], [$metals['home'], $metals['hub'], $metals['corp'], $metals['customsOffice'], $metals['openOrders'], $metals['toBuy']]);
        self::assertEqualsWithDelta(0.0190476, $metals['coverageWeeks'], 0.0000001);
    }

    #[Group('issue-1390')]
    public function testUsersWithoutCustomsOfficeStockGetAZeroOnEveryRowAndNeverTheStockOfAnotherUser(): void
    {
        $body = $this->read($this->alice);

        self::assertSame([self::BACTERIA, self::REACTIVE_METALS], array_column($body['rows'], 'typeId'));
        foreach ($body['rows'] as $row) {
            self::assertArrayHasKey('customsOffice', $row);
            self::assertSame(0, $row['customsOffice']);
        }
        self::assertSame(16_260, $this->rowOf($body, self::BACTERIA)['toBuy']);
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
        $this->client->request('GET', self::STOCK_URI, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function rowOf(array $body, int $typeId): array
    {
        foreach ($body['rows'] as $row) {
            if ($row['typeId'] === $typeId) {
                return $row;
            }
        }

        self::fail(\sprintf('No row for type %d', $typeId));
    }

    private function seedSde(): void
    {
        $category = (new InvCategory())->setCategoryId(self::STRUCTURES_CATEGORY_ID)->setCategoryName('Planetary Infrastructure');
        $this->em->persist($category);
        $commodities = (new InvGroup())->setGroupId(self::COMMODITIES_GROUP_ID)->setGroupName('Basic Commodities')->setCategory($category);
        $this->em->persist($commodities);

        $this->persistType(self::FACTORY_TYPE_ID, 'Basic Industry Facility', $commodities, null);
        $this->persistType(self::BACTERIA, 'Bacteria', $commodities, 0.38);
        $this->persistType(self::REACTIVE_METALS, 'Reactive Metals', $commodities, 0.52);
        $this->persistType(self::OXYGEN, 'Oxygen', $commodities, 1.5);
        $this->persistType(self::COOLANT, 'Coolant', $commodities, 1.75);
        $this->persistType(self::MECHANICAL_PARTS, 'Mechanical Parts', $commodities, 0.8);

        $this->persistSchematic(self::ALICE_CAROL_SCHEMATIC, 3600, [self::BACTERIA => 40, self::REACTIVE_METALS => 25], self::OXYGEN);
        $this->persistSchematic(self::BOB_SCHEMATIC, 3600, [self::COOLANT => 10, self::BACTERIA => 8], self::MECHANICAL_PARTS);
        $this->em->flush();
    }

    private function persistType(int $typeId, string $typeName, InvGroup $group, ?float $volume): void
    {
        $this->em->persist(
            (new InvType())
                ->setTypeId($typeId)
                ->setTypeName($typeName)
                ->setGroup($group)
                ->setVolume($volume)
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

    private function persistPilot(string $name, int $eveCharacterId): User
    {
        $user = new User();
        $this->em->persist($user);
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(98_539_501)
            ->setCorporationName('Verhoeven Deep Core');
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }

    private function persistSecondCharacter(User $user, string $name, int $eveCharacterId): Character
    {
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(98_539_501)
            ->setCorporationName('Verhoeven Deep Core');
        $user->addCharacter($character);
        $this->em->persist($character);
        $this->em->flush();

        return $character;
    }

    private function persistFactoryColony(User $user, int $schematicId): void
    {
        $character = $user->getMainCharacter();
        self::assertNotNull($character);

        $colony = (new PlanetaryColony())
            ->setCharacter($character)
            ->setPlanetId($this->nextPlanetId++)
            ->setPlanetName('Planet '.$user->getMainCharacter()->getName())
            ->setPlanetType('temperate')
            ->setSolarSystemId(30_001_538)
            ->setSolarSystemName('Aldranette')
            ->setUpgradeLevel(4)
            ->setNumPins(1)
            ->setLastUpdate(new \DateTimeImmutable());
        $colony->addPin((new PlanetaryPin())
            ->setPinId($this->nextPinId++)
            ->setTypeId(self::FACTORY_TYPE_ID)
            ->setSchematicId($schematicId));
        $this->em->persist($colony);
        $this->em->flush();
    }

    private function persistAliceStock(Character $main, Character $second): void
    {
        $box = $this->persistAsset($main, self::CONTAINER_TYPE_ID, 1, self::JITA_STATION_ID, 'station', true);
        $this->persistAsset($main, self::BACTERIA, 2_000, self::JITA_STATION_ID, 'station');
        $this->persistAsset($main, self::BACTERIA, 700, $box, 'item');
        $this->persistAsset($main, self::REACTIVE_METALS, 300, $box, 'item');
        $this->persistAsset($main, self::REACTIVE_METALS, 12_000, self::JITA_STATION_ID, 'station');
        $this->persistAsset($main, self::BACTERIA, 500, self::HUB_STRUCTURE_ID, 'item');
        $this->persistAsset($main, self::BACTERIA, 4_000, self::AMARR_STATION_ID, 'station');
        $this->persistAsset($second, self::BACTERIA, 300, self::JITA_STATION_ID, 'station');
        $this->persistAsset($second, self::REACTIVE_METALS, 1_000, self::JITA_STATION_ID, 'station');
        $this->persistAsset($second, self::REACTIVE_METALS, 200, self::HUB_STRUCTURE_ID, 'item');

        $this->persistBuyOrder($main, self::BACTERIA, self::HUB_STRUCTURE_ID, 300);
        $this->persistBuyOrder($second, self::BACTERIA, self::HUB_STRUCTURE_ID, 100);
        $this->persistBuyOrder($main, self::BACTERIA, self::JITA_STATION_ID, 250);
        $this->persistBuyOrder($main, self::REACTIVE_METALS, self::HUB_STRUCTURE_ID, 150);
        $this->em->flush();
    }

    private function persistBobStock(Character $main): void
    {
        $this->persistAsset($main, self::BACTERIA, 8_000, self::JITA_STATION_ID, 'station');
        $this->persistAsset($main, self::BACTERIA, 90, self::HUB_STRUCTURE_ID, 'item');
        $this->persistAsset($main, self::COOLANT, 777, self::JITA_STATION_ID, 'station');
        $this->persistAsset($main, self::COOLANT, 66, self::HUB_STRUCTURE_ID, 'item');
        $this->persistBuyOrder($main, self::BACTERIA, self::HUB_STRUCTURE_ID, 9_000);
        $this->persistBuyOrder($main, self::COOLANT, self::HUB_STRUCTURE_ID, 55);
        $this->em->flush();
    }

    private function persistErinStock(Character $main, Character $second): void
    {
        $customsOfficeName = 'Structure #'.self::UNRESOLVED_CUSTOMS_OFFICE_ID;
        $box = $this->persistAsset($main, self::CONTAINER_TYPE_ID, 1, self::UNRESOLVED_CUSTOMS_OFFICE_ID, 'other', true, $customsOfficeName);
        $this->persistAsset($main, self::BACTERIA, 1_200, self::UNRESOLVED_CUSTOMS_OFFICE_ID, 'other', false, $customsOfficeName);
        $this->persistAsset($main, self::BACTERIA, 300, $box, 'item', false, $customsOfficeName);
        $this->persistAsset($main, self::REACTIVE_METALS, 80, self::UNRESOLVED_LOCATION_ID, 'other', false, 'Location #'.self::UNRESOLVED_LOCATION_ID);
        $this->persistAsset($main, self::BACTERIA, 4_000, self::NAMED_STRUCTURE_ID, 'other', false, 'Ashab - Market Fortizar');
        $this->persistAsset($main, self::BACTERIA, 1_000, self::JITA_STATION_ID, 'station');
        $this->persistAsset($second, self::BACTERIA, 500, self::UNRESOLVED_CUSTOMS_OFFICE_ID, 'other', false, $customsOfficeName);
        $this->em->flush();
    }

    private function persistAsset(Character $character, int $typeId, int $quantity, int $locationId, string $locationType, bool $singleton = false, ?string $locationName = null): int
    {
        $itemId = $this->nextItemId++;
        $this->em->persist((new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId($typeId)
            ->setTypeName('Type '.$typeId)
            ->setQuantity($quantity)
            ->setLocationId($locationId)
            ->setLocationName($locationName ?? 'Location '.$locationId)
            ->setLocationType($locationType)
            ->setLocationFlag('Hangar')
            ->setIsSingleton($singleton)
            ->setCharacter($character)
            ->setIsCorporationAsset(false));

        return $itemId;
    }

    private function persistBuyOrder(Character $character, int $typeId, int $locationId, int $volumeRemain): void
    {
        $this->em->persist((new CachedCharacterBuyOrder())
            ->setCharacter($character)
            ->setOrderId($this->nextOrderId++)
            ->setTypeId($typeId)
            ->setLocationId($locationId)
            ->setPrice(12.5)
            ->setVolumeRemain($volumeRemain)
            ->setVolumeTotal($volumeRemain + 10)
            ->setIssuedAt(new \DateTimeImmutable('2026-11-20T10:00:00+00:00'))
            ->setSyncedAt(new \DateTimeImmutable('2026-11-26T10:00:00+00:00')));
    }
}
