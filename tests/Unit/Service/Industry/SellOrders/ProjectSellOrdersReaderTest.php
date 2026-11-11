<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\SellOrders;

use App\Constant\EveConstants;
use App\Entity\CachedCharacterOrder;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Enum\OrderPositionState;
use App\Enum\OrderPositionUnknownReason;
use App\Enum\ProjectStatus;
use App\Repository\CachedCharacterOrderRepository;
use App\Dto\OrderPosition;
use App\Service\Industry\SellOrders\ProjectProductTypes;
use App\Service\Industry\SellOrders\ProjectSellOrder;
use App\Service\Industry\SellOrders\ProjectSellOrders;
use App\Service\Industry\SellOrders\ProjectSellOrdersReader;
use App\Service\Industry\SellOrders\SellOrderMarketNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectSellOrdersReader::class)]
#[CoversClass(ProjectSellOrders::class)]
#[CoversClass(ProjectSellOrder::class)]
#[Group('issue-350')]
final class ProjectSellOrdersReaderTest extends TestCase
{
    private const string ORDERS_SCOPE = 'esi-markets.read_character_orders.v1';
    private const int HAMMERHEAD_TYPE_ID = 2185;
    private const int SABRE_TYPE_ID = 22456;
    private const int AMARR_STATION_ID = 60_008_494;

    private Character $main;
    private Character $alt;
    private User $viewer;
    private IndustryProject $project;

