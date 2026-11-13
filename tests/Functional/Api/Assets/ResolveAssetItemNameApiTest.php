<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Assets;

use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Service\ESI\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-784')]
final class ResolveAssetItemNameApiTest extends WebTestCase
{
    private const int JITA_STATION_ID = 60_003_760;
    private const int RIFTER_TYPE_ID = 587;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int STATION_CONTAINER_TYPE_ID = 17_366;
    private const int RIFTER_BLUEPRINT_TYPE_ID = 691;
    private const int SHIP_CATEGORY_ID = 6;
    private const int BLUEPRINT_CATEGORY_ID = 9;
    private const int CARGO_CONTAINER_GROUP_ID = 12;
    private const int CELESTIAL_CATEGORY_ID = 2;
    private const int MATERIAL_CATEGORY_ID = 4;
    private const int RIFTER_ITEM_ID = 1_050_000_784_301;
    private const int TRITANIUM_ITEM_ID = 1_050_000_784_302;
    private const int STATION_CONTAINER_ITEM_ID = 1_050_000_784_303;
    private const int LEGACY_PYERITE_ITEM_ID = 1_050_000_784_304;
    private const int RIFTER_BLUEPRINT_ITEM_ID = 1_050_000_863_301;

    private static int $nextEveCharacterId = 2_113_784_301;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private EsiClient&Stub $esiClient;

    /** @var list<array{endpoint: string, body: array<int|string, mixed>}> */
    private array $namesRequests = [];

    /** @var list<mixed>|\Throwable */
    private array|\Throwable $namesAnswer = [];

    protected function setUp(): void
    {
        $this->namesRequests = [];
        $this->namesAnswer = [];
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->esiClient = $this->createStub(EsiClient::class);
        $this->esiClient->method('post')->willReturnCallback(
            function (string $endpoint, array $body): array {
                if ($endpoint === '/universe/names/') {
                    return [];
                }

                $this->namesRequests[] = ['endpoint' => $endpoint, 'body' => $body];

                if ($this->namesAnswer instanceof \Throwable) {
                    throw $this->namesAnswer;
                }

                return $this->namesAnswer;
            },
        );
        self::getContainer()->set(EsiClient::class, $this->esiClient);
    }

    public function testResolvingTheNameOfAnUnnamedShipStoresItAndAnswersNamed(): void
    {
        $this->seedCategorizedTypes();
        $pilot = $this->persistPilot();
        $ship = $this->persistAsset($pilot, self::RIFTER_ITEM_ID, self::RIFTER_TYPE_ID, 'Rifter', 1, true);
        $this->em->flush();
        $shipId = $ship->getId()->toRfc4122();
        $this->namesAnswer = [['item_id' => self::RIFTER_ITEM_ID, 'name' => 'Hauler One']];

        $this->resolveItemName($pilot, $shipId);

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        self::assertSame($shipId, $body['id']);
        self::assertSame('named', $body['status']);
        self::assertSame('Hauler One', $body['itemName']);
        self::assertSame(
            [['endpoint' => '/characters/' . $pilot->getMainCharacter()->getEveCharacterId() . '/assets/names/', 'body' => [self::RIFTER_ITEM_ID]]],
            $this->namesRequests,
        );
        $this->em->clear();
        self::assertSame('Hauler One', $this->em->find(CachedAsset::class, $shipId)->getItemName());
    }

