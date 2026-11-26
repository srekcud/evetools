<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Planetary;

use App\Entity\CachedCharacterBuyOrder;
use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\PlanetSchematic;
use App\Entity\Sde\PlanetSchematicType;
use App\Entity\StructureMarketSnapshot;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1397')]
final class PlanetaryStockPriceApiTest extends WebTestCase
{
    private const string STOCK_PRICES_URI = '/api/planetary/stock-prices';
    private const string MARKET_POOL = 'structure_market.cache';
    private const string JITA_PRICES_KEY = 'jita_market_prices';

    private const int COMMODITIES_GROUP_ID = 996_042;
    private const int STRUCTURES_CATEGORY_ID = 996_066;
    private const int FACTORY_TYPE_ID = 996_473;

    private const int BACTERIA = 996_001;
    private const int REACTIVE_METALS = 996_002;
    private const int OXYGEN = 996_003;
    private const int COOLANT = 996_004;
    private const int MECHANICAL_PARTS = 996_005;

    private const int ALICE_SCHEMATIC = 996_001;
    private const int BOB_SCHEMATIC = 996_002;

    private const int JITA_STATION_ID = 60_003_760;
    private const int HUB_STRUCTURE_ID = 1_049_588_174_021;
    private const string HUB_STRUCTURE_NAME = 'C-J6MT - 1st Taj Mahgoon (Keepstar)';

    private const int ALICE_MAIN_EVE_ID = 2_139_700_001;
    private const int ALICE_SECOND_EVE_ID = 2_139_700_002;
    private const int BOB_EVE_ID = 2_139_700_003;
    private const int DAN_EVE_ID = 2_139_700_004;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $alice;
    private User $bob;
    private User $dan;
    private int $nextOrderId = 1_139_700_900_001;
    private int $nextTransactionId = 6_139_700_001;
    private int $nextPlanetId = 40_139_701;
    private int $nextPinId = 1_039_700_000_001;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->forgetTheMarketCaches();
        $this->seedSde();
        $this->alice = $this->persistPilot('Alice Tarn', self::ALICE_MAIN_EVE_ID);
        $aliceSecond = $this->persistSecondCharacter($this->alice, 'Aldo Tarn', self::ALICE_SECOND_EVE_ID);
        $this->bob = $this->persistPilot('Bob Kess', self::BOB_EVE_ID);
        $this->dan = $this->persistPilot('Dan Orsk', self::DAN_EVE_ID);

        $this->persistFactoryColony($this->alice, self::ALICE_SCHEMATIC);
        $this->persistFactoryColony($this->bob, self::BOB_SCHEMATIC);

        $this->persistPurchase($this->alice->getMainCharacter(), self::BACTERIA, 10, 70.0, '-3 days');
        $this->persistPurchase($this->alice->getMainCharacter(), self::BACTERIA, 30, 84.0, '-2 days');
        $this->persistPurchase($this->bob->getMainCharacter(), self::BACTERIA, 5, 5_000.0, '-2 days');
        $this->persistPurchase($this->bob->getMainCharacter(), self::COOLANT, 7, 333.0, '-2 days');

        $this->persistBuyOrder($this->alice->getMainCharacter(), self::BACTERIA, self::HUB_STRUCTURE_ID, 60.0);
        $this->persistBuyOrder($aliceSecond, self::BACTERIA, self::HUB_STRUCTURE_ID, 65.0);
        $this->persistBuyOrder($this->alice->getMainCharacter(), self::BACTERIA, self::JITA_STATION_ID, 95.0);
        $this->persistBuyOrder($this->bob->getMainCharacter(), self::BACTERIA, self::HUB_STRUCTURE_ID, 999.0);
        $this->persistBuyOrder($this->bob->getMainCharacter(), self::COOLANT, self::HUB_STRUCTURE_ID, 222.0);

