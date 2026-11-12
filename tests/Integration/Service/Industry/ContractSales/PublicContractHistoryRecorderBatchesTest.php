<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Industry\ContractSales;

use App\Entity\PublicContractListing;
use App\Repository\PublicContractListingRepository;
use App\Service\Industry\ContractSales\ObservedContract;
use App\Service\Industry\ContractSales\PublicContractHistoryRecorder;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(PublicContractHistoryRecorder::class)]
#[Group('issue-764')]
final class PublicContractHistoryRecorderBatchesTest extends IntegrationTestCase
{
    private const int FORGE = 10000002;
    private const int BATCH_SIZE = 10;
    private const int HULL = 19720;
    private const int FRIGATE = 587;
    private const int ISSUER = 5001;
    private const int TRACKED_COUNT = 25;
    private const int NEW_COUNT = 35;
    private const int FIRST_NEW_CONTRACT_ID = 101;

    private PublicContractListingRepository $repository;
    private PublicContractHistoryRecorder $recorder;
    private object $flushProbe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(PublicContractListingRepository::class);
        $this->recorder = new PublicContractHistoryRecorder($this->repository, $this->em, self::BATCH_SIZE);
        $this->flushProbe = new class {
            public int $flushes = 0;
            public int $maxManagedEntities = 0;

            public function onFlush(OnFlushEventArgs $args): void
            {
                ++$this->flushes;
                $this->maxManagedEntities = max($this->maxManagedEntities, $args->getObjectManager()->getUnitOfWork()->size());
            }
        };
        $this->em->getEventManager()->addEventListener(Events::onFlush, $this->flushProbe);
    }

    public function testTrackedAndNewContractsAreRecordedWithoutEverHoldingMoreThanOneBatchOfManagedEntities(): void
    {
        $firstSeen = new \DateTimeImmutable('2026-11-12 09:00:00');
        $now = new \DateTimeImmutable('2026-11-12 10:00:00');
        $trackedIds = range(1, self::TRACKED_COUNT);
        $newIds = range(self::FIRST_NEW_CONTRACT_ID, self::FIRST_NEW_CONTRACT_ID + self::NEW_COUNT - 1);
        foreach ($trackedIds as $contractId) {
            $this->em->persist($this->listing($contractId, $firstSeen));
        }
        $this->flushAndClear();
        $this->flushProbe->flushes = 0;
        $this->flushProbe->maxManagedEntities = 0;

        $this->recorder->record(
            self::FORGE,
            array_map(fn (int $contractId): ObservedContract => $this->observed($contractId), [...$trackedIds, ...$newIds]),
            [...$trackedIds, ...$newIds],
            $now,
        );
        $this->em->clear();

        $listings = $this->repository->findAll();
        self::assertCount(self::TRACKED_COUNT + self::NEW_COUNT, $listings);
        $tracked = array_filter($listings, static fn (PublicContractListing $listing): bool => $listing->getContractId() <= self::TRACKED_COUNT);
        $created = array_filter($listings, static fn (PublicContractListing $listing): bool => $listing->getContractId() >= self::FIRST_NEW_CONTRACT_ID);
        self::assertCount(self::TRACKED_COUNT, $tracked);
        self::assertCount(self::NEW_COUNT, $created);
        foreach ($tracked as $listing) {
            self::assertEquals($now, $listing->getLastSeenAt());
            self::assertEquals($firstSeen, $listing->getFirstSeenAt());
            self::assertSame(0, $listing->getMissedPolls());
        }
        foreach ($created as $listing) {
            self::assertEquals($now, $listing->getFirstSeenAt());
            self::assertEquals($now, $listing->getLastSeenAt());
            self::assertSame(self::FRIGATE, $listing->getTypeId());
        }
        self::assertLessThanOrEqual(self::BATCH_SIZE, $this->flushProbe->maxManagedEntities);
        self::assertGreaterThanOrEqual(6, $this->flushProbe->flushes);
    }

    public function testConcludedListingPublishedAgainIsSeenAgainWithoutDuplicate(): void
    {
        $firstSeen = new \DateTimeImmutable('2026-11-12 08:00:00');
        $now = new \DateTimeImmutable('2026-11-12 10:00:00');
        $concluded = $this->listing(7, $firstSeen);
        $concluded->missed(new \DateTimeImmutable('2026-11-12 09:00:00'));
        $concluded->missed(new \DateTimeImmutable('2026-11-12 09:30:00'));
        $this->em->persist($concluded);
        $this->flushAndClear();

        $this->recorder->record(self::FORGE, [$this->observed(7)], [7], $now);
        $this->em->clear();

        $listings = $this->repository->findAll();
        self::assertCount(1, $listings);
        self::assertSame(7, $listings[0]->getContractId());
        self::assertSame(0, $listings[0]->getMissedPolls());
        self::assertNull($listings[0]->getDisappearedAt());
        self::assertEquals($now, $listings[0]->getLastSeenAt());
    }

    private function listing(int $contractId, \DateTimeImmutable $firstSeen): PublicContractListing
    {
        return PublicContractListing::firstSeen(
            $contractId,
            self::FORGE,
            self::ISSUER,
            2_000_000.0 + $contractId * 10_000.0,
            [['typeId' => self::HULL, 'quantity' => 1]],
            new \DateTimeImmutable('2026-11-19 10:00:00'),
            $firstSeen,
        );
    }

    private function observed(int $contractId): ObservedContract
    {
        return new ObservedContract(
            $contractId,
            self::ISSUER,
            1_000_000.0 + $contractId * 1_000.0,
            [['typeId' => self::FRIGATE, 'quantity' => $contractId % 7 + 1]],
            new \DateTimeImmutable('2026-11-19 10:00:00'),
        );
    }
}
