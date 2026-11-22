<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Constant\EveConstants;
use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Entity\CorpAssetVisibility;
use App\Entity\EveToken;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Service\ESI\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1324')]
final class PersonalStockContainersApiTest extends WebTestCase
{
    private const int CORPORATION_ID = 98_132_401;
    private const int STATION_ID = 60_003_760;
    private const int OFFICE_ITEM_ID = 1_132_401_000_001;
    private const int PERSO_A_ITEM_ID = 1_132_401_000_002;
    private const int PERSO_B_ITEM_ID = 1_132_401_000_003;
    private const int HIDDEN_BOX_ITEM_ID = 1_132_401_000_004;
    private const int TRITANIUM_STACK_ITEM_ID = 1_132_401_000_005;
    private const int ISHTAR_BOX_ITEM_ID = 1_132_401_000_006;
    private const int UNKNOWN_ITEM_ID = 1_132_401_999_999;
    private const int BIGGEST_INT64 = 9_223_372_036_854_775_807;
    private const int CONTAINER_TYPE_ID = 17_366;
    private const int OFFICE_TYPE_ID = 27;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int PYERITE_TYPE_ID = 35;
    private const string PATH = '/api/industry/settings/personal-stock-containers';
    private const string UNKNOWN_DECLARATION_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e52';

