<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\ContractSales;

use App\Entity\PublicContractListing;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\ContractSales\FittedShipHullPricer;
use App\Service\Industry\ContractSales\HullAttribution;
use App\Service\JitaMarketService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(FittedShipHullPricer::class)]
#[CoversClass(HullAttribution::class)]
#[Group('issue-334')]
final class FittedShipHullPricerTest extends TestCase
{
    private const int SHIP_CATEGORY = 6;
    private const int MODULE_CATEGORY = 7;
    private const int HULL = 19720;
    private const int OTHER_HULL = 19724;
    private const int SHIELD_EXTENDER = 3841;
    private const int ARMOR_PLATE = 2048;

    /** @var array<int, int> */
    private array $categoryByType = [
        self::HULL => self::SHIP_CATEGORY,
        self::OTHER_HULL => self::SHIP_CATEGORY,
        self::SHIELD_EXTENDER => self::MODULE_CATEGORY,
        self::ARMOR_PLATE => self::MODULE_CATEGORY,
    ];

    /** @var array<int, ?float> */
    private array $jitaPrices = [
        self::SHIELD_EXTENDER => 100.0,
        self::ARMOR_PLATE => 200.0,
    ];

    public function testAFittedShipIsPricedAsItsHullOnceTheEquipmentIsDeductedAtTheJitaPrice(): void
    {
        $listing = $this->contract(1000.0, [self::HULL => 1, self::SHIELD_EXTENDER => 1, self::ARMOR_PLATE => 1]);

        $attributions = $this->pricer()->attributionsFor([$listing]);

        $this->assertEquals([$listing->getContractId() => new HullAttribution(self::HULL, 700.0)], $attributions);
    }

    public function testEquipmentQuantitiesAreMultipliedByTheirJitaPrice(): void
    {
        $listing = $this->contract(1000.0, [self::HULL => 1, self::SHIELD_EXTENDER => 3]);

        $attributions = $this->pricer()->attributionsFor([$listing]);

        $this->assertSame(700.0, $attributions[$listing->getContractId()]->price);
    }

    public function testAContractWithAnEquipmentTypeWithoutJitaPriceHasNoHullPrice(): void
    {
        $this->jitaPrices[self::ARMOR_PLATE] = null;
        $listing = $this->contract(1000.0, [self::HULL => 1, self::SHIELD_EXTENDER => 1, self::ARMOR_PLATE => 1]);

        $this->assertSame([], $this->pricer()->attributionsFor([$listing]));
    }

    public function testAContractPricedAtOrBelowItsEquipmentHasNoHullPrice(): void
    {
        $atEquipmentValue = $this->contract(300.0, [self::HULL => 1, self::SHIELD_EXTENDER => 1, self::ARMOR_PLATE => 1]);
        $belowEquipmentValue = $this->contract(250.0, [self::HULL => 1, self::SHIELD_EXTENDER => 1, self::ARMOR_PLATE => 1]);

        $this->assertSame([], $this->pricer()->attributionsFor([$atEquipmentValue, $belowEquipmentValue]));
    }

    public function testAContractWithTwoHullsHasNoHullPrice(): void
    {
        $listing = $this->contract(1000.0, [self::HULL => 1, self::OTHER_HULL => 1, self::SHIELD_EXTENDER => 1]);

        $this->assertSame([], $this->pricer()->attributionsFor([$listing]));
    }

    public function testAContractWithSeveralCopiesOfTheHullHasNoHullPrice(): void
    {
        $listing = $this->contract(1000.0, [self::HULL => 2, self::SHIELD_EXTENDER => 1]);

        $this->assertSame([], $this->pricer()->attributionsFor([$listing]));
    }

    public function testAContractWithoutAnyHullHasNoHullPrice(): void
    {
        $listing = $this->contract(1000.0, [self::SHIELD_EXTENDER => 1, self::ARMOR_PLATE => 1]);

        $this->assertSame([], $this->pricer()->attributionsFor([$listing]));
    }

    public function testASingleTypeContractIsLeftToTheTypeIndicators(): void
    {
        $listing = $this->contract(1000.0, [self::HULL => 1]);

        $this->assertSame([], $this->pricer()->attributionsFor([$listing]));
    }

    public function testNothingIsLoadedWithoutAnyContract(): void
    {
        $this->assertSame([], $this->pricer()->attributionsFor([]));
    }

    /**
     * @param array<int, int> $quantityByType
     */
    private function contract(float $price, array $quantityByType): PublicContractListing
    {
        static $nextContractId = 70000;

        return PublicContractListing::firstSeen(
            ++$nextContractId,
            10000002,
            5001,
            $price,
            array_map(
                static fn (int $typeId, int $quantity): array => ['typeId' => $typeId, 'quantity' => $quantity],
                array_keys($quantityByType),
                $quantityByType,
            ),
            new \DateTimeImmutable('2026-12-01 00:00:00'),
            new \DateTimeImmutable('2026-11-01 10:00:00'),
        );
    }

    private function pricer(): FittedShipHullPricer
    {
        $types = $this->createStub(InvTypeRepository::class);
        $types->method('findByTypeIds')->willReturnCallback(function (array $typeIds): array {
            $found = [];
            foreach ($typeIds as $typeId) {
                if (isset($this->categoryByType[$typeId])) {
                    $found[$typeId] = (new InvType())->setTypeId($typeId)->setGroup(
                        (new InvGroup())->setCategory((new InvCategory())->setCategoryId($this->categoryByType[$typeId])),
                    );
                }
            }

            return $found;
        });

        $jita = $this->createStub(JitaMarketService::class);
        $jita->method('getPrices')->willReturnCallback(function (array $typeIds): array {
            $prices = [];
            foreach ($typeIds as $typeId) {
                $prices[$typeId] = $this->jitaPrices[$typeId] ?? null;
            }

            return $prices;
        });

        return new FittedShipHullPricer($types, $jita);
    }
}
