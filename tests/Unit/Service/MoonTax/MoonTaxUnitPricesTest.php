<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MoonTax;

use App\Enum\MarketSide;
use App\Service\MoonTax\MoonTaxUnitPrices;
use App\Service\OreValueService;
use App\Tests\Unit\Service\Mining\BuildsMiningProfileUnitPrices;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MoonTaxUnitPrices::class)]
#[Group('issue-532')]
final class MoonTaxUnitPricesTest extends TestCase
{
    use BuildsOreValueServiceOnSdeOres;
    use BuildsMiningProfileUnitPrices;

    private const float PRICE_DELTA = 1e-9;
    private const int TYPE_WITHOUT_ANY_PRICE = 81143;

    public function testTheTaxBaseIsAlwaysJitaBuyCompressedWithoutExportTaxWhateverTheSellPricesAre(): void
    {
        $realOreValueService = new OreValueService(
            $this->sdeInvTypeRepository($this->sdeTypesWithOreAOfTheSpec(), []),
            $this->sdeInvTypeMaterialRepository($this->sdeReprocessMaterials()),
            $this->jitaMarketReadAt($this->jitaBuyPricesOfOreAOfTheSpec(), $this->jitaSellPricesOfOreAOfTheSpec()),
            null,
        );
        $oreValueService = $this->createMock(OreValueService::class);
        $oreValueService->expects($this->once())
            ->method('getBatchOrePrices')
            ->with([self::VELDSPAR, self::TYPE_WITHOUT_ANY_PRICE], null, 1.0, 0.0, MarketSide::Buy)
            ->willReturnCallback(static fn (array $typeIds, ?int $structureId, float $reprocessYield, float $exportTax, MarketSide $side): array => $realOreValueService->getBatchOrePrices($typeIds, $structureId, $reprocessYield, $exportTax, $side));

        $unitPrices = (new MoonTaxUnitPrices($oreValueService))->read([self::VELDSPAR, self::TYPE_WITHOUT_ANY_PRICE]);

        $this->assertSame([self::VELDSPAR, self::TYPE_WITHOUT_ANY_PRICE], array_keys($unitPrices));
        $this->assertEqualsWithDelta(500.0, $unitPrices[self::VELDSPAR], self::PRICE_DELTA);
        $this->assertNull($unitPrices[self::TYPE_WITHOUT_ANY_PRICE]);
    }
}
