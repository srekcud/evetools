<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\ContractSales;

use App\Entity\PublicContractListing;
use App\Repository\PublicContractListingRepository;
use App\Service\Industry\ContractSales\ObservedContract;
use App\Service\Industry\ContractSales\PublicContractHistoryRecorder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublicContractHistoryRecorder::class)]
#[Group('issue-334')]
final class PublicContractHistoryRecorderTest extends TestCase
{
    private const int REGION = 10000002;
    private const int HULL = 19720;
    private const int ISSUER = 5001;

    /** @var list<PublicContractListing> */
    private array $unconcluded = [];

    /** @var list<PublicContractListing> */
    private array $concluded = [];

    /** @var list<object> */
    private array $persisted = [];

    private int $flushes = 0;
    private ?\DateTimeImmutable $purgeCutoff = null;
    private PublicContractHistoryRecorder $recorder;

    protected function setUp(): void
    {
        $this->unconcluded = [];
        $this->concluded = [];
        $this->persisted = [];
        $this->flushes = 0;
        $this->purgeCutoff = null;

        $repository = $this->createStub(PublicContractListingRepository::class);
        $repository->method('findUnconcludedInRegion')->willReturnCallback(fn (): array => $this->unconcluded);
        $repository->method('findByContractIds')->willReturnCallback(
            fn (array $contractIds): array => array_values(array_filter(
                $this->concluded,
                static fn (PublicContractListing $listing): bool => in_array($listing->getContractId(), $contractIds, true),
            )),
        );
        $repository->method('purgeLastSeenBefore')->willReturnCallback(function (\DateTimeImmutable $cutoff): int {
            $this->purgeCutoff = $cutoff;

            return 7;
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        $this->recorder = new PublicContractHistoryRecorder($repository, $entityManager);
    }

    public function testNewReadableContractIsRecordedAsSeenForTheFirstTime(): void
    {
        $now = $this->at('10:00');

        $this->recorder->record(self::REGION, [$this->observed(80001, price: 5_000_000.0, quantity: 3)], [80001], $now);

        $this->assertCount(1, $this->persisted);
        $listing = $this->persisted[0];
        $this->assertInstanceOf(PublicContractListing::class, $listing);
        $this->assertSame(80001, $listing->getContractId());
        $this->assertSame(self::REGION, $listing->getRegionId());
        $this->assertSame(self::ISSUER, $listing->getIssuerId());
        $this->assertEqualsWithDelta(5_000_000.0, $listing->getPrice(), 0.001);
        $this->assertSame(self::HULL, $listing->getTypeId());
        $this->assertSame(3, $listing->getQuantity());
        $this->assertEquals($now, $listing->getFirstSeenAt());
        $this->assertSame(1, $this->flushes);
    }

    public function testContractWithoutAPositivePriceIsNotRecorded(): void
    {
        $this->recorder->record(self::REGION, [$this->observed(80001, price: 0.0)], [80001], $this->at('10:00'));

        $this->assertSame([], $this->persisted);
    }

    public function testContractWithoutAnyItemIsNotRecorded(): void
    {
        $this->recorder->record(self::REGION, [new ObservedContract(80001, self::ISSUER, 100.0, [], $this->at('18:00'))], [80001], $this->at('10:00'));

        $this->assertSame([], $this->persisted);
    }

    public function testTrackedContractStillListedIsSeenAgainAndNotPersistedTwice(): void
    {
        $listing = $this->tracked(80001, '09:30');
        $this->unconcluded = [$listing];

        $this->recorder->record(self::REGION, [$this->observed(80001)], [80001], $this->at('10:00'));

        $this->assertSame([], $this->persisted);
        $this->assertEquals($this->at('10:00'), $listing->getLastSeenAt());
        $this->assertSame(0, $listing->getMissedPolls());
    }

    public function testTrackedContractAbsentFromThePollIsCountedMissedOncePerPoll(): void
    {
        $listing = $this->tracked(80001, '09:30');
        $this->unconcluded = [$listing];

        $this->recorder->record(self::REGION, [], [], $this->at('10:00'));

        $this->assertSame(1, $listing->getMissedPolls());
        $this->assertEquals($this->at('10:00'), $listing->getDisappearedAt());
        $this->assertFalse($listing->isDisappearanceConfirmed());

        $this->recorder->record(self::REGION, [], [], $this->at('10:30'));

        $this->assertTrue($listing->isDisappearanceConfirmed());
        $this->assertEquals($this->at('10:00'), $listing->getDisappearedAt());
    }

    public function testTrackedContractThatReturnsAfterOneMissedPollIsStillListed(): void
    {
        $listing = $this->tracked(80001, '09:30');
        $this->unconcluded = [$listing];
        $this->recorder->record(self::REGION, [], [], $this->at('10:00'));

        $this->recorder->record(self::REGION, [$this->observed(80001)], [80001], $this->at('10:30'));

        $this->assertSame(0, $listing->getMissedPolls());
        $this->assertNull($listing->getDisappearedAt());
    }

    public function testListedContractWhoseItemsCouldNotBeReadIsNeitherCreatedNorCountedMissed(): void
    {
        $listing = $this->tracked(80001, '09:30');
        $this->unconcluded = [$listing];

        $this->recorder->record(self::REGION, [], [80001, 80002], $this->at('10:00'));

        $this->assertSame([], $this->persisted);
        $this->assertSame(0, $listing->getMissedPolls());
        $this->assertEquals($this->at('10:00'), $listing->getLastSeenAt());
    }

    public function testConcludedContractListedAgainIsReopenedInsteadOfDuplicated(): void
    {
        $listing = $this->tracked(80001, '08:00');
        $listing->missed($this->at('09:00'));
        $listing->missed($this->at('09:30'));
        $this->concluded = [$listing];

        $this->recorder->record(self::REGION, [$this->observed(80001)], [80001], $this->at('10:00'));

        $this->assertSame([], $this->persisted);
        $this->assertSame(0, $listing->getMissedPolls());
        $this->assertNull($listing->getDisappearedAt());
    }

    public function testPurgeDeletesWhatWasLastSeenMoreThanNinetyDaysAgo(): void
    {
        $deleted = $this->recorder->purgeExpiredHistory($this->at('10:00'));

        $this->assertSame(7, $deleted);
        $this->assertEquals(new \DateTimeImmutable('2026-08-09 10:00:00'), $this->purgeCutoff);
    }

    private function tracked(int $contractId, string $firstSeen): PublicContractListing
    {
        return PublicContractListing::firstSeen(
            $contractId,
            self::REGION,
            self::ISSUER,
            100.0,
            [['typeId' => self::HULL, 'quantity' => 1]],
            $this->at('18:00'),
            $this->at($firstSeen),
        );
    }

    private function observed(int $contractId, float $price = 100.0, int $quantity = 1): ObservedContract
    {
        return new ObservedContract($contractId, self::ISSUER, $price, [['typeId' => self::HULL, 'quantity' => $quantity]], $this->at('18:00'));
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-07 ' . $time . ':00');
    }
}
