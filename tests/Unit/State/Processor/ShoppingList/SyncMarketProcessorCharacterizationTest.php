<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\ShoppingList;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\ShoppingList\SyncMarketInput;
use App\ApiResource\ShoppingList\SyncMarketResultResource;
use App\Constant\EveConstants;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Service\ESI\EsiClient;
use App\Service\ESI\MarketService;
use App\Service\JitaMarketService;
use App\Service\Mercure\MercurePublisherService;
use App\Service\StructureMarketService;
use App\Service\StructureMarketSnapshotService;
use App\State\Processor\ShoppingList\SyncMarketProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SyncMarketProcessor::class)]
final class SyncMarketProcessorCharacterizationTest extends TestCase
{
    private const string USER_ID = '0192a7c4-ae60-7c41-9d5f-8a9b0c1d2e34';

    private const int DEFAULT_STRUCTURE_ID = 1049588174021;
    private const string DEFAULT_STRUCTURE_NAME = 'C-J6MT - 1st Taj Mahgoon (Keepstar)';
    private const int PREFERRED_STRUCTURE_ID = 1035466617946;
    private const string PREFERRED_STRUCTURE_NAME = 'Perimeter - Tranquility Trading Tower';
    private const int REQUESTED_STRUCTURE_ID = 1028858195912;
    private const string REQUESTED_STRUCTURE_NAME = 'Ashab - Market Fortizar';

    private const int TRITANIUM = 34;
    private const int PYERITE = 35;

    private EsiClient&Stub $esiClient;
    private ArrayAdapter $structureCache;
    private BufferingLogger $logger;
    private User $user;

    private array $esiStructureNames = [];

    private array $esiStructureOrders = [];

    private array $structureNameRequests = [];

    private array $structureOrdersRequests = [];

    private array $publishedUpdates = [];

    protected function setUp(): void
    {
        $this->esiClient = $this->createStub(EsiClient::class);
        $this->esiClient->method('get')->willReturnCallback(function (string $endpoint, ?EveToken $token): array {
            $this->structureNameRequests[] = [$endpoint, $token];
            $name = $this->esiStructureNames[$endpoint] ?? null;
            if ($name === null) {
                throw EsiApiException::forbidden('Forbidden structure', $endpoint);
            }

            return ['name' => $name, 'solar_system_id' => 30000142];
        });
        $this->esiClient->method('getPaginated')->willReturnCallback(function (string $endpoint, ?EveToken $token): array {
            $this->structureOrdersRequests[] = [$endpoint, $token];
            $orders = array_shift($this->esiStructureOrders[$endpoint]);
            if ($orders instanceof \Throwable) {
                throw $orders;
            }

            return $orders;
        });
        $this->structureCache = new ArrayAdapter();
        $this->logger = new BufferingLogger();

        $this->user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($this->user, Uuid::fromString(self::USER_ID));
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
    }

    public function testRequestedStructureIsSyncedWithTheFirstCharacterHavingTheStructureMarketScopeAndItsPricesAreCached(): void
    {
        $this->user->setPreferredMarketStructureId(self::PREFERRED_STRUCTURE_ID);
        $this->user->addCharacter($this->createCharacter(null));
        $structureMarketToken = $this->createToken([EveConstants::STRUCTURE_MARKET_SCOPE]);
        $this->user->addCharacter($this->createCharacter($structureMarketToken));
        $this->givenStructureName(self::REQUESTED_STRUCTURE_ID, self::REQUESTED_STRUCTURE_NAME);
        $this->givenStructureOrders(self::REQUESTED_STRUCTURE_ID, $this->requestedStructureOrders());

        $result = $this->process(self::REQUESTED_STRUCTURE_ID);

        $this->assertSame('sync', $result->id);
        $this->assertTrue($result->success);
        $this->assertSame(self::REQUESTED_STRUCTURE_ID, $result->structureId);
        $this->assertSame(self::REQUESTED_STRUCTURE_NAME, $result->structureName);
        $this->assertSame(4, $result->totalOrders);
        $this->assertSame(3, $result->sellOrders);
        $this->assertSame(1, $result->buyOrders);
        $this->assertSame(2, $result->typeCount);
        $this->assertNull($result->error);
        $this->assertSame([['/universe/structures/' . self::REQUESTED_STRUCTURE_ID . '/', $structureMarketToken]], $this->structureNameRequests);
        $this->assertSame([['/markets/structures/' . self::REQUESTED_STRUCTURE_ID . '/', $structureMarketToken]], $this->structureOrdersRequests);
        $structureMarketService = $this->createStructureMarketService($this->structureCache);
        $this->assertSame(4.25, $structureMarketService->getLowestSellPrice(self::REQUESTED_STRUCTURE_ID, self::TRITANIUM));
        $this->assertSame(3.875, $structureMarketService->getHighestBuyPrice(self::REQUESTED_STRUCTURE_ID, self::TRITANIUM));
        $this->assertSame(10.75, $structureMarketService->getLowestSellPrice(self::REQUESTED_STRUCTURE_ID, self::PYERITE));
        $this->assertNull($structureMarketService->getHighestBuyPrice(self::REQUESTED_STRUCTURE_ID, self::PYERITE));
        $this->assertSame(
            array_fill(0, 4, ['/user/' . self::USER_ID . '/sync/market-structure']),
            array_map(static fn (Update $update): array => $update->getTopics(), $this->publishedUpdates),
        );
        $this->assertSame(
            ['started', 'in_progress', 'in_progress', 'completed'],
            array_map(static fn (Update $update): string => json_decode($update->getData(), true, 512, JSON_THROW_ON_ERROR)['status'], $this->publishedUpdates),
        );
    }

