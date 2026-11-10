<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\ContractSales;

use App\Entity\PublicContractListing;
use App\Repository\PublicContractListingRepository;
use App\Service\Industry\ContractSales\ContractSalesAnalyzer;
use App\Service\Industry\ContractSales\ContractSalesReader;
use App\Service\Industry\ContractSales\FittedShipHullPricer;
use App\Service\Industry\ContractSales\HullAttribution;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContractSalesReader::class)]
#[Group('issue-334')]
final class ContractSalesReaderTest extends TestCase
{
    private const int REGION = 10000030;
    private const int HULL = 19720;
    private const int MODULE = 3841;

    public function testReadsTheRegionHistoryOfTheThirtyDayWindowPlusTheRepublicationMarginAndAnalyzesIt(): void
    {
        $now = new \DateTimeImmutable('2026-11-07 12:00:00');
        $sold = PublicContractListing::firstSeen(1, self::REGION, 5001, 300.0, [['typeId' => self::HULL, 'quantity' => 1]], new \DateTimeImmutable('2026-12-01 00:00:00'), new \DateTimeImmutable('2026-11-01 10:00:00'));
        $sold->missed(new \DateTimeImmutable('2026-11-02 10:00:00'));
        $sold->missed(new \DateTimeImmutable('2026-11-02 10:30:00'));

        $requestedSince = null;
        $repository = $this->createStub(PublicContractListingRepository::class);
        $repository->method('findInRegionLastSeenSince')->willReturnCallback(
            function (int $regionId, \DateTimeImmutable $since) use ($sold, &$requestedSince): array {
                $requestedSince = [$regionId, $since];

                return [$sold];
            },
        );

        $pricer = $this->createStub(FittedShipHullPricer::class);
        $pricer->method('attributionsFor')->willReturn([]);

        $indicators = (new ContractSalesReader($repository, new ContractSalesAnalyzer(), $pricer))->indicatorsFor(self::REGION, $now);

        $this->assertEquals([self::REGION, new \DateTimeImmutable('2026-10-07 12:00:00')], $requestedSince);
        $this->assertSame(1, $indicators[self::HULL]->estimatedSales);
        $this->assertEqualsWithDelta(300.0, $indicators[self::HULL]->medianSoldUnitPrice, 0.0001);
    }

    public function testPricesTheHullOfOnlyTheConfirmedMultiTypeContractsOfTheWindowAndFeedsThemToTheAnalysis(): void
    {
        $now = new \DateTimeImmutable('2026-11-07 12:00:00');
        $fitted = $this->vanished(2, [['typeId' => self::HULL, 'quantity' => 1], ['typeId' => self::MODULE, 'quantity' => 2]], 900.0);
        $mono = $this->vanished(3, [['typeId' => self::HULL, 'quantity' => 1]], 300.0);
        $stillListed = PublicContractListing::firstSeen(4, self::REGION, 5001, 800.0, [['typeId' => self::HULL, 'quantity' => 1], ['typeId' => self::MODULE, 'quantity' => 1]], new \DateTimeImmutable('2026-12-01 00:00:00'), new \DateTimeImmutable('2026-11-06 10:00:00'));

        $repository = $this->createStub(PublicContractListingRepository::class);
        $repository->method('findInRegionLastSeenSince')->willReturn([$fitted, $mono, $stillListed]);

        $pricedListings = null;
        $pricer = $this->createStub(FittedShipHullPricer::class);
        $pricer->method('attributionsFor')->willReturnCallback(
            function (array $listings) use (&$pricedListings, $fitted): array {
                $pricedListings = $listings;

                return [$fitted->getContractId() => new HullAttribution(self::HULL, 600.0)];
            },
        );

        $indicators = (new ContractSalesReader($repository, new ContractSalesAnalyzer(), $pricer))->indicatorsFor(self::REGION, $now);

        $this->assertSame([$fitted], $pricedListings);
        $this->assertSame(2, $indicators[self::HULL]->estimatedSales);
        $this->assertEqualsWithDelta(450.0, $indicators[self::HULL]->medianSoldUnitPrice, 0.0001);
    }

    /**
     * @param list<array{typeId: int, quantity: int}> $items
     */
    private function vanished(int $contractId, array $items, float $price): PublicContractListing
    {
        $listing = PublicContractListing::firstSeen($contractId, self::REGION, 5000 + $contractId, $price, $items, new \DateTimeImmutable('2026-12-01 00:00:00'), new \DateTimeImmutable('2026-11-06 10:00:00'));
        $listing->missed(new \DateTimeImmutable('2026-11-06 12:00:00'));
        $listing->missed(new \DateTimeImmutable('2026-11-06 12:30:00'));

        return $listing;
    }
}
