<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\PublicContractListing;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublicContractListing::class)]
#[Group('issue-334')]
final class PublicContractListingTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;

    public function testMonoTypeContractExposesItsTypeAndTotalQuantity(): void
    {
        $listing = $this->listing([['typeId' => self::TRITANIUM, 'quantity' => 100], ['typeId' => self::TRITANIUM, 'quantity' => 50]]);

        $this->assertSame(self::TRITANIUM, $listing->getTypeId());
        $this->assertSame(150, $listing->getQuantity());
    }

    public function testMultiTypeContractHasNoTypeAndNoQuantity(): void
    {
        $listing = $this->listing([['typeId' => self::TRITANIUM, 'quantity' => 100], ['typeId' => self::PYERITE, 'quantity' => 50]]);

        $this->assertNull($listing->getTypeId());
        $this->assertNull($listing->getQuantity());
    }

    public function testItemsAreAggregatedByTypeAndSortedByTypeId(): void
    {
        $listing = $this->listing([
            ['typeId' => self::PYERITE, 'quantity' => 50],
            ['typeId' => self::TRITANIUM, 'quantity' => 100],
            ['typeId' => self::PYERITE, 'quantity' => 25],
        ]);

        $this->assertSame([
            ['typeId' => self::TRITANIUM, 'quantity' => 100],
            ['typeId' => self::PYERITE, 'quantity' => 75],
        ], $listing->getItems());
    }

    public function testSignatureIgnoresTheOrderOfTheItems(): void
    {
        $first = $this->listing([['typeId' => self::TRITANIUM, 'quantity' => 100], ['typeId' => self::PYERITE, 'quantity' => 50]]);
        $second = $this->listing([['typeId' => self::PYERITE, 'quantity' => 50], ['typeId' => self::TRITANIUM, 'quantity' => 100]]);
        $other = $this->listing([['typeId' => self::TRITANIUM, 'quantity' => 101], ['typeId' => self::PYERITE, 'quantity' => 50]]);

        $this->assertSame($first->getItemsSignature(), $second->getItemsSignature());
        $this->assertNotSame($first->getItemsSignature(), $other->getItemsSignature());
    }

    public function testNewListingHasBeenSeenOnceAndMissedNever(): void
    {
        $listing = $this->listing([['typeId' => self::TRITANIUM, 'quantity' => 1]]);

        $this->assertEquals($this->at('10:00'), $listing->getFirstSeenAt());
        $this->assertEquals($this->at('10:00'), $listing->getLastSeenAt());
        $this->assertSame(0, $listing->getMissedPolls());
        $this->assertNull($listing->getDisappearedAt());
        $this->assertFalse($listing->isDisappearanceConfirmed());
    }

    public function testOneMissedPollRecordsTheDisappearanceWithoutConfirmingIt(): void
    {
        $listing = $this->listing([['typeId' => self::TRITANIUM, 'quantity' => 1]]);

        $listing->missed($this->at('11:00'));

        $this->assertSame(1, $listing->getMissedPolls());
        $this->assertEquals($this->at('11:00'), $listing->getDisappearedAt());
        $this->assertFalse($listing->isDisappearanceConfirmed());
    }

    public function testTwoConsecutiveMissedPollsConfirmTheDisappearanceAtTheFirstMiss(): void
    {
        $listing = $this->listing([['typeId' => self::TRITANIUM, 'quantity' => 1]]);

        $listing->missed($this->at('11:00'));
        $listing->missed($this->at('11:30'));

        $this->assertSame(2, $listing->getMissedPolls());
        $this->assertEquals($this->at('11:00'), $listing->getDisappearedAt());
        $this->assertTrue($listing->isDisappearanceConfirmed());
    }

    public function testSeenAgainAfterAMissedPollClearsTheDisappearance(): void
    {
        $listing = $this->listing([['typeId' => self::TRITANIUM, 'quantity' => 1]]);
        $listing->missed($this->at('11:00'));

        $listing->seenAgain($this->at('11:30'));

        $this->assertSame(0, $listing->getMissedPolls());
        $this->assertNull($listing->getDisappearedAt());
        $this->assertEquals($this->at('11:30'), $listing->getLastSeenAt());
        $this->assertEquals($this->at('10:00'), $listing->getFirstSeenAt());
    }

    /**
     * @param list<array{typeId: int, quantity: int}> $items
     */
    private function listing(array $items): PublicContractListing
    {
        return PublicContractListing::firstSeen(
            contractId: 90001,
            regionId: 10000002,
            issuerId: 5001,
            price: 1_000_000.0,
            items: $items,
            dateExpired: new \DateTimeImmutable('2026-11-14 10:00:00'),
            now: $this->at('10:00'),
        );
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-07 ' . $time . ':00');
    }
}
