<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Planetary;

use App\Entity\Character;
use App\Entity\MarketPriceHistory;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvMarketGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\PlanetSchematic;
use App\Entity\Sde\PlanetSchematicType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1404')]
final class PlanetaryPlannerMarketApiTest extends WebTestCase
{
    private const int PILOT_EVE_CHARACTER_ID = 2_112_940_301;
    private const int BASIC_COMMODITIES_MARKET_GROUP_ID = 1334;
    private const int REFINED_COMMODITIES_MARKET_GROUP_ID = 1335;
    private const int CATEGORY_ID = 990_900;
    private const int GROUP_ID = 990_901;
    private const int THE_FORGE = 10_000_002;
    private const int DOMAIN = 10_000_043;
    private const int AQUEOUS_LIQUIDS = 990_310;
    private const int WATER = 990_311;
    private const int COOLANT = 990_321;
    private const int MECHANICAL_PARTS = 990_322;
    private const int SILICATE_GLASS = 990_323;
    private const int WATER_SCHEMATIC_ID = 990_330;
    private const int COOLANT_SCHEMATIC_ID = 990_331;
    private const int MECHANICAL_PARTS_SCHEMATIC_ID = 990_332;
    private const int SILICATE_GLASS_SCHEMATIC_ID = 990_333;
    private const string FIRST_DAY = '2026-02-01';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $pilot;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->pilot = $this->createPilot();
        $this->seedPlanetarySchematics();
        $this->seedHistory();
    }

    public function testTheMarketRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/planetary/planner/market', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheForgeAndTheFixedIdentifierComeBackWithTheStatsOfTheSeededP2(): void
    {
        $body = $this->readMarket();

        foreach (['id', 'regionId', 'products'] as $key) {
            self::assertArrayHasKey($key, $body);
        }
        self::assertSame('market', $body['id']);
        self::assertSame(self::THE_FORGE, $body['regionId']);
        $coolant = $this->lineOf($body['products'], self::COOLANT);
        self::assertEquals(20, $coolant['averageDailyVolume']);
        self::assertEquals(110, $coolant['averagePrice']);
        self::assertEquals(10, $coolant['priceChange']);
        self::assertSame('2026-04-01', $coolant['lastDate']);
    }

    public function testAnotherRegionHistoryDoesNotChangeTheStats(): void
    {
        $body = $this->readMarket();

        $coolant = $this->lineOf($body['products'], self::COOLANT);

        self::assertEquals(20, $coolant['averageDailyVolume']);
        self::assertEquals(110, $coolant['averagePrice']);
    }

    public function testAP2WithThirtyEntriesOnlyHasAnUnknownPriceChangeThatIsPresentAndNull(): void
    {
        $body = $this->readMarket();

        $mechanicalParts = $this->lineOf($body['products'], self::MECHANICAL_PARTS);

        self::assertEquals(3_400, $mechanicalParts['averageDailyVolume']);
        self::assertEquals(2_190, $mechanicalParts['averagePrice']);
        self::assertArrayHasKey('priceChange', $mechanicalParts);
        self::assertNull($mechanicalParts['priceChange']);
        self::assertSame('2026-03-02', $mechanicalParts['lastDate']);
    }

    public function testAP2WithoutHistoryAndTheP1WithHistoryAreAbsent(): void
    {
        $body = $this->readMarket();

        $typeIds = array_column($body['products'], 'typeId');

        self::assertNotContains(self::SILICATE_GLASS, $typeIds);
        self::assertNotContains(self::WATER, $typeIds);
        self::assertContains(self::COOLANT, $typeIds);
        self::assertContains(self::MECHANICAL_PARTS, $typeIds);
    }

    public function testTheProductsAreSortedByTypeId(): void
    {
        $body = $this->readMarket();

        $typeIds = array_column($body['products'], 'typeId');
        $sorted = $typeIds;
        sort($sorted);

        self::assertSame([self::COOLANT, self::MECHANICAL_PARTS], array_values(array_intersect($typeIds, [self::MECHANICAL_PARTS, self::COOLANT])));
        self::assertSame($sorted, $typeIds);
    }

    private function readMarket(): array
    {
        $this->client->loginUser($this->pilot, 'api');
        $this->client->request('GET', '/api/planetary/planner/market', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function lineOf(array $products, int $typeId): array
    {
        $matching = array_values(array_filter($products, static fn (array $product): bool => $product['typeId'] === $typeId));
        self::assertCount(1, $matching);

        return $matching[0];
    }

    private function marketGroup(int $marketGroupId, string $name): InvMarketGroup
    {
        $marketGroup = $this->em->find(InvMarketGroup::class, $marketGroupId);
        if ($marketGroup === null) {
            $marketGroup = (new InvMarketGroup())->setMarketGroupId($marketGroupId)->setMarketGroupName($name);
            $this->em->persist($marketGroup);
        }

        return $marketGroup;
    }

    private function seedPlanetarySchematics(): void
    {
        $basicCommodities = $this->marketGroup(self::BASIC_COMMODITIES_MARKET_GROUP_ID, 'Basic Commodities');
        $refinedCommodities = $this->marketGroup(self::REFINED_COMMODITIES_MARKET_GROUP_ID, 'Refined Commodities');
        $category = (new InvCategory())->setCategoryId(self::CATEGORY_ID)->setCategoryName('Test Planetary Commodities');
        $group = (new InvGroup())->setGroupId(self::GROUP_ID)->setGroupName('Test Commodities')->setCategory($category);
        $types = [
            (new InvType())->setTypeId(self::AQUEOUS_LIQUIDS)->setTypeName('Test Aqueous Liquids')->setGroup($group)->setVolume(0.01),
            (new InvType())->setTypeId(self::WATER)->setTypeName('Test Water')->setGroup($group)->setVolume(0.19)->setMarketGroup($basicCommodities),
            (new InvType())->setTypeId(self::COOLANT)->setTypeName('Test Coolant')->setGroup($group)->setVolume(1.5)->setMarketGroup($refinedCommodities),
            (new InvType())->setTypeId(self::MECHANICAL_PARTS)->setTypeName('Test Mechanical Parts')->setGroup($group)->setVolume(1.5)->setMarketGroup($refinedCommodities),
            (new InvType())->setTypeId(self::SILICATE_GLASS)->setTypeName('Test Silicate Glass')->setGroup($group)->setVolume(1.5)->setMarketGroup($refinedCommodities),
        ];
        foreach ([$category, $group, ...$types] as $entity) {
            $this->em->persist($entity);
        }

        $schematics = [
            [self::WATER_SCHEMATIC_ID, 'Test Water', 1800, self::AQUEOUS_LIQUIDS, 3000, self::WATER, 20],
            [self::COOLANT_SCHEMATIC_ID, 'Test Coolant', 3600, self::WATER, 40, self::COOLANT, 5],
            [self::MECHANICAL_PARTS_SCHEMATIC_ID, 'Test Mechanical Parts', 3600, self::WATER, 20, self::MECHANICAL_PARTS, 6],
            [self::SILICATE_GLASS_SCHEMATIC_ID, 'Test Silicate Glass', 3600, self::WATER, 30, self::SILICATE_GLASS, 4],
        ];
        foreach ($schematics as [$schematicId, $name, $cycleTime, $inputTypeId, $inputQuantity, $outputTypeId, $outputQuantity]) {
            $schematic = (new PlanetSchematic())->setSchematicId($schematicId)->setSchematicName($name)->setCycleTime($cycleTime);
            $this->em->persist($schematic);
            $this->em->persist((new PlanetSchematicType())->setSchematic($schematic)->setTypeId($inputTypeId)->setIsInput(true)->setQuantity($inputQuantity));
            $this->em->persist((new PlanetSchematicType())->setSchematic($schematic)->setTypeId($outputTypeId)->setIsInput(false)->setQuantity($outputQuantity));
        }
        $this->em->flush();
    }

    private function seedHistory(): void
    {
        $this->persistEntries(self::COOLANT, self::THE_FORGE, 30, 100.0, 10, 0);
        $this->persistEntries(self::COOLANT, self::THE_FORGE, 30, 110.0, 20, 30);
        $this->persistEntries(self::COOLANT, self::DOMAIN, 30, 5_000.0, 999, 0);
        $this->persistEntries(self::MECHANICAL_PARTS, self::THE_FORGE, 30, 2_190.0, 3_400, 0);
        $this->persistEntries(self::WATER, self::THE_FORGE, 30, 61.0, 880_000, 0);
        $this->em->flush();
    }

    private function persistEntries(int $typeId, int $regionId, int $count, float $average, int $volume, int $firstOffset): void
    {
        for ($position = 0; $position < $count; ++$position) {
            $this->em->persist(
                (new MarketPriceHistory())
                    ->setTypeId($typeId)
                    ->setRegionId($regionId)
                    ->setDate((new \DateTime(self::FIRST_DAY))->modify(sprintf('+%d days', $firstOffset + $position)))
                    ->setAverage($average)
                    ->setHighest($average + 4.0)
                    ->setLowest($average - 4.0)
                    ->setOrderCount(15)
                    ->setVolume($volume),
            );
        }
    }

    private function createPilot(): User
    {
        $character = (new Character())
            ->setEveCharacterId(self::PILOT_EVE_CHARACTER_ID)
            ->setName('Ilyra Market')
            ->setCorporationId(98_900_003)
            ->setCorporationName('Esk Planetary Brokers');
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }
}