        $this->persistSnapshot(self::BACTERIA, '-20 days', 10.0);
        $this->persistSnapshot(self::BACTERIA, '-5 days', 80.0);
        $this->persistSnapshot(self::BACTERIA, '-3 days', null);
        $this->persistSnapshot(self::BACTERIA, '-1 day', 100.0);
        $this->em->flush();
    }

    public function testTheStockPricesRequireAuthentication(): void
    {
        $this->client->request('GET', self::STOCK_PRICES_URI, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testACompleteLineCarriesThePaidPriceTheMarketsMyBuyOrderTheTrendAndTheSignal(): void
    {
        $this->seedTheHubMarket();
        $this->seedTheJitaMarket();

        $body = $this->read($this->alice);

        self::assertSame('stock-prices', $body['id']);
        self::assertSame(self::HUB_STRUCTURE_ID, $body['hubStructureId']);
        self::assertSame(self::HUB_STRUCTURE_NAME, $body['hubStructureName']);
        self::assertSame(90, $body['paidPriceDays']);
        self::assertTrue($body['complete']);
        self::assertSame([self::BACTERIA, self::REACTIVE_METALS], array_column($body['rows'], 'typeId'));

        $bacteria = $this->rowOf($body, self::BACTERIA);
        self::assertSame('Bacteria', $bacteria['typeName']);
        self::assertEqualsWithDelta(80.5, $bacteria['paidPrice'], 0.0001);
        self::assertEqualsWithDelta(100.0, $bacteria['hubSell'], 0.0001);
        self::assertEqualsWithDelta(90.0, $bacteria['jitaSell'], 0.0001);
        self::assertSame(-19.5, $bacteria['paidVsHubPercent']);
        self::assertEqualsWithDelta(65.0, $bacteria['myBuyOrder'], 0.0001);
        self::assertSame(-35.0, $bacteria['buyOrderVsHubPercent']);
        self::assertEqualsWithDelta(70.0, $bacteria['hubBestBuy'], 0.0001);
        self::assertSame(25.0, $bacteria['trend7dPercent']);
        self::assertSame('outbid', $bacteria['signal']);
    }

    public function testATypeNeverBoughtNorOrderedNorInTheHubCacheKeepsEveryUnknownFigureNull(): void
    {
        $this->seedTheHubMarket();
        $this->seedTheJitaMarket();

        $metals = $this->rowOf($this->read($this->alice), self::REACTIVE_METALS);

        self::assertSame('Reactive Metals', $metals['typeName']);
        self::assertEqualsWithDelta(35.5, $metals['jitaSell'], 0.0001);
        foreach (['paidPrice', 'hubSell', 'paidVsHubPercent', 'myBuyOrder', 'buyOrderVsHubPercent', 'trend7dPercent', 'hubBestBuy', 'signal'] as $field) {
            self::assertArrayHasKey($field, $metals);
            self::assertNull($metals[$field], $field);
        }
    }

    public function testAnAbsentHubCacheLeavesTheHubFiguresNullAndTheLineIncomplete(): void
    {
        $this->seedTheJitaMarket();

        $body = $this->read($this->alice);

        self::assertFalse($body['complete']);
        $bacteria = $this->rowOf($body, self::BACTERIA);
        foreach (['hubSell', 'hubBestBuy', 'paidVsHubPercent', 'buyOrderVsHubPercent'] as $field) {
            self::assertArrayHasKey($field, $bacteria);
            self::assertNull($bacteria[$field], $field);
        }
        self::assertEqualsWithDelta(80.5, $bacteria['paidPrice'], 0.0001);
        self::assertEqualsWithDelta(90.0, $bacteria['jitaSell'], 0.0001);
        self::assertEqualsWithDelta(65.0, $bacteria['myBuyOrder'], 0.0001);
        self::assertSame(25.0, $bacteria['trend7dPercent']);
        self::assertArrayHasKey('signal', $bacteria);
        self::assertNull($bacteria['signal']);
    }

    public function testAnAbsentJitaCacheLeavesTheJitaSellNullAndTheLineIncomplete(): void
    {
        $this->seedTheHubMarket();

        $body = $this->read($this->alice);

        self::assertFalse($body['complete']);
        $bacteria = $this->rowOf($body, self::BACTERIA);
        self::assertArrayHasKey('jitaSell', $bacteria);
        self::assertNull($bacteria['jitaSell']);
        self::assertEqualsWithDelta(100.0, $bacteria['hubSell'], 0.0001);
    }

    public function testAnotherUserSeesTheirOwnPurchasesAndOrdersAndNothingOfTheFirst(): void
    {
        $this->seedTheHubMarket();
        $this->seedTheJitaMarket();

        $body = $this->read($this->bob);

        self::assertSame([self::BACTERIA, self::COOLANT], array_column($body['rows'], 'typeId'));
        $bacteria = $this->rowOf($body, self::BACTERIA);
        self::assertEqualsWithDelta(5_000.0, $bacteria['paidPrice'], 0.0001);
        self::assertEqualsWithDelta(999.0, $bacteria['myBuyOrder'], 0.0001);
        $coolant = $this->rowOf($body, self::COOLANT);
        self::assertEqualsWithDelta(333.0, $coolant['paidPrice'], 0.0001);
        self::assertEqualsWithDelta(222.0, $coolant['myBuyOrder'], 0.0001);
    }

    public function testTheUnsyncedWalletsOfTheUserAreListedBecauseThePaidPriceMayBeIncomplete(): void
    {
        $this->seedTheHubMarket();
        $this->seedTheJitaMarket();

        $body = $this->read($this->alice);

        self::assertEqualsCanonicalizing(
            [
                ['characterId' => self::ALICE_MAIN_EVE_ID, 'characterName' => 'Alice Tarn', 'status' => 'no_token'],
                ['characterId' => self::ALICE_SECOND_EVE_ID, 'characterName' => 'Aldo Tarn', 'status' => 'no_token'],
            ],
            $body['unsyncedWallets'],
        );
    }

    public function testAUserWithoutColonyGetsNoRow(): void
    {
        $this->seedTheHubMarket();
        $this->seedTheJitaMarket();

        $body = $this->read($this->dan);

        self::assertArrayHasKey('rows', $body);
        self::assertSame([], $body['rows']);
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
        $this->client->request('GET', self::STOCK_PRICES_URI, server: ['HTTP_ACCEPT' => 'application/json']);

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

    private function marketPool(): CacheItemPoolInterface
    {
        $pool = self::getContainer()->get(self::MARKET_POOL);
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);

        return $pool;
    }

    private function forgetTheMarketCaches(): void
    {
        $pool = $this->marketPool();
        $pool->deleteItem('structure_market_'.self::HUB_STRUCTURE_ID);
        $pool->deleteItem('structure_market_'.self::HUB_STRUCTURE_ID.'_meta');
        $pool->deleteItem(self::JITA_PRICES_KEY);
    }

    private function seedTheHubMarket(): void
    {
        $pool = $this->marketPool();
        $pool->save($pool->getItem('structure_market_'.self::HUB_STRUCTURE_ID)->set([
            self::BACTERIA => ['sell' => 100.0, 'buy' => 70.0],
            self::COOLANT => ['sell' => 410.0, 'buy' => 200.0],
        ]));
    }

    private function seedTheJitaMarket(): void
    {
        $pool = $this->marketPool();
        $pool->save($pool->getItem(self::JITA_PRICES_KEY)->set([
            self::BACTERIA => [['price' => 90.0, 'volume' => 1_000]],
            self::REACTIVE_METALS => [['price' => 35.5, 'volume' => 800]],
            self::COOLANT => [['price' => 380.0, 'volume' => 50]],
        ]));
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

        $this->persistSchematic(self::ALICE_SCHEMATIC, [self::BACTERIA => 40, self::REACTIVE_METALS => 25], self::OXYGEN);
        $this->persistSchematic(self::BOB_SCHEMATIC, [self::COOLANT => 10, self::BACTERIA => 8], self::MECHANICAL_PARTS);
        $this->em->flush();
    }

    private function persistType(int $typeId, string $typeName, InvGroup $group, ?float $volume): void
    {
        $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($typeName)->setGroup($group)->setVolume($volume)->setPublished(true));
    }

    /**
     * @param array<int, int> $inputs
     */
    private function persistSchematic(int $schematicId, array $inputs, int $outputTypeId): void
    {
        $schematic = (new PlanetSchematic())->setSchematicId($schematicId)->setSchematicName('Schematic '.$schematicId)->setCycleTime(3600);
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
            ->setCorporationId(98_539_701)
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
            ->setCorporationId(98_539_701)
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
            ->setPlanetName('Planet '.$character->getName())
            ->setPlanetType('temperate')
            ->setSolarSystemId(30_001_538)
            ->setSolarSystemName('Aldranette')
            ->setUpgradeLevel(4)
            ->setNumPins(1)
            ->setLastUpdate(new \DateTimeImmutable());
        $colony->addPin((new PlanetaryPin())->setPinId($this->nextPinId++)->setTypeId(self::FACTORY_TYPE_ID)->setSchematicId($schematicId));
        $this->em->persist($colony);
        $this->em->flush();
    }

    private function persistPurchase(Character $character, int $typeId, int $quantity, float $unitPrice, string $relativeDate): void
    {
        $this->em->persist(
            (new CachedWalletTransaction())
                ->setCharacter($character)
                ->setTransactionId($this->nextTransactionId++)
                ->setIsBuy(true)
                ->setTypeId($typeId)
                ->setQuantity($quantity)
                ->setUnitPrice($unitPrice)
                ->setDate(new \DateTimeImmutable($relativeDate))
                ->setLocationId(self::JITA_STATION_ID),
        );
    }

    private function persistBuyOrder(Character $character, int $typeId, int $locationId, float $price): void
    {
        $this->em->persist(
            (new CachedCharacterBuyOrder())
                ->setCharacter($character)
                ->setOrderId($this->nextOrderId++)
                ->setTypeId($typeId)
                ->setLocationId($locationId)
                ->setPrice($price)
                ->setVolumeRemain(100)
                ->setVolumeTotal(110)
                ->setIssuedAt(new \DateTimeImmutable('-1 day'))
                ->setSyncedAt(new \DateTimeImmutable()),
        );
    }

    private function persistSnapshot(int $typeId, string $relativeDate, ?float $sellMin): void
    {
        $this->em->persist(
            (new StructureMarketSnapshot())
                ->setStructureId(self::HUB_STRUCTURE_ID)
                ->setTypeId($typeId)
                ->setDate(new \DateTimeImmutable($relativeDate))
                ->setSellMin($sellMin),
        );
    }
}
