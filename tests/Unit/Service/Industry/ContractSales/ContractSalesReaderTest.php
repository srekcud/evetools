<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\ContractSales;

use App\Entity\PublicContractListing;
use App\Repository\PublicContractListingRepository;
use App\Service\Industry\ContractSales\ContractSalesAnalyzer;
use App\Service\Industry\ContractSales\ContractSalesReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContractSalesReader::class)]
#[Group('issue-334')]
final class ContractSalesReaderTest extends TestCase
{
    private const int REGION = 10000030;
    private const int HULL = 19720;

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

        $indicators = (new ContractSalesReader($repository, new ContractSalesAnalyzer()))->indicatorsFor(self::REGION, $now);

        $this->assertEquals([self::REGION, new \DateTimeImmutable('2026-10-07 12:00:00')], $requestedSince);
        $this->assertSame(1, $indicators[self::HULL]->estimatedSales);
        $this->assertEqualsWithDelta(300.0, $indicators[self::HULL]->medianSoldUnitPrice, 0.0001);
    }
}
