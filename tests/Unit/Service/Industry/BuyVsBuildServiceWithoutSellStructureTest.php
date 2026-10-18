<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Constant\EveConstants;
use App\Service\Industry\BuyVsBuildService;
use App\Service\StructureMarketService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuyVsBuildService::class)]
#[Group('issue-100')]
final class BuyVsBuildServiceWithoutSellStructureTest extends TestCase
{
    use BuildsBuyVsBuildOnGoldenSde;

    private const int HAIL_L = 12779;
    private const int RAM_AMMUNITION_TECH = 11476;
    private const int FERNITE_CARBIDE = 16673;
    private const int FULLERIDES = 16679;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int HELIUM_FUEL_BLOCK = 4247;
    private const int FERNITE_ALLOY = 16656;
    private const int CERAMIC_POWDER = 16660;
    private const int CARBON_POLYMERS = 16659;
    private const int PLATINUM_TECHNITE = 16662;
    private const int MORPHITE = 11399;

    private const array STRUCTURE_PRICES_FOR_ANY_STRUCTURE = [
        self::HAIL_L => 470.0,
        self::RAM_AMMUNITION_TECH => 21500.0,
        self::FERNITE_CARBIDE => 65.0,
        self::FULLERIDES => 140.0,
        self::HYDROGEN_FUEL_BLOCK => 19000.0,
        self::NITROGEN_FUEL_BLOCK => 18500.0,
        self::HELIUM_FUEL_BLOCK => 18800.0,
        self::FERNITE_ALLOY => 310.0,
        self::CERAMIC_POWDER => 260.0,
        self::CARBON_POLYMERS => 330.0,
        self::PLATINUM_TECHNITE => 420.0,
    ];

    private array $structureMarketReads = [];

    public function testWithoutSellStructureTheStructureMarketIsNeverRead(): void
    {
        $this->analyzeHailLWithoutSellStructure();

        $this->assertSame([], $this->structureMarketReads);
    }

    public function testWithoutSellStructureTheProductSellsAtTheJitaPrice(): void
    {
        $analysis = $this->analyzeHailLWithoutSellStructure();

        $this->assertSame(450.0, $analysis['sellPrice']);
    }

    public function testWithoutSellStructureEveryComponentHasNoStructureBuyCost(): void
    {
        $analysis = $this->analyzeHailLWithoutSellStructure();

        $buyCostStructureByComponent = [];
        foreach ($analysis['components'] as $component) {
            $buyCostStructureByComponent[$component['typeId']] = $component['buyCostStructure'];
        }
        ksort($buyCostStructureByComponent);

        $this->assertSame([
            self::RAM_AMMUNITION_TECH => null,
            self::FERNITE_CARBIDE => null,
            self::FULLERIDES => null,
        ], $buyCostStructureByComponent);
    }

    private function analyzeHailLWithoutSellStructure(): array
    {
        $service = $this->buyVsBuildServiceOnGoldenSde(
            jitaPrices: [
                self::HAIL_L => 450.0,
                self::RAM_AMMUNITION_TECH => 20000.0,
                self::FERNITE_CARBIDE => 60.0,
                self::FULLERIDES => 130.0,
                self::HYDROGEN_FUEL_BLOCK => 17500.0,
                self::NITROGEN_FUEL_BLOCK => 17000.0,
                self::HELIUM_FUEL_BLOCK => 17200.0,
                self::FERNITE_ALLOY => 300.0,
                self::CERAMIC_POWDER => 250.0,
                self::CARBON_POLYMERS => 320.0,
                self::PLATINUM_TECHNITE => 410.0,
                self::MORPHITE => 9500.0,
                34 => 5.0,
                35 => 10.0,
            ],
            structurePrices: [],
            adjustedPrices: [34 => 4.0, self::HYDROGEN_FUEL_BLOCK => 18000.0, self::FERNITE_ALLOY => 280.0, self::FERNITE_CARBIDE => 50.0, self::MORPHITE => 9000.0],
            costIndices: [EveConstants::JITA_SOLAR_SYSTEM_ID => ['manufacturing' => 0.02, 'reaction' => 0.04]],
            structureMarket: $this->structureMarketRecordingReads(),
        );

        return $service->analyze(self::HAIL_L, 1, 10, EveConstants::JITA_SOLAR_SYSTEM_ID, null, EveConstants::DEFAULT_BROKER_FEE_RATE, EveConstants::DEFAULT_SALES_TAX_RATE, null);
    }

    private function structureMarketRecordingReads(): StructureMarketService
    {
        $reads = &$this->structureMarketReads;

        return new class($reads, self::STRUCTURE_PRICES_FOR_ANY_STRUCTURE) extends StructureMarketService {
            public function __construct(private array &$reads, private readonly array $prices)
            {
            }

            public function getLowestSellPrice(?int $structureId, int $typeId): ?float
            {
                $this->reads[] = [$structureId, [$typeId]];

                return $this->prices[$typeId] ?? null;
            }

            public function getLowestSellPrices(?int $structureId, array $typeIds): array
            {
                $this->reads[] = [$structureId, array_values($typeIds)];

                $prices = [];
                foreach ($typeIds as $typeId) {
                    $prices[$typeId] = $this->prices[$typeId] ?? null;
                }

                return $prices;
            }
        };
    }
}