    #[Group('issue-861')]
    public function testResolvingWithoutBodyAndWithJsonContentTypeAnswers200(): void
    {
        $this->seedCategorizedTypes();
        $pilot = $this->persistPilot();
        $ship = $this->persistAsset($pilot, self::RIFTER_ITEM_ID, self::RIFTER_TYPE_ID, 'Rifter', 1, true);
        $this->em->flush();
        $shipId = $ship->getId()->toRfc4122();
        $this->namesAnswer = [['item_id' => self::RIFTER_ITEM_ID, 'name' => 'Hauler One']];

        $this->em->clear();
        $this->client->loginUser($this->em->find(User::class, $pilot->getId()), 'api');
        $this->client->request(
            'POST',
            '/api/me/assets/' . $shipId . '/item-name',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        self::assertSame('named', $body['status']);
        self::assertSame('Hauler One', $body['itemName']);
    }

    public function testAContainerWithoutCustomNameInGameAnswersUnnamedWithANullNameAndStoresNothing(): void
    {
        $this->seedCategorizedTypes();
        $pilot = $this->persistPilot();
        $container = $this->persistAsset($pilot, self::STATION_CONTAINER_ITEM_ID, self::STATION_CONTAINER_TYPE_ID, 'Station Container', 1, true);
        $this->em->flush();
        $containerId = $container->getId()->toRfc4122();
        $this->namesAnswer = [['item_id' => self::STATION_CONTAINER_ITEM_ID, 'name' => 'None']];

        $this->resolveItemName($pilot, $containerId);

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        self::assertSame('unnamed', $body['status']);
        self::assertArrayHasKey('itemName', $body);
        self::assertNull($body['itemName']);
        $this->em->clear();
        self::assertNull($this->em->find(CachedAsset::class, $containerId)->getItemName());
    }

    public function testAnEsiFailureAnswers502AndStoresNothing(): void
    {
        $this->seedCategorizedTypes();
        $pilot = $this->persistPilot();
        $container = $this->persistAsset($pilot, self::STATION_CONTAINER_ITEM_ID, self::STATION_CONTAINER_TYPE_ID, 'Station Container', 1, true);
        $this->em->flush();
        $containerId = $container->getId()->toRfc4122();
        $this->namesAnswer = new EsiApiException('Requested item is not a nameable asset', 404);

        $this->resolveItemName($pilot, $containerId);

        self::assertResponseStatusCodeSame(502);
        self::assertStringContainsString('Requested item is not a nameable asset', (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        self::assertNull($this->em->find(CachedAsset::class, $containerId)->getItemName());
    }

    public function testTheAssetOfAnotherUsersCharacterAnswers404AndCallsNoEsi(): void
    {
        $owner = $this->persistPilot();
        $ship = $this->persistAsset($owner, self::RIFTER_ITEM_ID, self::RIFTER_TYPE_ID, 'Rifter', 1, true);
        $intruder = $this->persistPilot();
        $this->em->flush();
        $shipId = $ship->getId()->toRfc4122();
        $this->namesAnswer = [['item_id' => self::RIFTER_ITEM_ID, 'name' => 'Stolen Name']];

        $this->resolveItemName($intruder, $shipId);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->namesRequests);
        $this->em->clear();
        self::assertNull($this->em->find(CachedAsset::class, $shipId)->getItemName());
    }

    public function testAStackOfTritaniumAnswers422AndCallsNoEsi(): void
    {
        $pilot = $this->persistPilot();
        $stack = $this->persistAsset($pilot, self::TRITANIUM_ITEM_ID, self::TRITANIUM_TYPE_ID, 'Tritanium', 500, false);
        $this->em->flush();

        $this->resolveItemName($pilot, $stack->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->namesRequests);
    }

    public function testCharacterAssetsExposeTheSingletonFlagAndWhetherTheirItemNameCanBeResolved(): void
    {
        $this->seedCategorizedTypes();
        $pilot = $this->persistPilot();
        $this->persistAsset($pilot, self::RIFTER_ITEM_ID, self::RIFTER_TYPE_ID, 'Rifter', 1, true);
        $this->persistAsset($pilot, self::TRITANIUM_ITEM_ID, self::TRITANIUM_TYPE_ID, 'Tritanium', 500, false);
        $this->persistAsset($pilot, self::LEGACY_PYERITE_ITEM_ID, 35, 'Pyerite', 750, null);
        $this->persistAsset($pilot, self::STATION_CONTAINER_ITEM_ID, self::STATION_CONTAINER_TYPE_ID, 'Station Container', 1, true);
        $this->persistAsset($pilot, self::RIFTER_BLUEPRINT_ITEM_ID, self::RIFTER_BLUEPRINT_TYPE_ID, 'Rifter Blueprint', 1, true);
        $this->em->flush();
        $this->em->clear();

        $this->client->loginUser($this->em->find(User::class, $pilot->getId()), 'api');
        $this->client->request(
            'GET',
            '/api/me/characters/' . $pilot->getMainCharacter()->getId()->toRfc4122() . '/assets',
            server: ['HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseStatusCodeSame(200);
        $itemsByItemId = [];
        foreach ($this->responseJson()['items'] as $item) {
            self::assertArrayHasKey('isSingleton', $item);
            self::assertArrayHasKey('canResolveItemName', $item);
            $itemsByItemId[$item['itemId']] = $item;
        }
        self::assertTrue($itemsByItemId[self::RIFTER_ITEM_ID]['isSingleton']);
        self::assertTrue($itemsByItemId[self::RIFTER_ITEM_ID]['canResolveItemName']);
        self::assertFalse($itemsByItemId[self::TRITANIUM_ITEM_ID]['isSingleton']);
        self::assertFalse($itemsByItemId[self::TRITANIUM_ITEM_ID]['canResolveItemName']);
        self::assertNull($itemsByItemId[self::LEGACY_PYERITE_ITEM_ID]['isSingleton']);
        self::assertFalse($itemsByItemId[self::LEGACY_PYERITE_ITEM_ID]['canResolveItemName']);
        self::assertTrue($itemsByItemId[self::STATION_CONTAINER_ITEM_ID]['canResolveItemName']);
        self::assertTrue($itemsByItemId[self::RIFTER_BLUEPRINT_ITEM_ID]['isSingleton']);
        self::assertFalse($itemsByItemId[self::RIFTER_BLUEPRINT_ITEM_ID]['canResolveItemName']);
    }

    #[Group('issue-863')]
    public function testAFlaggedSingletonBlueprintAnswers422WithTheTypeReasonAndCallsNoEsi(): void
    {
        $this->seedCategorizedTypes();
        $pilot = $this->persistPilot();
        $blueprint = $this->persistAsset($pilot, self::RIFTER_BLUEPRINT_ITEM_ID, self::RIFTER_BLUEPRINT_TYPE_ID, 'Rifter Blueprint', 1, true);
        $this->em->flush();
        $this->namesAnswer = [['item_id' => self::RIFTER_BLUEPRINT_ITEM_ID, 'name' => 'Should not be asked']];

        $this->resolveItemName($pilot, $blueprint->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('This type cannot have a custom name', (string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->namesRequests);
    }

    #[Group('issue-797')]
    public function testRowsSyncedBeforeTheSingletonFlagExistedOfferTheResolutionForShipsAndContainersOnly(): void
    {
        $this->seedCategorizedTypes();
        $pilot = $this->persistPilot();
        $this->persistAsset($pilot, self::RIFTER_ITEM_ID, self::RIFTER_TYPE_ID, 'Rifter', 1, null);
        $this->persistAsset($pilot, self::STATION_CONTAINER_ITEM_ID, self::STATION_CONTAINER_TYPE_ID, 'Station Container', 1, null);
        $this->persistAsset($pilot, self::LEGACY_PYERITE_ITEM_ID, 35, 'Pyerite', 1, null);
        $this->persistAsset($pilot, self::TRITANIUM_ITEM_ID, self::TRITANIUM_TYPE_ID, 'Tritanium', 500, null);
        $this->em->flush();
        $this->em->clear();

        $this->client->loginUser($this->em->find(User::class, $pilot->getId()), 'api');
        $this->client->request(
            'GET',
            '/api/me/characters/' . $pilot->getMainCharacter()->getId()->toRfc4122() . '/assets',
            server: ['HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseStatusCodeSame(200);
        $canResolveByItemId = [];
        foreach ($this->responseJson()['items'] as $item) {
            $canResolveByItemId[$item['itemId']] = [$item['isSingleton'], $item['canResolveItemName']];
        }
        ksort($canResolveByItemId);
        self::assertSame(
            [
                self::RIFTER_ITEM_ID => [null, true],
                self::TRITANIUM_ITEM_ID => [null, false],
                self::STATION_CONTAINER_ITEM_ID => [null, true],
                self::LEGACY_PYERITE_ITEM_ID => [null, false],
            ],
            $canResolveByItemId,
        );
    }

    #[Group('issue-797')]
    public function testResolvingTheNameOfAnUnflaggedShipStoresItAndAnswersNamed(): void
    {
        $this->seedCategorizedTypes();
        $pilot = $this->persistPilot();
        $ship = $this->persistAsset($pilot, self::RIFTER_ITEM_ID, self::RIFTER_TYPE_ID, 'Rifter', 1, null);
        $this->em->flush();
        $shipId = $ship->getId()->toRfc4122();
        $this->namesAnswer = [['item_id' => self::RIFTER_ITEM_ID, 'name' => 'Hauler One']];

        $this->resolveItemName($pilot, $shipId);

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        self::assertSame('named', $body['status']);
        self::assertSame('Hauler One', $body['itemName']);
        $this->em->clear();
        self::assertSame('Hauler One', $this->em->find(CachedAsset::class, $shipId)->getItemName());
    }

    #[Group('issue-797')]
    public function testResolvingAnUnflaggedMaterialAnswers422AndCallsNoEsi(): void
    {
        $this->seedCategorizedTypes();
        $pilot = $this->persistPilot();
        $material = $this->persistAsset($pilot, self::LEGACY_PYERITE_ITEM_ID, 35, 'Pyerite', 1, null);
        $this->em->flush();

        $this->resolveItemName($pilot, $material->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('refresh the assets', (string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->namesRequests);
    }

    private function resolveItemName(User $user, string $assetId): void
    {
        $this->em->clear();
        $this->client->loginUser($this->em->find(User::class, $user->getId()), 'api');
        $this->client->request(
            'POST',
            '/api/me/assets/' . $assetId . '/item-name',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: '{}',
        );
    }

    private function seedCategorizedTypes(): void
    {
        $this->seedType(self::RIFTER_TYPE_ID, 'Rifter', 25, 'Frigate', self::SHIP_CATEGORY_ID, 'Ship');
        $this->seedType(self::STATION_CONTAINER_TYPE_ID, 'Station Container', self::CARGO_CONTAINER_GROUP_ID, 'Cargo Container', self::CELESTIAL_CATEGORY_ID, 'Celestial');
        $this->seedType(self::RIFTER_BLUEPRINT_TYPE_ID, 'Rifter Blueprint', 105, 'Frigate Blueprint', self::BLUEPRINT_CATEGORY_ID, 'Blueprint');
        $this->seedType(self::TRITANIUM_TYPE_ID, 'Tritanium', 18, 'Mineral', self::MATERIAL_CATEGORY_ID, 'Material');
        $this->seedType(35, 'Pyerite', 18, 'Mineral', self::MATERIAL_CATEGORY_ID, 'Material');
        $this->em->flush();
    }

    private function seedType(int $typeId, string $typeName, int $groupId, string $groupName, int $categoryId, string $categoryName): void
    {
        $category = $this->em->find(InvCategory::class, $categoryId);
        if ($category === null) {
            $category = (new InvCategory())->setCategoryId($categoryId)->setCategoryName($categoryName);
            $this->em->persist($category);
        }
        $group = $this->em->find(InvGroup::class, $groupId);
        if ($group === null) {
            $group = (new InvGroup())->setGroupId($groupId)->setGroupName($groupName)->setCategory($category);
            $this->em->persist($group);
        }
        if ($this->em->find(InvType::class, $typeId) === null) {
            $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($typeName)->setGroup($group)->setVolume(1.0));
        }
    }

    private function persistPilot(): User
    {
        $eveCharacterId = self::$nextEveCharacterId++;
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName('Pilot ' . $eveCharacterId)
            ->setCorporationId(98_784_301)
            ->setCorporationName('Varrow Logistics');
        $token = (new EveToken())
            ->setAccessToken('access-' . $eveCharacterId)
            ->setRefreshTokenEncrypted('refresh-' . $eveCharacterId)
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+20 minutes'))
            ->setScopes(['esi-assets.read_assets.v1']);
        $character->setEveToken($token);
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);
        $this->em->persist($token);
        $this->em->flush();

        return $user;
    }

    private function persistAsset(User $user, int $itemId, int $typeId, string $typeName, int $quantity, ?bool $isSingleton): CachedAsset
    {
        $asset = (new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setQuantity($quantity)
            ->setLocationId(self::JITA_STATION_ID)
            ->setLocationName('Jita IV - Moon 4 - Caldari Navy Assembly Plant')
            ->setLocationType('station')
            ->setLocationFlag('Hangar')
            ->setIsSingleton($isSingleton)
            ->setCharacter($user->getMainCharacter())
            ->setIsCorporationAsset(false);
        $this->em->persist($asset);

        return $asset;
    }

    /**
     * @return array<string, mixed>
     */
    private function responseJson(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