    protected function setUp(): void
    {
        $this->main = $this->pilot(1, 'Aria Solberg', [self::ORDERS_SCOPE]);
        $this->alt = $this->pilot(2, 'Brann Holt', [self::ORDERS_SCOPE]);
        $this->viewer = new User();
        $this->viewer->addCharacter($this->main);
        $this->viewer->addCharacter($this->alt);
        $this->project = (new IndustryProject())->setStatus(ProjectStatus::Selling)->setPersonalUse(false);
        $this->finalJob(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II');
        $this->finalJob(self::SABRE_TYPE_ID, 'Sabre');
    }

    public function testReadsOnlyTheViewerCharactersOrdersOfTheProjectProducts(): void
    {
        $repository = $this->createMock(CachedCharacterOrderRepository::class);
        $repository->expects(self::once())
            ->method('findByCharactersAndTypes')
            ->with([$this->main, $this->alt], [self::HAMMERHEAD_TYPE_ID, self::SABRE_TYPE_ID])
            ->willReturn([]);

        $orders = $this->reader($repository)->of($this->project, $this->viewer);

        self::assertSame([], $orders->orders);
        self::assertNull($orders->syncedAt);
    }

    public function testOrdersAreSortedUndercutOldestFirstThenUnknownThenFirst(): void
    {
        $first = $this->row(1, $this->main, self::HAMMERHEAD_TYPE_ID, new OrderPosition(OrderPositionState::First, 1, 1000.0, null, null), null);
        $unknown = $this->row(2, $this->alt, self::SABRE_TYPE_ID, new OrderPosition(OrderPositionState::Unknown, null, null, OrderPositionUnknownReason::MarketNotFollowed, null), null, self::AMARR_STATION_ID);
        $recentUndercut = $this->row(3, $this->main, self::HAMMERHEAD_TYPE_ID, new OrderPosition(OrderPositionState::Undercut, 3, 900.0, null, 800.0), '2026-11-10 12:10:00');
        $oldUndercut = $this->row(4, $this->alt, self::HAMMERHEAD_TYPE_ID, new OrderPosition(OrderPositionState::Undercut, 2, 950.0, null, 850.0), '2026-11-10 11:00:00');

        $orders = $this->reader($this->repositoryReturning([$first, $unknown, $recentUndercut, $oldUndercut]))->of($this->project, $this->viewer);

        self::assertSame([4, 3, 2, 1], array_map(static fn (ProjectSellOrder $order): int => $order->orderId, $orders->orders));
    }

    public function testOrderCarriesItsPositionNamesAndSuggestedPrice(): void
    {
        $row = $this->row(3, $this->main, self::HAMMERHEAD_TYPE_ID, new OrderPosition(OrderPositionState::Undercut, 3, 900.0, null, 800.0), '2026-11-10 12:10:00');

        $order = $this->reader($this->repositoryReturning([$row]))->of($this->project, $this->viewer)->orders[0];

        self::assertSame(
            [3, 'Aria Solberg', self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 4, EveConstants::JITA_STATION_ID, 'Jita IV - Moon 4', 1000.0, 900.0, 3, OrderPositionState::Undercut, null, '2026-11-10 12:10:00', 800.0],
            [$order->orderId, $order->characterName, $order->typeId, $order->typeName, $order->volumeRemain, $order->locationId, $order->marketName, $order->price, $order->bestPrice, $order->rank, $order->state, $order->unknownReason, $order->undercutSince?->format('Y-m-d H:i:s'), $order->suggestedPrice],
        );
    }

    public function testUnknownOrderKeepsItsCauseAndNoInventedPrice(): void
    {
        $row = $this->row(2, $this->alt, self::SABRE_TYPE_ID, new OrderPosition(OrderPositionState::Unknown, null, null, OrderPositionUnknownReason::MarketNotFollowed, null), null, self::AMARR_STATION_ID);

        $order = $this->reader($this->repositoryReturning([$row]))->of($this->project, $this->viewer)->orders[0];

        self::assertSame(
            [OrderPositionState::Unknown, OrderPositionUnknownReason::MarketNotFollowed, null, null, null, null],
            [$order->state, $order->unknownReason, $order->bestPrice, $order->rank, $order->suggestedPrice, $order->marketName],
        );
    }

    public function testSyncedAtIsTheMostRecentSyncOfTheOrders(): void
    {
        $older = $this->row(1, $this->main, self::HAMMERHEAD_TYPE_ID, new OrderPosition(OrderPositionState::First, 1, 1000.0, null, null), null);
        $newer = $this->row(2, $this->alt, self::SABRE_TYPE_ID, new OrderPosition(OrderPositionState::First, 1, 700.0, null, null), null);
        $newer->setSyncedAt(new \DateTimeImmutable('2026-11-10 12:20:00'));

        $orders = $this->reader($this->repositoryReturning([$older, $newer]))->of($this->project, $this->viewer);

        self::assertSame('2026-11-10 12:20:00', $orders->syncedAt?->format('Y-m-d H:i:s'));
    }

    public function testCharactersWithoutTheOrdersScopeOrWithAnInvalidAuthAreToReconnect(): void
    {
        $this->alt->getEveToken()?->setScopes(['esi-assets.read_assets.v1']);
        $withoutToken = (new Character())->setEveCharacterId(3)->setName('Corin Vale');
        $invalid = $this->pilot(4, 'Dara Quill', [self::ORDERS_SCOPE]);
        $invalid->markAuthInvalid();
        $this->viewer->addCharacter($withoutToken);
        $this->viewer->addCharacter($invalid);

        $orders = $this->reader($this->repositoryReturning([]))->of($this->project, $this->viewer);

        self::assertSame(['Brann Holt', 'Corin Vale', 'Dara Quill'], $orders->charactersToReconnect);
    }

    public function testPersonalUseProjectHasNoOrdersAndNoRead(): void
    {
        $this->project->setPersonalUse(true);
        $repository = $this->createMock(CachedCharacterOrderRepository::class);
        $repository->expects(self::never())->method('findByCharactersAndTypes');

        $orders = $this->reader($repository)->of($this->project, $this->viewer);

        self::assertSame([[], [], null], [$orders->orders, $orders->charactersToReconnect, $orders->syncedAt]);
    }

    public function testCompletedProjectHasNoOrdersAndNoRead(): void
    {
        $this->project->setStatus(ProjectStatus::Completed);
        $repository = $this->createMock(CachedCharacterOrderRepository::class);
        $repository->expects(self::never())->method('findByCharactersAndTypes');

        self::assertSame([], $this->reader($repository)->of($this->project, $this->viewer)->orders);
    }

    public function testUndercutOrdersKeepOnlyTheUndercutOnes(): void
    {
        $first = $this->row(1, $this->main, self::HAMMERHEAD_TYPE_ID, new OrderPosition(OrderPositionState::First, 1, 1000.0, null, null), null);
        $undercut = $this->row(3, $this->main, self::HAMMERHEAD_TYPE_ID, new OrderPosition(OrderPositionState::Undercut, 3, 900.0, null, 800.0), '2026-11-10 12:10:00');
        $unknown = $this->row(2, $this->alt, self::SABRE_TYPE_ID, new OrderPosition(OrderPositionState::Unknown, null, null, OrderPositionUnknownReason::NoMarketData, null), null);

        $undercutOrders = $this->reader($this->repositoryReturning([$first, $undercut, $unknown]))->undercutOrdersOf($this->project, $this->viewer);

        self::assertSame([3], array_map(static fn (ProjectSellOrder $order): int => $order->orderId, $undercutOrders));
    }

    private function reader(CachedCharacterOrderRepository $repository): ProjectSellOrdersReader
    {
        $names = $this->createStub(SellOrderMarketNames::class);
        $names->method('of')->willReturnCallback(
            static fn (int $locationId): ?string => $locationId === EveConstants::JITA_STATION_ID ? 'Jita IV - Moon 4' : null,
        );

        return new ProjectSellOrdersReader($repository, new ProjectProductTypes(), $names);
    }

    /**
     * @param list<CachedCharacterOrder> $rows
     */
    private function repositoryReturning(array $rows): CachedCharacterOrderRepository
    {
        $repository = $this->createStub(CachedCharacterOrderRepository::class);
        $repository->method('findByCharactersAndTypes')->willReturn($rows);

        return $repository;
    }

    private function row(int $orderId, Character $character, int $typeId, OrderPosition $position, ?string $undercutSince, int $locationId = EveConstants::JITA_STATION_ID): CachedCharacterOrder
    {
        return (new CachedCharacterOrder())
            ->setCharacter($character)
            ->setOrderId($orderId)
            ->setTypeId($typeId)
            ->setLocationId($locationId)
            ->setPrice(1000.0)
            ->setVolumeRemain(4)
            ->setVolumeTotal(10)
            ->setIssuedAt(new \DateTimeImmutable('2026-11-10 08:00:00'))
            ->setSyncedAt(new \DateTimeImmutable('2026-11-10 12:15:00'))
            ->setPosition($position)
            ->setUndercutSince($undercutSince === null ? null : new \DateTimeImmutable($undercutSince));
    }

    private function finalJob(int $typeId, string $name): void
    {
        $this->project->addBomItem(
            (new IndustryProjectBomLine())->setTypeId($typeId)->setTypeName($name)->setIsJob(true)->setJobGroup('final')->setRequiredQuantity(5),
        );
    }

    /**
     * @param list<string> $scopes
     */
    private function pilot(int $eveCharacterId, string $name, array $scopes): Character
    {
        $character = (new Character())->setEveCharacterId($eveCharacterId)->setName($name);
        $character->setEveToken((new EveToken())->setScopes($scopes));

        return $character;
    }
}
