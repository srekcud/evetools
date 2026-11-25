<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Planetary;

use App\Entity\Character;
use App\Entity\Sde\DgmTypeAttribute;
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
    private const int EXTRACTORS_GROUP_ID = 1026;
    private const int COMMAND_CENTERS_GROUP_ID = 1027;
    private const int PROCESSORS_GROUP_ID = 1028;
    private const int SPACEPORTS_GROUP_ID = 1030;
    private const int ZETATEST_LAUNCHPAD = 990_201;
    private const int ZETATEST_ELITE_COMMAND_CENTER = 990_202;
    private const int ZETATEST_BASIC_FACILITY = 990_203;
    private const int ZETATEST_ADVANCED_FACILITY = 990_204;
    private const int ZETATEST_AQUEOUS_EXTRACTOR = 990_205;
    private const int ZETATEST_LEVEL_ONE_COMMAND_CENTER = 990_206;
    private const int CPU_OUTPUT = 48;
    private const int POWER_OUTPUT = 11;
    private const int CPU_LOAD = 49;
    private const int POWER_LOAD = 15;
    private const int HARVESTER_TYPE = 709;

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
        $this->seedZetatestPlanet();
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
        foreach (['id', 'recipes', 'types', 'prices', 'planetTypes', 'extractionPlanetTypes'] as $key) {
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

    public function testTheSeededPlanetComesBackWithItsCommandCenterLaunchpadAndFacilities(): void
    {
        $this->client->loginUser($this->pilot, 'api');
        $this->client->request('GET', '/api/planetary/planner', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $planets = array_values(array_filter($body['planetTypes'], static fn (array $planet): bool => $planet['name'] === 'Zetatest'));
        self::assertCount(1, $planets);
        $planet = $planets[0];

        self::assertSame(['cpu' => 21_000, 'pg' => 16_000], $planet['commandCenter']);
        self::assertSame(3_300, $planet['launchpad']['cpu']);
        self::assertSame(640, $planet['launchpad']['pg']);
        self::assertEqualsWithDelta(7_500.0, $planet['launchpad']['capacity'], 0.0001);
        self::assertSame(['cpu' => 210, 'pg' => 790], $planet['facilities']['basic']);
        self::assertSame(['cpu' => 510, 'pg' => 690], $planet['facilities']['advanced']);
        self::assertArrayHasKey('highTech', $planet['facilities']);
        self::assertNull($planet['facilities']['highTech']);
    }

    public function testTheSeededExtractorGivesItsRawResourceThePlanetTypeOfItsName(): void
    {
        $this->client->loginUser($this->pilot, 'api');
        $this->client->request('GET', '/api/planetary/planner', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(
            ['typeId' => self::AQUEOUS_LIQUIDS, 'planetTypes' => ['Zetatest']],
            $this->lineOf($body['extractionPlanetTypes'], 'typeId', self::AQUEOUS_LIQUIDS),
        );
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

    private function seedZetatestPlanet(): void
    {
        $category = (new InvCategory())->setCategoryId(self::CATEGORY_ID + 1)->setCategoryName('Test Planetary Installations');
        $this->em->persist($category);
        $groups = [];
        foreach ([
            self::EXTRACTORS_GROUP_ID => 'Extractors',
            self::COMMAND_CENTERS_GROUP_ID => 'Command Centers',
            self::PROCESSORS_GROUP_ID => 'Processors',
            self::SPACEPORTS_GROUP_ID => 'Spaceports',
        ] as $groupId => $groupName) {
            $group = $this->em->find(InvGroup::class, $groupId);
            if ($group === null) {
                $group = (new InvGroup())->setGroupId($groupId)->setGroupName($groupName)->setCategory($category);
                $this->em->persist($group);
            }
            $groups[$groupId] = $group;
        }

        $installations = [
            [self::ZETATEST_LAUNCHPAD, 'Zetatest Launchpad', self::SPACEPORTS_GROUP_ID, 7_500.0, [self::CPU_LOAD => 3_300, self::POWER_LOAD => 640]],
            [self::ZETATEST_ELITE_COMMAND_CENTER, 'Elite Zetatest Command Center', self::COMMAND_CENTERS_GROUP_ID, null, [self::CPU_OUTPUT => 21_000, self::POWER_OUTPUT => 16_000]],
            [self::ZETATEST_LEVEL_ONE_COMMAND_CENTER, 'Zetatest Command Center', self::COMMAND_CENTERS_GROUP_ID, null, [self::CPU_OUTPUT => 1_500, self::POWER_OUTPUT => 5_000]],
            [self::ZETATEST_BASIC_FACILITY, 'Zetatest Basic Industry Facility', self::PROCESSORS_GROUP_ID, null, [self::CPU_LOAD => 210, self::POWER_LOAD => 790]],
            [self::ZETATEST_ADVANCED_FACILITY, 'Zetatest Advanced Industry Facility', self::PROCESSORS_GROUP_ID, null, [self::CPU_LOAD => 510, self::POWER_LOAD => 690]],
            [self::ZETATEST_AQUEOUS_EXTRACTOR, 'Zetatest Aqueous Liquids Extractor', self::EXTRACTORS_GROUP_ID, null, [self::HARVESTER_TYPE => self::AQUEOUS_LIQUIDS]],
        ];
        foreach ($installations as [$typeId, $name, $groupId, $capacity, $attributes]) {
            $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($name)->setGroup($groups[$groupId])->setCapacity($capacity));
            foreach ($attributes as $attributeId => $value) {
                $this->em->persist((new DgmTypeAttribute())->setTypeId($typeId)->setAttributeId($attributeId)->setValueInt($value));
            }
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
