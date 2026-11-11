<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Constant\EveConstants;
use App\Dto\CharacterSellOrder;
use App\Entity\CachedCharacterOrder;
use App\Entity\Character;
use App\Entity\User;
use App\Enum\OrderPositionState;
use App\Enum\OrderPositionUnknownReason;
use App\Exception\EsiApiException;
use App\Repository\CachedCharacterOrderRepository;
use App\Service\ESI\CharacterOrdersService;
use App\Service\Industry\SellOrders\SellOrderBooks;
use App\Service\Industry\SellOrders\UndercutAssessor;
use App\Service\Industry\SellOrders\UserSellableProductTypes;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Sync\CharacterOrderSyncService;
use App\Tests\Unit\Service\Industry\Blueprint\BuildsBlueprintLibrary;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[CoversClass(CharacterOrderSyncService::class)]
#[CoversClass(CachedCharacterOrder::class)]
#[Group('issue-350')]
final class CharacterOrderSyncServiceTest extends TestCase
{
    use BuildsBlueprintLibrary;

    private const string ORDERS_SCOPE = 'esi-markets.read_character_orders.v1';
    private const string SYNC_TYPE = 'character-orders';
    private const int HAMMERHEAD_TYPE_ID = 2185;
    private const int SABRE_TYPE_ID = 22456;
    private const int RIFTER_TYPE_ID = 587;
    private const int AMARR_STATION_ID = 60_008_494;
    private const string NOW = '2026-11-10 12:15:00';

    private Character $main;
    private Character $alt;
    private User $user;

    private array $events = [];
    private array $persisted = [];
    private array $removed = [];
    private array $mercureCalls = [];
    private array $ordersByCharacter = [];
    private array $cachedByCharacter = [];
    private array $booksByLocationAndType = [];
    private array $productTypes = [self::HAMMERHEAD_TYPE_ID, self::SABRE_TYPE_ID];
    private array $jitaRefreshes = [];

    protected function setUp(): void
    {
        $this->main = $this->pilot('Aria Solberg', self::MAIN_CORPORATION_ID, self::MAIN_CORPORATION_NAME, [self::ORDERS_SCOPE]);
        $this->alt = $this->pilot('Brann Holt', self::MAIN_CORPORATION_ID, self::MAIN_CORPORATION_NAME, [self::ORDERS_SCOPE]);
        $this->user = $this->userOf(self::MAIN_USER_ID, $this->main, $this->alt);
        $this->booksByLocationAndType[EveConstants::JITA_STATION_ID . ':' . self::HAMMERHEAD_TYPE_ID] = [['price' => 900.0, 'volume' => 2], ['price' => 1000.0, 'volume' => 1]];
    }

    public function testStoresTheSellOrderWithItsPositionAndTheSyncDateAsUndercutSince(): void
    {
        $this->ordersByCharacter['Aria Solberg'] = [$this->order(1, self::HAMMERHEAD_TYPE_ID, 1000.0)];

        $this->service()->syncUserOrders($this->user);

        self::assertCount(1, $this->persisted);
        $row = $this->persisted[0];
        self::assertSame(
            [1, $this->main, self::HAMMERHEAD_TYPE_ID, EveConstants::JITA_STATION_ID, 1000.0, 4, 10, OrderPositionState::Undercut, 2, 900.0, 800.0, self::NOW],
            [$row->getOrderId(), $row->getCharacter(), $row->getTypeId(), $row->getLocationId(), $row->getPrice(), $row->getVolumeRemain(), $row->getVolumeTotal(), $row->getPosition()->state, $row->getPosition()->rank, $row->getPosition()->bestPrice, $row->getPosition()->suggestedPrice, $row->getUndercutSince()?->format('Y-m-d H:i:s')],
        );
        self::assertSame(self::NOW, $row->getSyncedAt()->format('Y-m-d H:i:s'));
    }

    public function testOrderStillUndercutKeepsItsFirstUndercutDate(): void
    {
        $existing = $this->cachedRow($this->main, 1, self::HAMMERHEAD_TYPE_ID, 1000.0, '2026-11-10 12:00:00');
        $this->cachedByCharacter['Aria Solberg'] = [$existing];
        $this->ordersByCharacter['Aria Solberg'] = [$this->order(1, self::HAMMERHEAD_TYPE_ID, 1000.0)];

        $this->service()->syncUserOrders($this->user);

        self::assertSame('2026-11-10 12:00:00', $existing->getUndercutSince()?->format('Y-m-d H:i:s'));
        self::assertSame(self::NOW, $existing->getSyncedAt()->format('Y-m-d H:i:s'));
        self::assertSame([], $this->persisted);
    }