    private static int $nextEveCharacterId = 2_132_401_001;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $pilot;
    private User $otherPilot;
    private ?User $actingUser = null;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->pilot = $this->persistPilot('Aria Solberg');
        $this->otherPilot = $this->persistPilot('Brann Holt');
        $this->connectDirector($this->pilot);
        $this->em->persist((new CorpAssetVisibility())->setCorporationId(self::CORPORATION_ID)->setVisibleDivisions([2])->setConfiguredBy($this->pilot));
        $this->persistAsset(self::OFFICE_ITEM_ID, self::OFFICE_TYPE_ID, 1, self::STATION_ID, 'station', EveConstants::OFFICE_LOCATION_FLAG, null, null, null);
        $this->persistAsset(self::PERSO_A_ITEM_ID, self::CONTAINER_TYPE_ID, 1, self::OFFICE_ITEM_ID, 'item', 'CorpSAG2', 'Perso A', 'Giant Secure Container', true);
        $this->persistAsset(self::PERSO_B_ITEM_ID, self::CONTAINER_TYPE_ID, 1, self::OFFICE_ITEM_ID, 'item', 'CorpSAG2', 'Perso B', 'Giant Secure Container', true);
        $this->persistAsset(self::HIDDEN_BOX_ITEM_ID, self::CONTAINER_TYPE_ID, 1, self::OFFICE_ITEM_ID, 'item', 'CorpSAG5', 'Secret box', 'Giant Secure Container', true);
        $this->persistAsset(self::TRITANIUM_STACK_ITEM_ID, self::TRITANIUM_TYPE_ID, 40, self::OFFICE_ITEM_ID, 'item', 'CorpSAG2', null, 'Tritanium', false);
        $this->persistAsset(self::ISHTAR_BOX_ITEM_ID, self::CONTAINER_TYPE_ID, 1, self::OFFICE_ITEM_ID, 'item', 'CorpSAG2', 'Ishtar A', 'Giant Secure Container', true);
        $this->persistAsset(1_132_401_000_011, self::TRITANIUM_TYPE_ID, 5, self::PERSO_A_ITEM_ID, 'item', 'Unlocked', null, 'Tritanium', false);
        $this->persistAsset(1_132_401_000_012, self::PYERITE_TYPE_ID, 2, self::PERSO_A_ITEM_ID, 'item', 'Unlocked', null, 'Pyerite', false);
        $this->em->flush();
    }

    public function testDeclaringAVisibleSingletonContainerAnswers201WithTheResource(): void
    {
        $this->actAs($this->pilot);
        $this->request('POST', self::PATH, ['itemId' => self::PERSO_A_ITEM_ID]);

        self::assertResponseStatusCodeSame(201);
        $resource = $this->responseJson();
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $resource['id']);
        self::assertSame(
            [self::PERSO_A_ITEM_ID, 'Perso A', self::STATION_ID, 'known', 2],
            [$resource['itemId'], $resource['name'], $resource['stationId'], $resource['status'], $resource['itemCount']],
        );
        self::assertArrayHasKey('unknownReason', $resource);
        self::assertNull($resource['unknownReason']);
        self::assertSame(1, $this->declarationCountOf(self::PERSO_A_ITEM_ID));
    }

    public function testDeclaringTheSameContainerTwiceAnswers201WithTheSameIdAndKeepsOneRow(): void
    {
        $this->actAs($this->pilot);
        $this->request('POST', self::PATH, ['itemId' => self::PERSO_A_ITEM_ID]);
        self::assertResponseStatusCodeSame(201);
        $firstId = $this->responseJson()['id'];

        $this->request('POST', self::PATH, ['itemId' => self::PERSO_A_ITEM_ID]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($firstId, $this->responseJson()['id']);
        self::assertSame(1, $this->declarationCountOf(self::PERSO_A_ITEM_ID));
    }

    public function testContainerOutsideEveryVisibleDivisionIsNotFound(): void
    {
        $this->declareAndExpectCreated($this->pilot, self::PERSO_A_ITEM_ID);

        $this->request('POST', self::PATH, ['itemId' => self::HIDDEN_BOX_ITEM_ID]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->declarationCountOf(self::HIDDEN_BOX_ITEM_ID));
        self::assertSame([self::PERSO_A_ITEM_ID], $this->listedItemIds());
    }

    public function testItemUnknownToTheCorporationAssetsIsNotFound(): void
    {
        $this->declareAndExpectCreated($this->pilot, self::PERSO_A_ITEM_ID);

        $this->request('POST', self::PATH, ['itemId' => self::UNKNOWN_ITEM_ID]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([self::PERSO_A_ITEM_ID], $this->listedItemIds());
    }

    public function testBiggestInt64ItemIdIsNotFoundAndNeverAServerError(): void
    {
        $this->declareAndExpectCreated($this->pilot, self::PERSO_A_ITEM_ID);

        $this->request('POST', self::PATH, ['itemId' => self::BIGGEST_INT64]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testItemIdBeyondInt64IsRefusedWithoutAServerError(): void
    {
        $this->declareAndExpectCreated($this->pilot, self::PERSO_A_ITEM_ID);

        $this->send('POST', self::PATH, '{"itemId": 9223372036854775808}');

        self::assertContains($this->client->getResponse()->getStatusCode(), [400, 422]);
        self::assertSame([self::PERSO_A_ITEM_ID], $this->listedItemIds());
    }

    public function testItemIdAsAStringIsRefusedWithoutAServerError(): void
    {
        $this->declareAndExpectCreated($this->pilot, self::PERSO_A_ITEM_ID);

        $this->request('POST', self::PATH, ['itemId' => 'Perso A']);

        self::assertContains($this->client->getResponse()->getStatusCode(), [400, 422]);
        self::assertSame([self::PERSO_A_ITEM_ID], $this->listedItemIds());
    }

    public function testBodyWithoutItemIdAnswers422(): void
    {
        $this->declareAndExpectCreated($this->pilot, self::PERSO_A_ITEM_ID);

        $this->request('POST', self::PATH, []);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([self::PERSO_A_ITEM_ID], $this->listedItemIds());
    }

    public function testNonSingletonAssetAnswers422NotAContainer(): void
    {
        $this->declareAndExpectCreated($this->pilot, self::PERSO_A_ITEM_ID);

        $this->request('POST', self::PATH, ['itemId' => self::TRITANIUM_STACK_ITEM_ID]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('not a container', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->declarationCountOf(self::TRITANIUM_STACK_ITEM_ID));
    }

    public function testContainerDeclaredByAnotherUserAnswers409AndKeepsTheFirstDeclaration(): void
    {
        $this->declareAndExpectCreated($this->pilot, self::PERSO_A_ITEM_ID);

        $this->actAs($this->otherPilot);
        $this->request('POST', self::PATH, ['itemId' => self::PERSO_A_ITEM_ID]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->declarationCountOf(self::PERSO_A_ITEM_ID));
        $this->request('GET', self::PATH);
        self::assertSame([], $this->responseJson());
    }

    public function testContainerNamedLikeAContainerOfAnOpenProjectAnswers409ProjectContainer(): void
    {
        $this->em->persist((new IndustryProject())->setOwner($this->pilot)->setName('Ishtar run')->setStatus(ProjectStatus::InProgress)->setContainerNames(['Ishtar A']));
        $this->em->flush();
        $this->declareAndExpectCreated($this->pilot, self::PERSO_A_ITEM_ID);

        $this->request('POST', self::PATH, ['itemId' => self::ISHTAR_BOX_ITEM_ID]);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('project container', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->declarationCountOf(self::ISHTAR_BOX_ITEM_ID));
    }

    public function testListHoldsOnlyMyDeclarationsWithTheirReadings(): void
    {
        $this->declareAndExpectCreated($this->pilot, self::PERSO_A_ITEM_ID);
        $this->declareAndExpectCreated($this->otherPilot, self::PERSO_B_ITEM_ID);

        $this->actAs($this->pilot);
        $this->request('GET', self::PATH);

        self::assertResponseStatusCodeSame(200);
        $entries = $this->responseJson();
        self::assertCount(1, $entries);
        self::assertSame(
            [self::PERSO_A_ITEM_ID, 'Perso A', self::STATION_ID, 'known', 2],
            [$entries[0]['itemId'], $entries[0]['name'], $entries[0]['stationId'], $entries[0]['status'], $entries[0]['itemCount']],
        );
        self::assertArrayHasKey('unknownReason', $entries[0]);
        self::assertNull($entries[0]['unknownReason']);
    }

    public function testDeclaredContainerRemovedFromTheAssetsIsListedUnknownWithNullItemCount(): void
    {
        $this->declareAndExpectCreated($this->pilot, self::PERSO_A_ITEM_ID);
        $this->em->getConnection()->executeStatement('DELETE FROM cached_assets WHERE item_id = :itemId', ['itemId' => self::PERSO_A_ITEM_ID]);

        $this->request('GET', self::PATH);

        self::assertResponseStatusCodeSame(200);
        $entries = $this->responseJson();
        self::assertCount(1, $entries);
        self::assertSame(['Perso A', 'unknown', 'container_not_found'], [$entries[0]['name'], $entries[0]['status'], $entries[0]['unknownReason']]);
        self::assertArrayHasKey('itemCount', $entries[0]);
        self::assertNull($entries[0]['itemCount']);
    }

    public function testRemovingMyDeclarationAnswers204AndTheContainerLeavesTheList(): void
    {
        $declarationId = $this->declareAndExpectCreated($this->pilot, self::PERSO_A_ITEM_ID);

        $this->request('DELETE', self::PATH.'/'.$declarationId);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->declarationCountOf(self::PERSO_A_ITEM_ID));
        self::assertSame([], $this->listedItemIds());
    }

    public function testRemovingTheDeclarationOfAnotherUserAnswers404AndKeepsIt(): void
    {
        $declarationId = $this->declareAndExpectCreated($this->pilot, self::PERSO_A_ITEM_ID);

        $this->actAs($this->otherPilot);
        $this->request('DELETE', self::PATH.'/'.$declarationId);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, $this->declarationCountOf(self::PERSO_A_ITEM_ID));
    }

    public function testRemovingAnUnknownDeclarationAnswers404(): void
    {
        $this->declareAndExpectCreated($this->pilot, self::PERSO_A_ITEM_ID);

        $this->request('DELETE', self::PATH.'/'.self::UNKNOWN_DECLARATION_ID);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, $this->declarationCountOf(self::PERSO_A_ITEM_ID));
    }

    public function testEveryOperationIsRefusedWithoutAuthentication(): void
    {
        foreach ([
            ['GET', self::PATH, null],
            ['POST', self::PATH, ['itemId' => self::PERSO_A_ITEM_ID]],
            ['DELETE', self::PATH.'/'.self::UNKNOWN_DECLARATION_ID, null],
        ] as [$method, $path, $body]) {
            $this->request($method, $path, $body);

            self::assertResponseStatusCodeSame(401);
        }
        self::assertSame(0, $this->declarationCountOf(self::PERSO_A_ITEM_ID));
    }

    private function declareAndExpectCreated(User $user, int $itemId): string
    {
        $this->actAs($user);
        $this->request('POST', self::PATH, ['itemId' => $itemId]);
        self::assertResponseStatusCodeSame(201);

        return $this->responseJson()['id'];
    }

    private function listedItemIds(): array
    {
        $this->request('GET', self::PATH);
        self::assertResponseStatusCodeSame(200);

        return array_map(static fn (array $entry): int => $entry['itemId'], $this->responseJson());
    }

    private function declarationCountOf(int $itemId): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM personal_stock_containers WHERE item_id = :itemId', ['itemId' => $itemId]);
    }

    private function persistPilot(string $name): User
    {
        $character = (new Character())
            ->setEveCharacterId(self::$nextEveCharacterId++)
            ->setName($name)
            ->setCorporationId(self::CORPORATION_ID)
            ->setCorporationName('Solberg Industries');
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);

        return $user;
    }

    private function connectDirector(User $user): void
    {
        $token = (new EveToken())
            ->setAccessToken('access-token')
            ->setRefreshTokenEncrypted('encrypted-refresh-token')
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+20 minutes'))
            ->setScopes([EveConstants::CORPORATION_ASSETS_SCOPE, EveConstants::CORPORATION_ROLES_SCOPE]);
        $user->getMainCharacter()?->setEveToken($token);
        $this->em->persist($token);
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('get')->willReturn(['roles' => ['Director']]);
        self::getContainer()->set(EsiClient::class, $esiClient);
    }

    private function persistAsset(int $itemId, int $typeId, int $quantity, int $locationId, string $locationType, string $locationFlag, ?string $itemName, ?string $typeName, ?bool $isSingleton): void
    {
        $this->em->persist(
            (new CachedAsset())
                ->setItemId($itemId)
                ->setTypeId($typeId)
                ->setTypeName($typeName)
                ->setQuantity($quantity)
                ->setLocationId($locationId)
                ->setLocationName('Jita IV - Moon 4 - Caldari Navy Assembly Plant')
                ->setSolarSystemName('Jita')
                ->setLocationType($locationType)
                ->setLocationFlag($locationFlag)
                ->setDivisionName('Reactions')
                ->setItemName($itemName)
                ->setIsSingleton($isSingleton)
                ->setCorporationId(self::CORPORATION_ID)
                ->setIsCorporationAsset(true),
        );
    }

    private function actAs(User $user): void
    {
        $this->actingUser = $user;
    }

    private function request(string $method, string $uri, ?array $body = null): void
    {
        $this->send($method, $uri, $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function send(string $method, string $uri, ?string $content): void
    {
        if (null !== $this->actingUser) {
            $this->client->getKernel()->boot();
            $this->client->loginUser($this->em->find(User::class, $this->actingUser->getId()), 'api');
        }
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $content);
    }

    private function responseJson(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
