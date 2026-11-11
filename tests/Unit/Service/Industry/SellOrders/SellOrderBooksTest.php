<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\SellOrders;

use App\Constant\EveConstants;
use App\Service\Industry\SellOrders\SellOrderBooks;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(SellOrderBooks::class)]
#[Group('issue-350')]
final class SellOrderBooksTest extends TestCase
{
    private const int HAMMERHEAD_TYPE_ID = 2185;
    private const int FOLLOWED_STRUCTURE_ID = 1_040_000_000_350;
    private const int UNFOLLOWED_STRUCTURE_ID = 1_040_000_000_351;
    private const int AMARR_STATION_ID = 60_008_494;

    public function testJitaStationReadsTheJitaSellBook(): void
    {
        $jita = $this->createMock(JitaMarketService::class);
        $jita->expects(self::once())->method('getSellOrders')->with(self::HAMMERHEAD_TYPE_ID)->willReturn([['price' => 900.0, 'volume' => 3]]);

        $book = (new SellOrderBooks($jita, $this->createStub(StructureMarketService::class)))->of(EveConstants::JITA_STATION_ID, self::HAMMERHEAD_TYPE_ID);

        self::assertSame([['price' => 900.0, 'volume' => 3]], $book);
    }

    public function testStructureWithACachedMarketReadsItsSellBook(): void
    {
        $structures = $this->createMock(StructureMarketService::class);
        $structures->method('hasCachedData')->with(self::FOLLOWED_STRUCTURE_ID)->willReturn(true);
        $structures->expects(self::once())->method('getSellOrders')->with(self::FOLLOWED_STRUCTURE_ID, self::HAMMERHEAD_TYPE_ID)->willReturn([['price' => 1_200.0, 'volume' => 1]]);

        $book = (new SellOrderBooks($this->createStub(JitaMarketService::class), $structures))->of(self::FOLLOWED_STRUCTURE_ID, self::HAMMERHEAD_TYPE_ID);

        self::assertSame([['price' => 1_200.0, 'volume' => 1]], $book);
    }

    public function testStructureWithoutACachedMarketIsNotFollowed(): void
    {
        $structures = $this->createStub(StructureMarketService::class);
        $structures->method('hasCachedData')->willReturn(false);

        $book = (new SellOrderBooks($this->createStub(JitaMarketService::class), $structures))->of(self::UNFOLLOWED_STRUCTURE_ID, self::HAMMERHEAD_TYPE_ID);

        self::assertNull($book);
    }

    public function testAnotherNpcStationIsNotFollowed(): void
    {
        $structures = $this->createStub(StructureMarketService::class);
        $structures->method('hasCachedData')->willReturn(false);

        $book = (new SellOrderBooks($this->createStub(JitaMarketService::class), $structures))->of(self::AMARR_STATION_ID, self::HAMMERHEAD_TYPE_ID);

        self::assertNull($book);
    }

    public function testJitaRefreshAsksForTheGivenTypesOnly(): void
    {
        $jita = $this->createMock(JitaMarketService::class);
        $jita->expects(self::once())->method('refreshPricesForTypes')->with([self::HAMMERHEAD_TYPE_ID, 17_300])->willReturn(['success' => true, 'typeCount' => 2, 'duration' => 0.1]);

        (new SellOrderBooks($jita, $this->createStub(StructureMarketService::class)))->refreshJita([self::HAMMERHEAD_TYPE_ID, 17_300]);
    }

    public function testJitaRefreshWithoutTypeDoesNotCallTheMarket(): void
    {
        $jita = $this->createMock(JitaMarketService::class);
        $jita->expects(self::never())->method('refreshPricesForTypes');

        (new SellOrderBooks($jita, $this->createStub(StructureMarketService::class)))->refreshJita([]);
    }

    public function testFailedJitaRefreshRaisesAnExplicitError(): void
    {
        $jita = $this->createStub(JitaMarketService::class);
        $jita->method('refreshPricesForTypes')->willReturn(['success' => false, 'typeCount' => 0, 'duration' => 0.1, 'error' => 'ESI down']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ESI down');

        (new SellOrderBooks($jita, $this->createStub(StructureMarketService::class)))->refreshJita([self::HAMMERHEAD_TYPE_ID]);
    }
}
