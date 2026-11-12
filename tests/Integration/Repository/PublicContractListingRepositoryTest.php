<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\PublicContractListing;
use App\Repository\PublicContractListingRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(PublicContractListingRepository::class)]
#[Group('issue-334')]
final class PublicContractListingRepositoryTest extends IntegrationTestCase
{
    private const int FORGE = 10000002;
    private const int DOMAIN = 10000043;
    private const int HULL = 19720;

    private PublicContractListingRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(PublicContractListingRepository::class);
    }

    public function testUnconcludedListingsOfTheRegionExcludeConfirmedDisappearancesAndOtherRegions(): void
    {
        $listed = $this->listing(1, self::FORGE, '-2 days');
        $pending = $this->listing(2, self::FORGE, '-2 days');
        $pending->missed(new \DateTimeImmutable('-1 day'));
        $concluded = $this->listing(3, self::FORGE, '-2 days');
        $concluded->missed(new \DateTimeImmutable('-1 day'));
        $concluded->missed(new \DateTimeImmutable('-1 day'));
        $otherRegion = $this->listing(4, self::DOMAIN, '-2 days');
        $this->persistAll([$listed, $pending, $concluded, $otherRegion]);

        $contractIds = array_map(
            static fn (PublicContractListing $listing): int => $listing->getContractId(),
            $this->repository->findUnconcludedInRegion(self::FORGE),
        );
        sort($contractIds);

        self::assertSame([1, 2], $contractIds);
    }

    #[Group('issue-764')]
    public function testUnconcludedContractIdsOfTheRegionAreScalarIntegersWithoutConcludedOrOtherRegionListings(): void
    {
        $listed = $this->listing(3_000_000_001, self::FORGE, '-2 days');
        $pending = $this->listing(2, self::FORGE, '-2 days');
        $pending->missed(new \DateTimeImmutable('-1 day'));
        $concluded = $this->listing(7, self::FORGE, '-2 days');
        $concluded->missed(new \DateTimeImmutable('-1 day'));
        $concluded->missed(new \DateTimeImmutable('-1 day'));
        $otherRegion = $this->listing(4, self::DOMAIN, '-2 days');
        $this->persistAll([$listed, $pending, $concluded, $otherRegion]);

        $contractIds = $this->repository->findUnconcludedContractIdsInRegion(self::FORGE);
        sort($contractIds);

        self::assertSame([2, 3_000_000_001], $contractIds);
        self::assertSame([], $this->repository->findUnconcludedContractIdsInRegion(10000099));
    }

    public function testListingsAreFoundByContractIdWhateverTheirRegionOrState(): void
    {
        $concluded = $this->listing(3, self::FORGE, '-2 days');
        $concluded->missed(new \DateTimeImmutable('-1 day'));
        $concluded->missed(new \DateTimeImmutable('-1 day'));
        $this->persistAll([$this->listing(1, self::DOMAIN, '-2 days'), $concluded, $this->listing(5, self::FORGE, '-2 days')]);

        $found = $this->repository->findByContractIds([1, 3, 99]);
        $contractIds = array_map(static fn (PublicContractListing $listing): int => $listing->getContractId(), $found);
        sort($contractIds);

        self::assertSame([1, 3], $contractIds);
        self::assertSame([], $this->repository->findByContractIds([]));
    }

    public function testListingsLastSeenSinceAreReadForTheRegionOnly(): void
    {
        $recent = $this->listing(1, self::FORGE, '-2 days');
        $old = $this->listing(2, self::FORGE, '-40 days');
        $otherRegion = $this->listing(3, self::DOMAIN, '-2 days');
        $this->persistAll([$recent, $old, $otherRegion]);

        $contractIds = array_map(
            static fn (PublicContractListing $listing): int => $listing->getContractId(),
            $this->repository->findInRegionLastSeenSince(self::FORGE, new \DateTimeImmutable('-31 days')),
        );

        self::assertSame([1], $contractIds);
    }

    public function testPurgeDeletesWhatWasLastSeenBeforeTheCutoffAndKeepsTheOthers(): void
    {
        $this->persistAll([$this->listing(1, self::FORGE, '-91 days'), $this->listing(2, self::FORGE, '-89 days')]);

        $deleted = $this->repository->purgeLastSeenBefore(new \DateTimeImmutable('-90 days'));

        self::assertSame(1, $deleted);
        $remaining = $this->repository->findAll();
        self::assertCount(1, $remaining);
        self::assertSame(2, $remaining[0]->getContractId());
    }

    public function testTheContractIdIsUnique(): void
    {
        $this->em->persist($this->listing(1, self::FORGE, '-1 day'));
        $this->em->persist($this->listing(1, self::DOMAIN, '-1 day'));

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

        $this->em->flush();
    }

    private function listing(int $contractId, int $regionId, string $firstSeen): PublicContractListing
    {
        return PublicContractListing::firstSeen(
            $contractId,
            $regionId,
            5001,
            1_000_000.0,
            [['typeId' => self::HULL, 'quantity' => 1]],
            new \DateTimeImmutable('+7 days'),
            new \DateTimeImmutable($firstSeen),
        );
    }

    #[Group('issue-805')]
    public function testAContractWithTwoHundredDistinctTypesIsRecordedAndReloadedWithItsSignature(): void
    {
        $items = array_map(static fn (int $typeId): array => ['typeId' => 1_000 + $typeId, 'quantity' => 1_000_000 + $typeId], range(1, 200));
        $listing = PublicContractListing::firstSeen(900_805, self::FORGE, 5001, 1_000_000.0, $items, new \DateTimeImmutable('+7 days'), new \DateTimeImmutable('-1 day'));
        $expectedSignature = $listing->getItemsSignature();
        $this->persistAll([$listing]);

        $reloaded = $this->repository->findByContractIds([900_805]);

        self::assertCount(1, $reloaded);
        self::assertSame($expectedSignature, $reloaded[0]->getItemsSignature());
        self::assertCount(200, $reloaded[0]->getItems());
    }

    #[Group('issue-805')]
    public function testTheMigrationExpressionRehashesAnExistingSignatureLikeTheEntityDoes(): void
    {
        $listing = PublicContractListing::firstSeen(
            900_806,
            self::FORGE,
            5001,
            1_000_000.0,
            [['typeId' => 34, 'quantity' => 100], ['typeId' => 35, 'quantity' => 50]],
            new \DateTimeImmutable('+7 days'),
            new \DateTimeImmutable('-1 day'),
        );

        $rehashedBySql = $this->em->getConnection()->fetchOne("SELECT encode(sha256(convert_to('34:100;35:50', 'UTF8')), 'hex')");

        self::assertSame($listing->getItemsSignature(), $rehashedBySql);
    }

    /**
     * @param list<PublicContractListing> $listings
     */
    private function persistAll(array $listings): void
    {
        foreach ($listings as $listing) {
            $this->em->persist($listing);
        }
        $this->flushAndClear();
    }
}
