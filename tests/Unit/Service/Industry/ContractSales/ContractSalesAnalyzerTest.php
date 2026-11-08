<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\ContractSales;

use App\Entity\PublicContractListing;
use App\Service\Industry\ContractSales\ContractSaleOutcome;
use App\Service\Industry\ContractSales\ContractSalesAnalyzer;
use App\Service\Industry\ContractSales\ContractSalesConfidence;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContractSalesAnalyzer::class)]
#[Group('issue-334')]
final class ContractSalesAnalyzerTest extends TestCase
{
    private const int REGION = 10000002;
    private const int HULL = 19720;
    private const int OTHER_TYPE = 23919;
    private const int ISSUER = 5001;
    private const int OTHER_ISSUER = 5002;
    private const int UNIQUE_ISSUER_BASE = 6000;

    private int $nextContractId = 90000;
    private ContractSalesAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new ContractSalesAnalyzer();
    }

    public function testContractStillListedIsListed(): void
    {
        $listing = $this->listing('2026-11-07 10:00', '2026-11-14 10:00');
        $listing->seenAgain($this->at('2026-11-07 11:00'));

        $this->assertSame(ContractSaleOutcome::Listed, $this->analyzer->outcomeOf($listing, [$listing]));
    }

    public function testContractAbsentFromOnePollOnlyIsPendingNotConcluded(): void
    {
        $listing = $this->listing('2026-11-07 10:00', '2026-11-14 10:00');
        $listing->missed($this->at('2026-11-07 11:00'));

        $this->assertSame(ContractSaleOutcome::Pending, $this->analyzer->outcomeOf($listing, [$listing]));
    }

    public function testContractGoneBeforeItsExpiryWithoutRepublicationIsProbablySold(): void
    {
        $listing = $this->vanished('2026-11-07 10:00', '2026-11-14 10:00', '2026-11-07 11:00');

        $this->assertSame(ContractSaleOutcome::ProbablySold, $this->analyzer->outcomeOf($listing, [$listing]));
    }

    public function testContractGoneAfterItsExpiryIsExpiredNeverSold(): void
    {
        $listing = $this->vanished('2026-11-07 09:00', '2026-11-07 10:45', '2026-11-07 11:00');

        $this->assertSame(ContractSaleOutcome::Expired, $this->analyzer->outcomeOf($listing, [$listing]));
    }

    public function testSameIssuerRelistingTheSameItemsAtALowerPriceIsARepublicationNotASale(): void
    {
        $original = $this->vanished('2026-11-07 10:00', '2026-11-14 10:00', '2026-11-07 11:00', price: 500_000_000.0, issuerId: self::ISSUER);
        $cheaper = $this->listing('2026-11-07 10:30', '2026-11-14 10:30', price: 450_000_000.0, issuerId: self::ISSUER);

        $this->assertSame(ContractSaleOutcome::Republished, $this->analyzer->outcomeOf($original, [$original, $cheaper]));
    }

    public function testRepublicationLongerThanADayAfterTheDisappearanceIsNotCounted(): void
    {
        $original = $this->vanished('2026-11-07 10:00', '2026-11-14 10:00', '2026-11-07 11:00', issuerId: self::ISSUER);
        $later = $this->listing('2026-11-08 12:00', '2026-11-15 12:00', issuerId: self::ISSUER);

        $this->assertSame(ContractSaleOutcome::ProbablySold, $this->analyzer->outcomeOf($original, [$original, $later]));
    }

    public function testRepublicationWithinADayAfterTheDisappearanceIsCounted(): void
    {
        $original = $this->vanished('2026-11-07 10:00', '2026-11-14 10:00', '2026-11-07 11:00', issuerId: self::ISSUER);
        $later = $this->listing('2026-11-08 10:30', '2026-11-15 10:30', issuerId: self::ISSUER);

        $this->assertSame(ContractSaleOutcome::Republished, $this->analyzer->outcomeOf($original, [$original, $later]));
    }

    public function testAnotherIssuerOrOtherItemsAreNotARepublication(): void
    {
        $original = $this->vanished('2026-11-07 10:00', '2026-11-14 10:00', '2026-11-07 11:00', issuerId: self::ISSUER);
        $otherIssuer = $this->listing('2026-11-07 10:30', '2026-11-14 10:30', issuerId: self::OTHER_ISSUER);
        $otherItems = $this->listing('2026-11-07 10:30', '2026-11-14 10:30', issuerId: self::ISSUER, typeId: self::OTHER_TYPE);
        $otherQuantity = $this->listing('2026-11-07 10:30', '2026-11-14 10:30', issuerId: self::ISSUER, quantity: 2);

        $this->assertSame(
            ContractSaleOutcome::ProbablySold,
            $this->analyzer->outcomeOf($original, [$original, $otherIssuer, $otherItems, $otherQuantity]),
        );
    }

    public function testAnIdenticalLotAlreadyListedBeforeTheDisappearanceIsNotItsRepublication(): void
    {
        $twin = $this->listing('2026-11-07 09:00', '2026-11-14 09:00', issuerId: self::ISSUER);
        $twin->seenAgain($this->at('2026-11-07 11:30'));
        $sold = $this->vanished('2026-11-07 10:00', '2026-11-14 10:00', '2026-11-07 11:00', lastSeen: '2026-11-07 10:30', issuerId: self::ISSUER);

        $this->assertSame(ContractSaleOutcome::ProbablySold, $this->analyzer->outcomeOf($sold, [$twin, $sold]));
    }

    public function testIndicatorsOfAThirtyDayWindow(): void
    {
        $now = $this->at('2026-11-07 12:00');
        $listings = [
            $this->soldContract(price: 100.0, quantity: 1, firstSeen: '2026-11-01 10:00', disappearedAt: '2026-11-02 10:00'),
            $this->soldContract(price: 200.0, quantity: 2, firstSeen: '2026-11-03 10:00', disappearedAt: '2026-11-06 10:00'),
            $this->soldContract(price: 300.0, quantity: 1, firstSeen: '2026-11-04 10:00', disappearedAt: '2026-11-06 22:00'),
            $this->vanished('2026-11-02 10:00', '2026-11-03 10:00', '2026-11-03 12:00'),
            $this->republishedContract(),
        ];
        $listings[] = $this->listing('2026-11-04 10:30', '2026-11-11 10:30', price: 120.0, issuerId: self::ISSUER);

        $indicators = $this->analyzer->indicators($listings, $now)[self::HULL];

        $this->assertSame(self::HULL, $indicators->typeId);
        $this->assertSame(3, $indicators->estimatedSales);
        $this->assertSame(4, $indicators->soldQuantity);
        $this->assertEqualsWithDelta(3 / 4, $indicators->sellThroughRate, 0.0001);
        $this->assertEqualsWithDelta(200.0, $indicators->medianSoldUnitPrice, 0.0001);
        $this->assertEqualsWithDelta(2.5, $indicators->medianDaysToSell, 0.0001);
    }

    public function testSalesOlderThanThirtyDaysAreLeftOutOfTheWindow(): void
    {
        $now = $this->at('2026-11-07 12:00');
        $listings = [
            $this->soldContract(price: 100.0, quantity: 1, firstSeen: '2026-09-01 10:00', disappearedAt: '2026-10-01 10:00'),
            $this->soldContract(price: 300.0, quantity: 1, firstSeen: '2026-11-01 10:00', disappearedAt: '2026-11-02 10:00'),
        ];

        $indicators = $this->analyzer->indicators($listings, $now)[self::HULL];

        $this->assertSame(1, $indicators->estimatedSales);
        $this->assertEqualsWithDelta(300.0, $indicators->medianSoldUnitPrice, 0.0001);
    }

    public function testTypeWithOnlyListedContractsHasNoIndicators(): void
    {
        $listings = [$this->listing('2026-11-06 10:00', '2026-11-13 10:00')];

        $this->assertSame([], $this->analyzer->indicators($listings, $this->at('2026-11-07 12:00')));
    }

    public function testTypeWithOnlyExpiredLotsHasAZeroSellThroughAndNoPrice(): void
    {
        $listings = [$this->vanished('2026-11-02 10:00', '2026-11-03 10:00', '2026-11-03 12:00')];

        $indicators = $this->analyzer->indicators($listings, $this->at('2026-11-07 12:00'))[self::HULL];

        $this->assertSame(0, $indicators->estimatedSales);
        $this->assertEqualsWithDelta(0.0, $indicators->sellThroughRate, 0.0001);
        $this->assertNull($indicators->medianSoldUnitPrice);
        $this->assertNull($indicators->medianDaysToSell);
    }

    public function testMultiTypeContractsFeedNoPerTypeIndicator(): void
    {
        $multi = PublicContractListing::firstSeen(
            $this->nextContractId++,
            self::REGION,
            self::ISSUER,
            900.0,
            [['typeId' => self::HULL, 'quantity' => 1], ['typeId' => self::OTHER_TYPE, 'quantity' => 1]],
            $this->at('2026-11-14 10:00'),
            $this->at('2026-11-06 10:00'),
        );
        $multi->missed($this->at('2026-11-06 12:00'));
        $multi->missed($this->at('2026-11-06 12:30'));

        $this->assertSame([], $this->analyzer->indicators([$multi], $this->at('2026-11-07 12:00')));
    }

    public function testFewSalesOrAShortHistoryGiveALowConfidence(): void
    {
        $fiveSalesButYoungHistory = [];
        for ($i = 0; $i < 5; ++$i) {
            $fiveSalesButYoungHistory[] = $this->soldContract(price: 100.0, quantity: 1, firstSeen: '2026-11-05 10:00', disappearedAt: '2026-11-06 10:00');
        }
        $fourSalesOldHistory = [$this->listing('2026-10-20 10:00', '2026-11-20 10:00')];
        for ($i = 0; $i < 4; ++$i) {
            $fourSalesOldHistory[] = $this->soldContract(price: 100.0, quantity: 1, firstSeen: '2026-11-05 10:00', disappearedAt: '2026-11-06 10:00');
        }

        $now = $this->at('2026-11-07 12:00');

        $this->assertSame(ContractSalesConfidence::Low, $this->analyzer->indicators($fiveSalesButYoungHistory, $now)[self::HULL]->confidence);
        $this->assertSame(ContractSalesConfidence::Low, $this->analyzer->indicators($fourSalesOldHistory, $now)[self::HULL]->confidence);
    }

    public function testFiveSalesOverAWeekOfHistoryGiveAMediumConfidenceAndNeverHigher(): void
    {
        $listings = [$this->listing('2026-10-20 10:00', '2026-11-20 10:00')];
        for ($i = 0; $i < 5; ++$i) {
            $listings[] = $this->soldContract(price: 100.0, quantity: 1, firstSeen: '2026-11-05 10:00', disappearedAt: '2026-11-06 10:00');
        }

        $this->assertSame(
            ContractSalesConfidence::Medium,
            $this->analyzer->indicators($listings, $this->at('2026-11-07 12:00'))[self::HULL]->confidence,
        );
    }

    private function listing(
        string $firstSeen,
        string $expires,
        float $price = 100.0,
        ?int $issuerId = null,
        int $typeId = self::HULL,
        int $quantity = 1,
    ): PublicContractListing {
        return PublicContractListing::firstSeen(
            $this->nextContractId++,
            self::REGION,
            $issuerId ?? self::UNIQUE_ISSUER_BASE + $this->nextContractId,
            $price,
            [['typeId' => $typeId, 'quantity' => $quantity]],
            $this->at($expires),
            $this->at($firstSeen),
        );
    }

    private function vanished(string $firstSeen, string $expires, string $disappearedAt, float $price = 100.0, ?string $lastSeen = null, int $quantity = 1, ?int $issuerId = null): PublicContractListing
    {
        $listing = $this->listing($firstSeen, $expires, $price, $issuerId, quantity: $quantity);
        $lastSeenAt = null !== $lastSeen ? $this->at($lastSeen) : $this->at($disappearedAt)->modify('-30 minutes');
        if ($lastSeenAt > $this->at($firstSeen)) {
            $listing->seenAgain($lastSeenAt);
        }
        $listing->missed($this->at($disappearedAt));
        $listing->missed($this->at($disappearedAt)->modify('+30 minutes'));

        return $listing;
    }

    private function soldContract(float $price, int $quantity, string $firstSeen, string $disappearedAt): PublicContractListing
    {
        return $this->vanished($firstSeen, '2026-12-31 10:00', $disappearedAt, $price * $quantity, quantity: $quantity);
    }

    private function republishedContract(): PublicContractListing
    {
        return $this->vanished('2026-11-04 10:00', '2026-11-11 10:00', '2026-11-04 11:00', price: 150.0, issuerId: self::ISSUER);
    }

    private function at(string $dateTime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($dateTime . ':00');
    }
}