    public function testWithoutRequestedStructureThePreferredStructureIsSyncedUnderAGenericNameWhenEsiRefusesItsName(): void
    {
        $this->user->setPreferredMarketStructureId(self::PREFERRED_STRUCTURE_ID);
        $token = $this->createToken([EveConstants::STRUCTURE_MARKET_SCOPE]);
        $this->user->addCharacter($this->createCharacter($token));
        $this->givenStructureOrders(self::PREFERRED_STRUCTURE_ID, [[
            ['type_id' => self::PYERITE, 'price' => 11.125, 'volume_remain' => 380, 'is_buy_order' => false],
            ['type_id' => self::PYERITE, 'price' => 9.5625, 'volume_remain' => 2100, 'is_buy_order' => true],
        ]]);

        $result = $this->process(null);

        $this->assertTrue($result->success);
        $this->assertSame(self::PREFERRED_STRUCTURE_ID, $result->structureId);
        $this->assertSame('Structure ' . self::PREFERRED_STRUCTURE_ID, $result->structureName);
        $this->assertSame(2, $result->totalOrders);
        $this->assertSame(1, $result->sellOrders);
        $this->assertSame(1, $result->buyOrders);
        $this->assertSame(1, $result->typeCount);
        $this->assertSame([['/markets/structures/' . self::PREFERRED_STRUCTURE_ID . '/', $token]], $this->structureOrdersRequests);
    }

    public function testWithoutRequestedNorPreferredStructureTheDefaultStructureIsSyncedUnderItsConfiguredName(): void
    {
        $this->user->setPreferredMarketStructureId(null);
        $token = $this->createToken([EveConstants::STRUCTURE_MARKET_SCOPE]);
        $this->user->addCharacter($this->createCharacter($token));
        $this->givenStructureOrders(self::DEFAULT_STRUCTURE_ID, [[
            ['type_id' => self::TRITANIUM, 'price' => 4.625, 'volume_remain' => 940, 'is_buy_order' => false],
        ]]);

        $result = $this->process(null);

        $this->assertTrue($result->success);
        $this->assertSame(self::DEFAULT_STRUCTURE_ID, $result->structureId);
        $this->assertSame(self::DEFAULT_STRUCTURE_NAME, $result->structureName);
        $this->assertSame(1, $result->totalOrders);
        $this->assertSame(1, $result->sellOrders);
        $this->assertSame(0, $result->buyOrders);
        $this->assertSame(1, $result->typeCount);
        $this->assertSame([['/markets/structures/' . self::DEFAULT_STRUCTURE_ID . '/', $token]], $this->structureOrdersRequests);
    }

    public function testUserWithoutIdentifierIsSyncedWithoutSyncProgressPublication(): void
    {
        $this->user = new User();
        $this->user->addCharacter($this->createCharacter($this->createToken([EveConstants::STRUCTURE_MARKET_SCOPE])));
        $this->givenStructureName(self::REQUESTED_STRUCTURE_ID, self::REQUESTED_STRUCTURE_NAME);
        $this->givenStructureOrders(self::REQUESTED_STRUCTURE_ID, $this->requestedStructureOrders());

        $result = $this->process(self::REQUESTED_STRUCTURE_ID);

        $this->assertTrue($result->success);
        $this->assertSame(4, $result->totalOrders);
        $this->assertSame(2, $result->typeCount);
        $this->assertSame([], $this->publishedUpdates);
    }

