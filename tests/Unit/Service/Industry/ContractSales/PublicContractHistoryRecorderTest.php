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
#[Group('issue-764')]
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

    /** @var list<string> */
    private array $events = [];

    private int $flushes = 0;
    private int $clears = 0;
    private ?\DateTimeImmutable $purgeCutoff = null;
    private PublicContractListingRepository $repository;
    private EntityManagerInterface $entityManager;
    private PublicContractHistoryRecorder $recorder;

    protected function setUp(): void
    {
        $this->unconcluded = [];
        $this->concluded = [];
        $this->persisted = [];
        $this->events = [];
        $this->flushes = 0;
        $this->clears = 0;
        $this->purgeCutoff = null;

        $repository = $this->createStub(PublicContractListingRepository::class);
        $repository->method('findUnconcludedContractIdsInRegion')->willReturnCallback(
            fn (): array => array_map(
                static fn (PublicContractListing $listing): int => $listing->getContractId(),
                $this->unconcluded,
            ),
        );
        $repository->method('findByContractIds')->willReturnCallback(
            fn (array $contractIds): array => array_values(array_filter(
                [...$this->unconcluded, ...$this->concluded],
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
            $this->events[] = 'persist';
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
            $this->events[] = 'flush';
        });
        $entityManager->method('clear')->willReturnCallback(function (): void {
            ++$this->clears;
            $this->events[] = 'clear';
        });

        $this->repository = $repository;
        $this->entityManager = $entityManager;
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

    public function testTrackedContractsAreProcessedInBatchesWithAFlushAndAClearAfterEachBatch(): void
    {
        $this->unconcluded = [
            $this->tracked(80001, '09:30'),
            $this->tracked(80002, '09:30'),
            $this->tracked(80003, '09:30'),
            $this->tracked(80004, '09:30'),
            $this->tracked(80005, '09:30'),
        ];
        $readable = array_map(fn (PublicContractListing $listing): ObservedContract => $this->observed($listing->getContractId()), $this->unconcluded);

        $this->recorderWithBatchSize(2)->record(self::REGION, $readable, [80001, 80002, 80003, 80004, 80005], $this->at('10:00'));

        $this->assertSame(['flush', 'clear', 'flush', 'clear', 'flush', 'clear'], $this->events);
        $this->assertSame([], $this->persisted);
    }

    public function testTrackedContractsOfEveryBatchAreSeenAgainOrMissedAccordingToThePoll(): void
    {
        $listings = [];
        foreach ([80001, 80002, 80003, 80004, 80005] as $contractId) {
            $listings[$contractId] = $this->tracked($contractId, '09:30');
        }
        $this->unconcluded = array_values($listings);

        $this->recorderWithBatchSize(2)->record(
            self::REGION,
            [$this->observed(80001), $this->observed(80003), $this->observed(80005)],
            [80001, 80003, 80005],
            $this->at('10:00'),
        );

        $this->assertSame([0, 1, 0, 1, 0], array_map(static fn (PublicContractListing $listing): int => $listing->getMissedPolls(), array_values($listings)));
        $this->assertEquals($this->at('10:00'), $listings[80005]->getLastSeenAt());
        $this->assertEquals($this->at('10:00'), $listings[80004]->getDisappearedAt());
        $this->assertSame([], $this->persisted);
    }

    public function testNewContractsArePersistedInBatchesOfAtMostTheBatchSizeBetweenTwoFlushes(): void
    {
        $readable = [];
        foreach ([80001, 80002, 80003, 80004, 80005] as $contractId) {
            $readable[] = $this->observed($contractId);
        }

        $this->recorderWithBatchSize(2)->record(self::REGION, $readable, [80001, 80002, 80003, 80004, 80005], $this->at('10:00'));

        $this->assertCount(5, $this->persisted);
        $this->assertSame([2, 2, 1], $this->persistsBeforeEachNonEmptyFlush());
        $this->assertSame($this->flushes, $this->clears);
    }

    public function testNewContractsInTheLastIncompleteBatchAreFlushedAndClearedToo(): void
    {
        $this->recorderWithBatchSize(3)->record(
            self::REGION,
            [$this->observed(80001), $this->observed(80002), $this->observed(80003), $this->observed(80004)],
            [80001, 80002, 80003, 80004],
            $this->at('10:00'),
        );

        $this->assertSame([3, 1], $this->persistsBeforeEachNonEmptyFlush());
        $this->assertSame('clear', $this->events[array_key_last($this->events)]);
    }

    public function testNothingIsPersistedAndEveryFlushIsFollowedByAClearWhenThePollIsEmpty(): void
    {
        $this->recorderWithBatchSize(2)->record(self::REGION, [], [], $this->at('10:00'));

        $this->assertSame([], $this->persisted);
        $this->assertSame($this->flushes, $this->clears);
    }

    public function testPurgeDeletesWhatWasLastSeenMoreThanNinetyDaysAgo(): void
    {
        $deleted = $this->recorder->purgeExpiredHistory($this->at('10:00'));

        $this->assertSame(7, $deleted);
        $this->assertEquals(new \DateTimeImmutable('2026-08-09 10:00:00'), $this->purgeCutoff);
    }

    private function recorderWithBatchSize(int $batchSize): PublicContractHistoryRecorder
    {
        return new PublicContractHistoryRecorder($this->repository, $this->entityManager, $batchSize);
    }

    /**
     * @return list<int>
     */
    private function persistsBeforeEachNonEmptyFlush(): array
    {
        $counts = [];
        $persists = 0;
        foreach ($this->events as $event) {
            if ('persist' === $event) {
                ++$persists;
            } elseif ('flush' === $event) {
                $counts[] = $persists;
                $persists = 0;
            }
        }

        return array_values(array_filter($counts, static fn (int $count): bool => $count > 0));
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
