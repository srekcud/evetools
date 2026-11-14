<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Assets;

use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Entity\CorpAssetVisibility;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-878')]
final class AssetBrowseApiTest extends WebTestCase
{
    private const int CORPORATION_ID = 98_878_501;
    private const int JITA_ID = 60_003_760;
    private const int AMARR_ID = 60_008_494;
    private const int VISIBLE_DIVISION = 2;
    private const int BOX = 1_878_500_001;
    private const int TRITANIUM_IN_BOX = 1_878_500_002;
    private const int BLUEPRINT = 1_878_500_003;
    private const int HIDDEN_BOX = 1_878_500_004;
    private const int HIDDEN_VELDSPAR = 1_878_500_005;
    private const int BOX_TYPE = 17_366;
    private const int TRITANIUM_TYPE = 34;
    private const int BLUEPRINT_TYPE = 11_184;
    private const int VELDSPAR_TYPE = 1_230;

    private static int $nextEveCharacterId = 2_113_878_501;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function inventoryFormats(): iterable
    {
        yield 'json' => ['application/json'];
        yield 'json-ld' => ['application/ld+json'];
    }

    #[DataProvider('inventoryFormats')]
    public function testCharacterSummaryListsLocationsWithoutLinkedDataKeysWhateverTheFormat(string $accept): void
    {
        $user = $this->persistPilotWithPersonalInventory();

        $body = $this->get($user, $this->characterPath($user) . '/locations', $accept);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['characterId', 'itemCount', 'typeCount', 'systemCount', 'locationCount', 'lastSyncedAt', 'locations'],
            array_keys($body),
        );
        self::assertSame(1 + 700 + 1 + 5, $body['itemCount']);
        self::assertSame(2, $body['locationCount']);
        self::assertSame(
            ['locationId', 'locationType', 'locationName', 'solarSystemName', 'itemCount', 'shipCount', 'divisions', 'cachedAt'],
            array_keys($body['locations'][0]),
        );
        self::assertSame(self::JITA_ID, $body['locations'][0]['locationId']);
        self::assertSame(702, $body['locations'][0]['itemCount']);
    }

    public function testCharacterLocationAndContainerContentsAreAnnotatedLikeTheFullList(): void
    {
        $user = $this->persistPilotWithPersonalInventory();

        $location = $this->get($user, $this->characterPath($user) . '/locations/' . self::JITA_ID);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::JITA_ID, $location['locationId']);
        self::assertSame([self::BOX, self::BLUEPRINT], array_column($location['items'], 'itemId'));
        [$box, $blueprint] = $location['items'];
        self::assertSame(9, $blueprint['categoryId']);
        self::assertSame('not_synced', $blueprint['blueprint']['status']);
        self::assertSame(1, $box['childCount']);
        self::assertTrue($box['canResolveItemName']);
        self::assertSame(
            ['id', 'itemId', 'typeId', 'typeName', 'categoryId', 'quantity', 'locationId', 'locationName', 'locationType', 'locationFlag', 'solarSystemId', 'solarSystemName', 'itemName', 'divisionName', 'isSingleton', 'canResolveItemName', 'blueprint', 'cachedAt', 'childCount'],
            array_keys($box),
        );

        $contents = $this->get($user, $this->characterPath($user) . '/containers/' . self::BOX);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::BOX, $contents['itemId']);
        self::assertSame([self::TRITANIUM_IN_BOX], array_column($contents['items'], 'itemId'));
        self::assertSame(0, $contents['items'][0]['childCount']);
    }

    public function testAnotherUsersCharacterAndUnknownLocationsOrContainersAreNotFound(): void
    {
        $owner = $this->persistPilotWithPersonalInventory();
        $intruder = $this->persistPilot();
        $this->get($owner, $this->characterPath($owner) . '/locations');
        self::assertResponseStatusCodeSame(200);

        foreach (['/locations', '/locations/' . self::JITA_ID, '/containers/' . self::BOX, '/search?q=trit'] as $suffix) {
            $this->get($intruder, $this->characterPath($owner) . $suffix);
            self::assertResponseStatusCodeSame(404, $suffix);
        }

        $this->get($owner, $this->characterPath($owner) . '/locations/12345');
        self::assertResponseStatusCodeSame(404);
        $this->get($owner, $this->characterPath($owner) . '/containers/12345');
        self::assertResponseStatusCodeSame(404);
    }

    public function testCorporationRoutesNeverExposeAHiddenDivisionAndServeTheVisibleOne(): void
    {
        $user = $this->persistPilotWithCorporationBoxes();

        $summary = $this->get($user, '/api/me/corporation/assets/locations');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['id', 'itemCount', 'typeCount', 'systemCount', 'locationCount', 'lastSyncedAt', 'nextRefreshAt', 'locations'], array_keys($summary));
        self::assertSame('corporation', $summary['id']);
        self::assertSame([self::JITA_ID], array_column($summary['locations'], 'locationId'));
        self::assertSame([['name' => 'Reactions', 'count' => 41]], $summary['locations'][0]['divisions']);

        $contents = $this->get($user, '/api/me/corporation/assets/containers/' . self::BOX);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([self::TRITANIUM_IN_BOX], array_column($contents['items'], 'itemId'));

        $this->get($user, '/api/me/corporation/assets/containers/' . self::HIDDEN_BOX);
        self::assertResponseStatusCodeSame(404);
        $this->get($user, '/api/me/corporation/assets/locations/' . self::AMARR_ID);
        self::assertResponseStatusCodeSame(404);
        $this->get($user, '/api/me/corporation/assets/locations/' . self::JITA_ID);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([self::BOX], array_column($this->responseJson()['items'], 'itemId'));
        self::assertSame('Reactions', $this->responseJson()['items'][0]['divisionName']);
    }

    public function testSearchNeedsTwoCharactersAndReturnsHitsWithTheirContainerPath(): void
    {
        $user = $this->persistPilotWithCorporationBoxes();

        $this->get($user, '/api/me/corporation/assets/search?q=t');
        self::assertResponseStatusCodeSame(400);

        $result = $this->get($user, '/api/me/corporation/assets/search?q=trit');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['query', 'truncated', 'locationIds', 'hits'], array_keys($result));
        self::assertFalse($result['truncated']);
        self::assertSame([self::TRITANIUM_IN_BOX], array_column($result['hits'], 'itemId'));
        self::assertSame([self::BOX], $result['hits'][0]['containerPath']);
        self::assertSame(self::JITA_ID, $result['hits'][0]['rootLocationId']);
        self::assertSame([], $this->get($user, '/api/me/corporation/assets/search?q=veldspar')['hits']);
    }

    private function characterPath(User $user): string
    {
        return '/api/me/characters/' . $user->getMainCharacter()->getId()->toRfc4122() . '/assets';
    }

    private function persistPilot(): User
    {
        $character = (new Character())
            ->setEveCharacterId(self::$nextEveCharacterId++)
            ->setName('Pilot ' . self::$nextEveCharacterId)
            ->setCorporationId(self::CORPORATION_ID)
            ->setCorporationName('Solberg Industries');
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }

    private function persistPilotWithPersonalInventory(): User
    {
        $user = $this->persistPilot();
        $character = $user->getMainCharacter();
        $this->persistSde();
        $this->em->persist($this->asset(self::BOX, self::BOX_TYPE, 'Giant Secure Container', 1, self::JITA_ID, 'station', 'Hangar')->setCharacter($character)->setIsSingleton(true));
        $this->em->persist($this->asset(self::TRITANIUM_IN_BOX, self::TRITANIUM_TYPE, 'Tritanium', 700, self::BOX, 'item', 'Unlocked')->setCharacter($character));
        $this->em->persist($this->asset(self::BLUEPRINT, self::BLUEPRINT_TYPE, 'Merlin Blueprint', 1, self::JITA_ID, 'station', 'Hangar')->setCharacter($character));
        $this->em->persist($this->asset(1_878_500_090, self::TRITANIUM_TYPE, 'Tritanium', 5, self::AMARR_ID, 'station', 'Hangar', 'Amarr VIII')->setCharacter($character));
        $this->em->flush();

        return $user;
    }

    private function persistPilotWithCorporationBoxes(): User
    {
        $user = $this->persistPilot();
        $this->persistSde();
        $this->em->persist((new CorpAssetVisibility())
            ->setCorporationId(self::CORPORATION_ID)
            ->setVisibleDivisions([self::VISIBLE_DIVISION])
            ->setConfiguredBy($user));
        $this->em->persist($this->corporationAsset(self::BOX, self::BOX_TYPE, 'Giant Secure Container', 1, self::JITA_ID, 'station', 'CorpSAG2', 'Reactions'));
        $this->em->persist($this->corporationAsset(self::TRITANIUM_IN_BOX, self::TRITANIUM_TYPE, 'Tritanium', 40, self::BOX, 'item', 'Unlocked', 'Reactions'));
        $this->em->persist($this->corporationAsset(self::HIDDEN_BOX, self::BOX_TYPE, 'Giant Secure Container', 1, self::AMARR_ID, 'station', 'CorpSAG5', 'Vault', 'Amarr VIII'));
        $this->em->persist($this->corporationAsset(self::HIDDEN_VELDSPAR, self::VELDSPAR_TYPE, 'Veldspar', 900, self::HIDDEN_BOX, 'item', 'Unlocked', 'Vault', 'Amarr VIII'));
        $this->em->flush();

        return $user;
    }

    private function asset(int $itemId, int $typeId, string $typeName, int $quantity, int $locationId, string $locationType, string $flag, string $locationName = 'Jita IV'): CachedAsset
    {
        return (new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setQuantity($quantity)
            ->setLocationId($locationId)
            ->setLocationName($locationName)
            ->setLocationType($locationType)
            ->setLocationFlag($flag)
            ->setSolarSystemName($locationName === 'Jita IV' ? 'Jita' : 'Amarr')
            ->setIsCorporationAsset(false);
    }

    private function corporationAsset(int $itemId, int $typeId, string $typeName, int $quantity, int $locationId, string $locationType, string $flag, string $division, string $locationName = 'Jita IV'): CachedAsset
    {
        return $this->asset($itemId, $typeId, $typeName, $quantity, $locationId, $locationType, $flag, $locationName)
            ->setDivisionName($division)
            ->setCorporationId(self::CORPORATION_ID)
            ->setIsCorporationAsset(true);
    }

    private function persistSde(): void
    {
        $categories = [];
        foreach ([2 => 'Celestial', 4 => 'Material', 9 => 'Blueprint'] as $categoryId => $name) {
            $categories[$categoryId] = (new InvCategory())->setCategoryId($categoryId)->setCategoryName($name)->setPublished(true);
            $this->em->persist($categories[$categoryId]);
        }
        $groups = [];
        foreach ([[12, 'Cargo Container', 2], [18, 'Mineral', 4], [105, 'Frigate Blueprint', 9]] as [$groupId, $name, $categoryId]) {
            $groups[$groupId] = (new InvGroup())->setGroupId($groupId)->setGroupName($name)->setCategory($categories[$categoryId])->setPublished(true);
            $this->em->persist($groups[$groupId]);
        }
        foreach ([
            [self::BOX_TYPE, 'Giant Secure Container', 12],
            [self::TRITANIUM_TYPE, 'Tritanium', 18],
            [self::VELDSPAR_TYPE, 'Veldspar', 18],
            [self::BLUEPRINT_TYPE, 'Merlin Blueprint', 105],
        ] as [$typeId, $name, $groupId]) {
            $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($name)->setGroup($groups[$groupId])->setPublished(true));
        }
        $this->em->flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function get(User $user, string $path, string $accept = 'application/json'): array
    {
        $userId = $user->getId();
        $this->em->clear();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($this->em->find(User::class, $userId), 'api');
        $this->client->request('GET', $path, server: ['HTTP_ACCEPT' => $accept]);

        $content = (string) $this->client->getResponse()->getContent();

        return $content === '' ? [] : (json_decode($content, true, flags: \JSON_THROW_ON_ERROR) ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    private function responseJson(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