    #[Group('market-audit-4')]
    public function testSyncCurrentlyUsesTheFirstCharacterTokenEvenWithoutTheStructureMarketScopeMarketAudit4(): void
    {
        $tokenWithoutStructureMarketScope = $this->createToken(['esi-assets.read_assets.v1', 'esi-markets.read_character_orders.v1']);
        $tokenWithStructureMarketScope = $this->createToken([EveConstants::STRUCTURE_MARKET_SCOPE]);
        $this->user->addCharacter($this->createCharacter(null));
        $this->user->addCharacter($this->createCharacter($tokenWithoutStructureMarketScope));
        $this->user->addCharacter($this->createCharacter($tokenWithStructureMarketScope));
        $this->givenStructureName(self::REQUESTED_STRUCTURE_ID, self::REQUESTED_STRUCTURE_NAME);
        $this->givenStructureOrders(self::REQUESTED_STRUCTURE_ID, [EsiApiException::forbidden('Forbidden', '/markets/structures/' . self::REQUESTED_STRUCTURE_ID . '/')]);

        $result = $this->process(self::REQUESTED_STRUCTURE_ID);

        $this->assertFalse($result->success);
        $this->assertSame('Forbidden', $result->error);
        $this->assertSame([['/markets/structures/' . self::REQUESTED_STRUCTURE_ID . '/', $tokenWithoutStructureMarketScope]], $this->structureOrdersRequests);
        $this->assertSame([['/universe/structures/' . self::REQUESTED_STRUCTURE_ID . '/', $tokenWithoutStructureMarketScope]], $this->structureNameRequests);
    }

    #[Group('market-audit-4')]
    public function testFailedSyncCurrentlyEmptiesTheSharedStructurePricesCachedByAPreviousSyncMarketAudit4(): void
    {
        $this->user->addCharacter($this->createCharacter($this->createToken([EveConstants::STRUCTURE_MARKET_SCOPE])));
        $this->givenStructureName(self::REQUESTED_STRUCTURE_ID, self::REQUESTED_STRUCTURE_NAME);
        $this->givenStructureOrders(self::REQUESTED_STRUCTURE_ID, $this->requestedStructureOrders());
        $this->givenStructureOrders(self::REQUESTED_STRUCTURE_ID, [EsiApiException::forbidden('Forbidden', '/markets/structures/' . self::REQUESTED_STRUCTURE_ID . '/')]);
        $structureMarketService = $this->createStructureMarketService($this->structureCache);
        $firstResult = $this->process(self::REQUESTED_STRUCTURE_ID);
        $this->assertTrue($firstResult->success);
        $this->assertSame(4.25, $structureMarketService->getLowestSellPrice(self::REQUESTED_STRUCTURE_ID, self::TRITANIUM));

        $result = $this->process(self::REQUESTED_STRUCTURE_ID);

        $this->assertFalse($result->success);
        $this->assertSame('Forbidden', $result->error);
        $this->assertSame(self::REQUESTED_STRUCTURE_ID, $result->structureId);
        $this->assertSame(self::REQUESTED_STRUCTURE_NAME, $result->structureName);
        $this->assertSame(0, $result->totalOrders);
        $this->assertSame(0, $result->sellOrders);
        $this->assertSame(0, $result->buyOrders);
        $this->assertSame(0, $result->typeCount);
        $this->assertNull($structureMarketService->getLowestSellPrice(self::REQUESTED_STRUCTURE_ID, self::TRITANIUM));
        $this->assertNull($structureMarketService->getHighestBuyPrice(self::REQUESTED_STRUCTURE_ID, self::TRITANIUM));
        $this->assertNull($structureMarketService->getLowestSellPrice(self::REQUESTED_STRUCTURE_ID, self::PYERITE));
        $this->assertSame([], $structureMarketService->getSellOrders(self::REQUESTED_STRUCTURE_ID, self::TRITANIUM));
        $this->assertNull($structureMarketService->getLastSyncTime(self::REQUESTED_STRUCTURE_ID));
    }

    #[Group('market-audit-4')]
    public function testSharedCacheClearingFailureCurrentlyAbortsTheSyncBeforeAnyEsiMarketCallMarketAudit4(): void
    {
        $this->user->addCharacter($this->createCharacter($this->createToken([EveConstants::STRUCTURE_MARKET_SCOPE])));
        $this->givenStructureName(self::REQUESTED_STRUCTURE_ID, self::REQUESTED_STRUCTURE_NAME);
        $failingCache = $this->createStub(CacheItemPoolInterface::class);
        $failingCache->method('deleteItem')->willThrowException(new \RuntimeException('Redis connection refused'));

        $result = $this->createProcessor($failingCache, $this->user)->process($this->input(self::REQUESTED_STRUCTURE_ID), new Post());

        $this->assertFalse($result->success);
        $this->assertSame('Sync failed: Redis connection refused', $result->error);
        $this->assertSame(self::REQUESTED_STRUCTURE_ID, $result->structureId);
        $this->assertSame(self::REQUESTED_STRUCTURE_NAME, $result->structureName);
        $this->assertSame([], $this->structureOrdersRequests);
        $this->assertSame([[
            LogLevel::ERROR,
            'Structure market sync failed',
            ['structureId' => self::REQUESTED_STRUCTURE_ID, 'error' => 'Redis connection refused'],
        ]], $this->logger->cleanLogs());
    }

