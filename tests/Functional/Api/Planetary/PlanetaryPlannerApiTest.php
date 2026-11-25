<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Planetary;

use App\Entity\Character;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvMarketGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\PlanetSchematic;
use App\Entity\Sde\PlanetSchematicType;
use App\Entity\User;
use App\Service\JitaMarketService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1402')]
final class PlanetaryPlannerApiTest extends WebTestCase
{
    private const int PILOT_EVE_CHARACTER_ID = 2_112_940_201;
    private const int BASIC_COMMODITIES_MARKET_GROUP_ID = 1334;
    private const int CATEGORY_ID = 990_900;
    private const int GROUP_ID = 990_901;
    private const int AQUEOUS_LIQUIDS = 990_010;
    private const int WATER = 990_011;
    private const int WATER_SCHEMATIC_ID = 990_100;

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
        $this->seedWaterSchematic();
        $this->replaceJitaMarket();
    }

    public function testThePlannerRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/planetary/planner', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheSeededWaterSchematicComesBackWithItsTypesAndPrices(): void
    {
        $this->client->loginUser($this->pilot, 'api');
        $this->client->request('GET', '/api/planetary/planner', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        foreach (['id', 'recipes', 'types', 'prices'] as $key) {
            self::assertArrayHasKey($key, $body);
        }
        self::assertSame('planner', $body['id']);

        self::assertSame(
            ['outputTypeId' => self::WATER, 'outputQuantity' => 20, 'cycleSeconds' => 1800, 'inputs' => [['typeId' => self::AQUEOUS_LIQUIDS, 'quantity' => 3000]]],
            $this->lineOf($body['recipes'], 'outputTypeId', self::WATER),
        );
        self::assertSame(
            ['typeId' => self::WATER, 'name' => 'Test Water', 'tier' => 'P1', 'volume' => 0.19],
            $this->lineOf($body['types'], 'typeId', self::WATER),
        );
        self::assertSame(
            ['typeId' => self::AQUEOUS_LIQUIDS, 'name' => 'Test Aqueous Liquids', 'tier' => 'P0', 'volume' => 0.01],
            $this->lineOf($body['types'], 'typeId', self::AQUEOUS_LIQUIDS),
        );
        self::assertSame(['typeId' => self::WATER, 'sell' => 12.5, 'buy' => 11.5], $this->lineOf($body['prices'], 'typeId', self::WATER));
        $unpriced = $this->lineOf($body['prices'], 'typeId', self::AQUEOUS_LIQUIDS);
        self::assertArrayHasKey('sell', $unpriced);
        self::assertArrayHasKey('buy', $unpriced);
        self::assertNull($unpriced['sell']);
        self::assertNull($unpriced['buy']);
    }

    private function lineOf(array $lines, string $key, int $typeId): array
    {
        $matching = array_values(array_filter($lines, static fn (array $line): bool => $line[$key] === $typeId));
        self::assertCount(1, $matching);

        return $matching[0];
    }

    private function seedWaterSchematic(): void
    {
        $basicCommodities = $this->em->find(InvMarketGroup::class, self::BASIC_COMMODITIES_MARKET_GROUP_ID);
        if ($basicCommodities === null) {
            $basicCommodities = (new InvMarketGroup())->setMarketGroupId(self::BASIC_COMMODITIES_MARKET_GROUP_ID)->setMarketGroupName('Basic Commodities');
            $this->em->persist($basicCommodities);
        }
        $category = (new InvCategory())->setCategoryId(self::CATEGORY_ID)->setCategoryName('Test Planetary Commodities');
        $group = (new InvGroup())->setGroupId(self::GROUP_ID)->setGroupName('Test Basic Commodities')->setCategory($category);
        $aqueousLiquids = (new InvType())->setTypeId(self::AQUEOUS_LIQUIDS)->setTypeName('Test Aqueous Liquids')->setGroup($group)->setVolume(0.01);
        $water = (new InvType())->setTypeId(self::WATER)->setTypeName('Test Water')->setGroup($group)->setVolume(0.19)->setMarketGroup($basicCommodities);
        $schematic = (new PlanetSchematic())->setSchematicId(self::WATER_SCHEMATIC_ID)->setSchematicName('Test Water')->setCycleTime(1800);
        $input = (new PlanetSchematicType())->setSchematic($schematic)->setTypeId(self::AQUEOUS_LIQUIDS)->setIsInput(true)->setQuantity(3000);
        $output = (new PlanetSchematicType())->setSchematic($schematic)->setTypeId(self::WATER)->setIsInput(false)->setQuantity(20);
        foreach ([$category, $group, $aqueousLiquids, $water, $schematic, $input, $output] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    private function replaceJitaMarket(): void
    {
        $sellPrices = [self::WATER => 12.5];
        $buyPrices = [self::WATER => 11.5];
        $jitaMarket = $this->createStub(JitaMarketService::class);
        $jitaMarket->method('getPrices')->willReturnCallback(
            static fn (array $typeIds): array => array_combine($typeIds, array_map(static fn (int $typeId): ?float => $sellPrices[$typeId] ?? null, $typeIds)),
        );
        $jitaMarket->method('getBuyPrices')->willReturnCallback(
            static fn (array $typeIds): array => array_combine($typeIds, array_map(static fn (int $typeId): ?float => $buyPrices[$typeId] ?? null, $typeIds)),
        );
        self::getContainer()->set(JitaMarketService::class, $jitaMarket);
    }

    private function createPilot(): User
    {
        $character = (new Character())
            ->setEveCharacterId(self::PILOT_EVE_CHARACTER_ID)
            ->setName('Ilyra Planner')
            ->setCorporationId(98_900_002)
            ->setCorporationName('Esk Planetary Haulers');
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }
}