    public function testOrderBackToFirstLosesItsUndercutDate(): void
    {
        $existing = $this->cachedRow($this->main, 1, self::HAMMERHEAD_TYPE_ID, 1000.0, '2026-11-10 12:00:00');
        $this->cachedByCharacter['Aria Solberg'] = [$existing];
        $this->ordersByCharacter['Aria Solberg'] = [$this->order(1, self::HAMMERHEAD_TYPE_ID, 850.0)];

        $this->service()->syncUserOrders($this->user);

        self::assertSame([OrderPositionState::First, 850.0, null], [$existing->getPosition()->state, $existing->getPrice(), $existing->getUndercutSince()]);
    }

    public function testOrderNoLongerReturnedByTheEsiIsRemoved(): void
    {
        $gone = $this->cachedRow($this->main, 7, self::HAMMERHEAD_TYPE_ID, 1000.0, null);
        $this->cachedByCharacter['Aria Solberg'] = [$gone];
        $this->ordersByCharacter['Aria Solberg'] = [];

        $this->service()->syncUserOrders($this->user);

        self::assertSame([$gone], $this->removed);
    }

    public function testOrderOfATypeThatIsNotAProjectProductIsIgnored(): void
    {
        $this->ordersByCharacter['Aria Solberg'] = [$this->order(1, self::RIFTER_TYPE_ID, 500.0)];

        $this->service()->syncUserOrders($this->user);

        self::assertSame([], $this->persisted);
        self::assertSame([], $this->jitaRefreshes);
    }

    public function testOrderAtAnUnfollowedMarketIsStoredAsUnknownWithItsCause(): void
    {
        $this->ordersByCharacter['Aria Solberg'] = [$this->order(1, self::SABRE_TYPE_ID, 700.0, self::AMARR_STATION_ID)];

        $this->service()->syncUserOrders($this->user);

        $position = $this->persisted[0]->getPosition();
        self::assertSame([OrderPositionState::Unknown, OrderPositionUnknownReason::MarketNotFollowed, null], [$position->state, $position->unknownReason, $this->persisted[0]->getUndercutSince()]);
    }

    public function testJitaBooksAreRefreshedOnceForTheJitaTypesOfAllTheCharacters(): void
    {
        $this->ordersByCharacter['Aria Solberg'] = [$this->order(1, self::HAMMERHEAD_TYPE_ID, 1000.0), $this->order(2, self::SABRE_TYPE_ID, 700.0, self::AMARR_STATION_ID)];
        $this->ordersByCharacter['Brann Holt'] = [$this->order(3, self::HAMMERHEAD_TYPE_ID, 950.0), $this->order(4, self::SABRE_TYPE_ID, 3_000.0)];

        $this->service()->syncUserOrders($this->user);

        self::assertSame([[self::HAMMERHEAD_TYPE_ID, self::SABRE_TYPE_ID]], $this->jitaRefreshes);
    }

    public function testCharacterWithoutTheOrdersScopeIsIgnored(): void
    {
        $this->alt->setEveToken(null);
        $withoutScope = $this->pilot('Corin Vale', self::ALT_CORPORATION_ID, 'Vale Freight', ['esi-assets.read_assets.v1']);
        $this->user->addCharacter($withoutScope);
        $this->ordersByCharacter['Aria Solberg'] = [$this->order(1, self::HAMMERHEAD_TYPE_ID, 1000.0)];

        $this->service()->syncUserOrders($this->user);

        self::assertSame(['read:Aria Solberg'], array_values(array_filter($this->events, static fn (string $event): bool => str_starts_with($event, 'read:'))));
    }

    public function testFailedCharacterKeepsItsRowsAndDoesNotBlockTheOthers(): void
    {
        $kept = $this->cachedRow($this->main, 1, self::HAMMERHEAD_TYPE_ID, 1000.0, null);
        $this->cachedByCharacter['Aria Solberg'] = [$kept];
        $this->ordersByCharacter['Aria Solberg'] = EsiApiException::fromResponse(500, 'ESI down', '/characters/1/orders/');
        $this->ordersByCharacter['Brann Holt'] = [$this->order(3, self::HAMMERHEAD_TYPE_ID, 950.0)];

        $this->service()->syncUserOrders($this->user);

        self::assertSame([], $this->removed);
        self::assertSame([3], array_map(static fn (CachedCharacterOrder $row): int => $row->getOrderId(), $this->persisted));
        self::assertSame(
            ['characters' => 1, 'failedCharacters' => [['characterName' => 'Aria Solberg', 'reason' => 'esi_unavailable', 'statusCode' => 500]]],
            $this->completedData(),
        );
    }

    public function testNoSellableProjectMeansNoEsiReadAndTheCachedOrdersAreRemoved(): void
    {
        $stale = $this->cachedRow($this->main, 1, self::HAMMERHEAD_TYPE_ID, 1000.0, null);
        $this->cachedByCharacter['Aria Solberg'] = [$stale];
        $this->productTypes = [];

        $this->service()->syncUserOrders($this->user);

        self::assertSame([], array_values(array_filter($this->events, static fn (string $event): bool => str_starts_with($event, 'read:'))));
        self::assertSame([$stale], $this->removed);
        self::assertContains('completed', array_column($this->mercureCalls, 0));
    }