    public function testUserWithoutAnyCharacterTokenIsRejectedBeforeAnyEsiCall(): void
    {
        $this->user->addCharacter($this->createCharacter(null));

        try {
            $this->process(self::REQUESTED_STRUCTURE_ID);
            $this->fail('A BadRequestHttpException was expected');
        } catch (BadRequestHttpException $exception) {
            $this->assertSame('No character with valid token', $exception->getMessage());
        }

        $this->assertSame([], $this->structureNameRequests);
        $this->assertSame([], $this->structureOrdersRequests);
    }

    public function testAnonymousCallerIsRejectedBeforeAnyEsiCall(): void
    {
        $processor = $this->createProcessor($this->structureCache, null);

        try {
            $processor->process($this->input(self::REQUESTED_STRUCTURE_ID), new Post());
            $this->fail('An UnauthorizedHttpException was expected');
        } catch (UnauthorizedHttpException $exception) {
            $this->assertSame('Unauthorized', $exception->getMessage());
            $this->assertSame(['WWW-Authenticate' => 'Bearer'], $exception->getHeaders());
        }

        $this->assertSame([], $this->structureNameRequests);
        $this->assertSame([], $this->structureOrdersRequests);
    }

    private function process(?int $requestedStructureId): SyncMarketResultResource
    {
        return $this->createProcessor($this->structureCache, $this->user)->process($this->input($requestedStructureId), new Post());
    }

    private function createProcessor(CacheItemPoolInterface $structureCache, ?User $authenticatedUser): SyncMarketProcessor
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($authenticatedUser);

        return new SyncMarketProcessor(
            $security,
            $this->createMarketService($structureCache),
            $this->createStructureMarketService($structureCache),
            $this->logger,
            self::DEFAULT_STRUCTURE_ID,
            self::DEFAULT_STRUCTURE_NAME,
        );
    }

    private function createMarketService(CacheItemPoolInterface $structureCache): MarketService
    {
        return new MarketService(
            $this->esiClient,
            new ArrayAdapter(),
            new NullLogger(),
            $this->createStructureMarketService($structureCache),
            $this->createStub(JitaMarketService::class),
            self::DEFAULT_STRUCTURE_ID,
            self::DEFAULT_STRUCTURE_NAME,
        );
    }

    private function createStructureMarketService(CacheItemPoolInterface $structureCache): StructureMarketService
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'urn:uuid:' . Uuid::v4()->toRfc4122();
        });

        return new StructureMarketService(
            $this->esiClient,
            $structureCache,
            new NullLogger(),
            new MercurePublisherService($hub, new NullLogger()),
            $this->createStub(StructureMarketSnapshotService::class),
        );
    }

    private function input(?int $structureId): SyncMarketInput
    {
        $input = new SyncMarketInput();
        $input->structureId = $structureId;

        return $input;
    }

    private function createCharacter(?EveToken $token): Character
    {
        $character = new Character();
        $character->setEveToken($token);

        return $character;
    }

    private function createToken(array $scopes): EveToken
    {
        return (new EveToken())->setScopes($scopes);
    }

    private function givenStructureName(int $structureId, string $name): void
    {
        $this->esiStructureNames['/universe/structures/' . $structureId . '/'] = $name;
    }

    private function givenStructureOrders(int $structureId, array $responses): void
    {
        $endpoint = '/markets/structures/' . $structureId . '/';
        $this->esiStructureOrders[$endpoint] = [...($this->esiStructureOrders[$endpoint] ?? []), ...$responses];
    }

    private function requestedStructureOrders(): array
    {
        return [[
            ['type_id' => self::TRITANIUM, 'price' => 4.5, 'volume_remain' => 1300, 'is_buy_order' => false],
            ['type_id' => self::TRITANIUM, 'price' => 4.25, 'volume_remain' => 720, 'is_buy_order' => false],
            ['type_id' => self::TRITANIUM, 'price' => 3.875, 'volume_remain' => 5100, 'is_buy_order' => true],
            ['type_id' => self::PYERITE, 'price' => 10.75, 'volume_remain' => 415, 'is_buy_order' => false],
        ]];
    }
}
