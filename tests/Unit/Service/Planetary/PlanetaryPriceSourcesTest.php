<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary;

use App\Service\Planetary\PlanetaryPriceSources;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetaryPriceSources::class)]
#[Group('issue-1454')]
final class PlanetaryPriceSourcesTest extends TestCase
{
    private const float PAID = 80.0;
    private const float HUB = 90.0;
    private const float JITA_SELL = 100.0;
    private const float JITA_BUY = 70.0;

    /**
     * @return iterable<string, array{string, float|null}>
     */
    public static function purchaseSources(): iterable
    {
        yield 'Jita sell' => ['jita-sell', self::JITA_SELL];
        yield 'Jita buy' => ['jita-buy', self::JITA_BUY];
        yield 'the hub' => ['hub', self::HUB];
        yield 'the paid price' => ['paid', self::PAID];
        yield 'the best of paid, hub and Jita sell' => ['best', self::PAID];
    }

    #[DataProvider('purchaseSources')]
    public function testThePurchasePriceIsTheOneOfTheChosenSource(string $source, float $expected): void
    {
        $this->assertSame($expected, PlanetaryPriceSources::purchase($source, self::PAID, self::HUB, self::JITA_SELL, self::JITA_BUY));
    }

    public function testTheBestPurchasePriceIgnoresJitaBuyAndTheUnknownPrices(): void
    {
        $this->assertSame(90.0, PlanetaryPriceSources::purchase('best', null, 90.0, 100.0, 10.0));
        $this->assertSame(100.0, PlanetaryPriceSources::purchase('best', null, null, 100.0, 10.0));
        $this->assertSame(60.0, PlanetaryPriceSources::purchase('best', 60.0, 90.0, 100.0, null));
    }

    public function testTheBestPurchasePriceIsUnknownWhenNoSourceIsKnown(): void
    {
        $this->assertNull(PlanetaryPriceSources::purchase('best', null, null, null, 70.0));
    }

    public function testAnUnknownPriceOfTheChosenPurchaseSourceStaysUnknownWithoutFallingBackToAnother(): void
    {
        $this->assertNull(PlanetaryPriceSources::purchase('paid', null, self::HUB, self::JITA_SELL, self::JITA_BUY));
        $this->assertNull(PlanetaryPriceSources::purchase('hub', self::PAID, null, self::JITA_SELL, self::JITA_BUY));
    }

    /**
     * @return iterable<string, array{string, float}>
     */
    public static function saleSources(): iterable
    {
        yield 'Jita sell' => ['jita-sell', self::JITA_SELL];
        yield 'Jita buy' => ['jita-buy', self::JITA_BUY];
        yield 'the hub' => ['hub', self::HUB];
    }

    #[DataProvider('saleSources')]
    public function testTheSalePriceIsTheOneOfTheChosenSource(string $source, float $expected): void
    {
        $this->assertSame($expected, PlanetaryPriceSources::sale($source, self::HUB, self::JITA_SELL, self::JITA_BUY));
    }

    public function testAnUnknownPriceOfTheChosenSaleSourceStaysUnknown(): void
    {
        $this->assertNull(PlanetaryPriceSources::sale('hub', null, self::JITA_SELL, self::JITA_BUY));
    }

    public function testASourceOutsideTheListIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PlanetaryPriceSources::sale('best', self::HUB, self::JITA_SELL, self::JITA_BUY);
    }
}
