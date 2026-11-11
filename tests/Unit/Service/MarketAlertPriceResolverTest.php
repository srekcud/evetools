<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\MarketPriceAlert;
use App\Entity\User;
use App\Enum\AlertDirection;
use App\Enum\AlertPriceSource;
use App\Service\JitaMarketService;
use App\Service\MarketAlertPriceResolver;
use App\Service\StructureMarketService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarketAlertPriceResolver::class)]
#[Group('issue-632')]
final class MarketAlertPriceResolverTest extends TestCase
{
    private const int STRUCTURE_ID = 1000000000001;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;

    private JitaMarketService&MockObject $jitaMarketService;
    private StructureMarketService&MockObject $structureMarketService;
    private MarketAlertPriceResolver $resolver;

    protected function setUp(): void
    {
        $this->jitaMarketService = $this->createMock(JitaMarketService::class);
        $this->structureMarketService = $this->createMock(StructureMarketService::class);
        $this->resolver = new MarketAlertPriceResolver($this->jitaMarketService, $this->structureMarketService);
    }

    public function testResolvesEachPriceSourceInTheOrderOfTheAlerts(): void
    {
        $user = (new User())->setPreferredMarketStructureId(self::STRUCTURE_ID);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([self::PYERITE => 12.0]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([self::PYERITE => 9.0]);
        $this->structureMarketService->method('getLowestSellPrices')->with(self::STRUCTURE_ID)->willReturn([self::PYERITE => 11.0]);
        $this->structureMarketService->method('getHighestBuyPrices')->with(self::STRUCTURE_ID)->willReturn([self::PYERITE => 8.0]);

        $prices = $this->resolver->currentPrices($user, [
            $this->alert(self::PYERITE, AlertPriceSource::StructureBuy),
            $this->alert(self::PYERITE, AlertPriceSource::JitaSell),
            $this->alert(self::PYERITE, AlertPriceSource::StructureSell),
            $this->alert(self::PYERITE, AlertPriceSource::JitaBuy),
        ]);

        $this->assertSame([8.0, 12.0, 11.0, 9.0], $prices);
    }

    public function testLoadsTheJitaPricesOnceForTheDistinctTypeIds(): void
    {
        $user = new User();
        $this->jitaMarketService->expects($this->once())->method('getPricesWithFallback')
            ->with([self::PYERITE, self::MEXALLON])
            ->willReturn([self::PYERITE => 12.0, self::MEXALLON => 55.25]);
        $this->jitaMarketService->expects($this->once())->method('getBuyPricesWithFallback')
            ->with([self::PYERITE, self::MEXALLON])
            ->willReturn([self::PYERITE => 9.0, self::MEXALLON => 50.0]);

        $prices = $this->resolver->currentPrices($user, [
            $this->alert(self::PYERITE, AlertPriceSource::JitaSell),
            $this->alert(self::MEXALLON, AlertPriceSource::JitaBuy),
            $this->alert(self::PYERITE, AlertPriceSource::JitaBuy),
        ]);

        $this->assertSame([12.0, 50.0, 9.0], $prices);
    }

    public function testNeverCallsTheStructureMarketWhenNoAlertUsesAStructureSource(): void
    {
        $user = (new User())->setPreferredMarketStructureId(self::STRUCTURE_ID);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([self::PYERITE => 12.0]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([self::PYERITE => 9.0]);
        $this->structureMarketService->expects($this->never())->method('getLowestSellPrices');
        $this->structureMarketService->expects($this->never())->method('getHighestBuyPrices');

        $prices = $this->resolver->currentPrices($user, [$this->alert(self::PYERITE, AlertPriceSource::JitaSell)]);

        $this->assertSame([12.0], $prices);
    }

    public function testAStructureSourceWithoutPreferredStructureHasNoPriceAndNeverCallsTheStructureMarket(): void
    {
        $user = new User();
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([]);
        $this->structureMarketService->expects($this->never())->method('getLowestSellPrices');
        $this->structureMarketService->expects($this->never())->method('getHighestBuyPrices');

        $prices = $this->resolver->currentPrices($user, [$this->alert(self::PYERITE, AlertPriceSource::StructureSell)]);

        $this->assertSame([null], $prices);
    }

    public function testAMissingPriceStaysUnknown(): void
    {
        $user = new User();
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([self::MEXALLON => 55.25]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([]);

        $prices = $this->resolver->currentPrices($user, [$this->alert(self::PYERITE, AlertPriceSource::JitaSell)]);

        $this->assertSame([null], $prices);
    }

    public function testResolvesTheCurrentPriceOfASingleAlert(): void
    {
        $user = new User();
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([self::PYERITE => 12.0]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([self::PYERITE => 9.0]);

        $price = $this->resolver->currentPrice($user, $this->alert(self::PYERITE, AlertPriceSource::JitaBuy));

        $this->assertSame(9.0, $price);
    }

    public function testNoAlertsNeedNoPriceLookup(): void
    {
        $this->jitaMarketService->expects($this->never())->method('getPricesWithFallback');
        $this->jitaMarketService->expects($this->never())->method('getBuyPricesWithFallback');

        $this->assertSame([], $this->resolver->currentPrices(new User(), []));
    }

    private function alert(int $typeId, AlertPriceSource $priceSource): MarketPriceAlert
    {
        return (new MarketPriceAlert())
            ->setTypeId($typeId)
            ->setTypeName('Type ' . $typeId)
            ->setDirection(AlertDirection::Above)
            ->setThreshold(10.0)
            ->setPriceSource($priceSource);
    }
}