    public function testEveryCharacterFailingPublishesASyncError(): void
    {
        $this->ordersByCharacter['Aria Solberg'] = EsiApiException::fromResponse(500, 'ESI down', '/characters/1/orders/');
        $this->ordersByCharacter['Brann Holt'] = EsiApiException::fromResponse(500, 'ESI down', '/characters/2/orders/');

        $this->service()->syncUserOrders($this->user);

        self::assertSame(['started', 'error'], array_column($this->mercureCalls, 0));
    }

    public function testSyncPublishesStartedThenCompletedOnTheCharacterOrdersTopic(): void
    {
        $this->ordersByCharacter['Aria Solberg'] = [];
        $this->ordersByCharacter['Brann Holt'] = [];

        $this->service()->syncUserOrders($this->user);

        self::assertSame(['started', 'completed'], array_column($this->mercureCalls, 0));
        self::assertSame(['/user/' . self::MAIN_USER_ID . '/sync/' . self::SYNC_TYPE], array_values(array_unique(array_column($this->mercureCalls, 1))));
        self::assertSame([self::SYNC_TYPE], array_values(array_unique(array_column($this->mercureCalls, 2))));
    }

    private function order(int $orderId, int $typeId, float $price, int $locationId = EveConstants::JITA_STATION_ID): CharacterSellOrder
    {
        return new CharacterSellOrder($orderId, $typeId, $locationId, $price, 4, 10, new \DateTimeImmutable('2026-11-10 08:00:00'));
    }

    private function cachedRow(Character $character, int $orderId, int $typeId, float $price, ?string $undercutSince): CachedCharacterOrder
    {
        $row = (new CachedCharacterOrder())
            ->setCharacter($character)
            ->setOrderId($orderId)
            ->setTypeId($typeId)
            ->setLocationId(EveConstants::JITA_STATION_ID)
            ->setPrice($price)
            ->setVolumeRemain(4)
            ->setVolumeTotal(10)
            ->setIssuedAt(new \DateTimeImmutable('2026-11-10 08:00:00'))
            ->setSyncedAt(new \DateTimeImmutable('2026-11-10 12:00:00'))
            ->setUndercutSince($undercutSince === null ? null : new \DateTimeImmutable($undercutSince));

        return $row;
    }

    private function completedData(): array
    {
        $completed = array_values(array_filter($this->mercureCalls, static fn (array $call): bool => $call[0] === 'completed'));

        return $completed[0][3] ?? [];
    }

    private function service(): CharacterOrderSyncService
    {
        $ordersService = $this->createStub(CharacterOrdersService::class);
        $ordersService->method('getSellOrders')->willReturnCallback(
            function (Character $character): array {
                $this->events[] = 'read:' . $character->getName();
                $outcome = $this->ordersByCharacter[$character->getName()] ?? [];
                if ($outcome instanceof \Throwable) {
                    throw $outcome;
                }

                return $outcome;
            },
        );

        $repository = $this->createStub(CachedCharacterOrderRepository::class);
        $repository->method('findByCharacter')->willReturnCallback(
            fn (Character $character): array => $this->cachedByCharacter[$character->getName()] ?? [],
        );

        $productTypes = $this->createStub(UserSellableProductTypes::class);
        $productTypes->method('of')->willReturnCallback(fn (): array => $this->productTypes);

        $books = $this->createStub(SellOrderBooks::class);
        $books->method('of')->willReturnCallback(
            fn (int $locationId, int $typeId): ?array => $this->booksByLocationAndType[$locationId . ':' . $typeId] ?? ($locationId === EveConstants::JITA_STATION_ID ? [] : null),
        );
        $books->method('refreshJita')->willReturnCallback(
            function (array $typeIds): void {
                $this->jitaRefreshes[] = $typeIds;
            },
        );

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(
            function (object $entity): void {
                $this->persisted[] = $entity;
            },
        );
        $entityManager->method('remove')->willReturnCallback(
            function (object $entity): void {
                $this->removed[] = $entity;
            },
        );

        return new CharacterOrderSyncService(
            $ordersService,
            $repository,
            $productTypes,
            $books,
            new UndercutAssessor(),
            $entityManager,
            new MockClock(self::NOW),
            new NullLogger(),
            $this->mercurePublisher(),
        );
    }

    private function mercurePublisher(): MercurePublisherService
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(
            function (Update $update): string {
                $payload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
                $this->mercureCalls[] = [$payload['status'], $update->getTopics()[0], $payload['syncType'], $payload['data']];

                return 'urn:uuid:published';
            },
        );

        return new MercurePublisherService($hub, new NullLogger());
    }
}
