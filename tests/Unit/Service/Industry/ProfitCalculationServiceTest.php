<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\CachedIndustryJob;
use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\ProfitMatch;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Repository\ProfitMatchRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\ProfitCalculationService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProfitCalculationService::class)]
class ProfitCalculationServiceTest extends TestCase
{
    private ProfitMatchRepository&Stub $profitMatchRepository;
    private InvTypeRepository&Stub $invTypeRepository;
    private EntityManagerInterface&Stub $entityManager;
    private ProfitCalculationService $service;

    protected function setUp(): void
    {
        $this->profitMatchRepository = $this->createStub(ProfitMatchRepository::class);
        $this->invTypeRepository = $this->createStub(InvTypeRepository::class);
        $this->entityManager = $this->createStub(EntityManagerInterface::class);

        $this->service = new ProfitCalculationService(
            $this->profitMatchRepository,
            $this->invTypeRepository,
            $this->entityManager,
        );
    }

    // ===========================================
    // getSummary Tests
    // ===========================================

    public function testGetSummaryAggregation(): void
    {
        $user = $this->createUserStub();

        $this->profitMatchRepository
            ->method('getAggregatedByProductType')
            ->willReturn([
                [
                    'productTypeId' => 100,
                    'totalRevenue' => 10000.0,
                    'totalMaterialCost' => 5000.0,
                    'totalJobInstallCost' => 500.0,
                    'totalTaxAmount' => 360.0,
                    'totalProfit' => 4140.0,
                    'quantitySold' => 50,
                    'matchCount' => 3,
                    'lastMatchedAt' => '2026-02-15',
                ],
                [
                    'productTypeId' => 200,
                    'totalRevenue' => 5000.0,
                    'totalMaterialCost' => 6000.0,
                    'totalJobInstallCost' => 200.0,
                    'totalTaxAmount' => 180.0,
                    'totalProfit' => -1380.0,
                    'quantitySold' => 20,
                    'matchCount' => 2,
                    'lastMatchedAt' => '2026-02-14',
                ],
            ]);

        $this->setupTypeNames([
            100 => 'Raven',
            200 => 'Tristanium Plate',
        ]);

        $summary = $this->service->getSummary($user);

        $this->assertSame(2760.0, $summary['totalProfit']);       // 4140 + (-1380)
        $this->assertSame(15000.0, $summary['totalRevenue']);     // 10000 + 5000
        $this->assertSame(12240.0, $summary['totalCost']);        // (5000+500+360) + (6000+200+180)
        $this->assertSame(18.4, $summary['avgMargin']);           // (2760/15000)*100 = 18.4
        $this->assertSame(2, $summary['itemCount']);

        $this->assertNotNull($summary['bestItem']);
        $this->assertSame('Raven', $summary['bestItem']['typeName']);
        $this->assertSame(4140.0, $summary['bestItem']['profit']);

        $this->assertNotNull($summary['worstItem']);
        $this->assertSame('Tristanium Plate', $summary['worstItem']['typeName']);
        $this->assertSame(-1380.0, $summary['worstItem']['profit']);
    }

    public function testGetSummaryWithNoData(): void
    {
        $user = $this->createUserStub();

        $this->profitMatchRepository
            ->method('getAggregatedByProductType')
            ->willReturn([]);

        $this->invTypeRepository->method('findByTypeIds')->willReturn([]);

        $summary = $this->service->getSummary($user);

        $this->assertSame(0.0, $summary['totalProfit']);
        $this->assertSame(0.0, $summary['totalRevenue']);
        $this->assertSame(0.0, $summary['totalCost']);
        $this->assertSame(0.0, $summary['avgMargin']);
        $this->assertSame(0, $summary['itemCount']);
        $this->assertNull($summary['bestItem']);
        $this->assertNull($summary['worstItem']);
    }

    public function testGetSummarySingleItem(): void
    {
        $user = $this->createUserStub();

        $this->profitMatchRepository
            ->method('getAggregatedByProductType')
            ->willReturn([
                [
                    'productTypeId' => 100,
                    'totalRevenue' => 10000.0,
                    'totalMaterialCost' => 5000.0,
                    'totalJobInstallCost' => 500.0,
                    'totalTaxAmount' => 360.0,
                    'totalProfit' => 4140.0,
                    'quantitySold' => 50,
                    'matchCount' => 3,
                    'lastMatchedAt' => '2026-02-15',
                ],
            ]);

        $this->setupTypeNames([100 => 'Raven']);

        $summary = $this->service->getSummary($user);

        // With single item, best and worst are the same
        $this->assertNotNull($summary['bestItem']);
        $this->assertNotNull($summary['worstItem']);
        $this->assertSame('Raven', $summary['bestItem']['typeName']);
        $this->assertSame('Raven', $summary['worstItem']['typeName']);
    }

    // ===========================================
    // getItemProfits Tests
    // ===========================================

    public function testGetItemProfitsWithSortAndFilter(): void
    {
        $user = $this->createUserStub();

        $this->profitMatchRepository
            ->method('getAggregatedByProductType')
            ->willReturn([
                [
                    'productTypeId' => 100,
                    'totalRevenue' => 10000.0,
                    'totalMaterialCost' => 5000.0,
                    'totalJobInstallCost' => 500.0,
                    'totalTaxAmount' => 360.0,
                    'totalProfit' => 4140.0,
                    'quantitySold' => 50,
                    'matchCount' => 3,
                    'lastMatchedAt' => '2026-02-15',
                ],
                [
                    'productTypeId' => 200,
                    'totalRevenue' => 5000.0,
                    'totalMaterialCost' => 6000.0,
                    'totalJobInstallCost' => 200.0,
                    'totalTaxAmount' => 180.0,
                    'totalProfit' => -1380.0,
                    'quantitySold' => 20,
                    'matchCount' => 2,
                    'lastMatchedAt' => '2026-02-14',
                ],
            ]);

        $this->setupTypeNames([100 => 'Raven', 200 => 'Tristanium Plate']);

        // Default sort by profit desc
        $items = $this->service->getItemProfits($user);
        $this->assertCount(2, $items);
        $this->assertSame(100, $items[0]['productTypeId']); // Raven (higher profit) first
        $this->assertSame(200, $items[1]['productTypeId']);
    }

    public function testGetItemProfitsFilterProfit(): void
    {
        $user = $this->createUserStub();

        $this->profitMatchRepository
            ->method('getAggregatedByProductType')
            ->willReturn([
                [
                    'productTypeId' => 100,
                    'totalRevenue' => 10000.0,
                    'totalMaterialCost' => 5000.0,
                    'totalJobInstallCost' => 500.0,
                    'totalTaxAmount' => 360.0,
                    'totalProfit' => 4140.0,
                    'quantitySold' => 50,
                    'matchCount' => 3,
                    'lastMatchedAt' => '2026-02-15',
                ],
                [
                    'productTypeId' => 200,
                    'totalRevenue' => 5000.0,
                    'totalMaterialCost' => 6000.0,
                    'totalJobInstallCost' => 200.0,
                    'totalTaxAmount' => 180.0,
                    'totalProfit' => -1380.0,
                    'quantitySold' => 20,
                    'matchCount' => 2,
                    'lastMatchedAt' => '2026-02-14',
                ],
            ]);

        $this->setupTypeNames([100 => 'Raven', 200 => 'Tristanium Plate']);

        // Filter to only profitable items
        $items = $this->service->getItemProfits($user, 30, 'profit', 'desc', 'profit');
        $this->assertCount(1, $items);
        $this->assertSame(100, $items[0]['productTypeId']);
    }

    public function testGetItemProfitsFilterLoss(): void
    {
        $user = $this->createUserStub();

        $this->profitMatchRepository
            ->method('getAggregatedByProductType')
            ->willReturn([
                [
                    'productTypeId' => 100,
                    'totalRevenue' => 10000.0,
                    'totalMaterialCost' => 5000.0,
                    'totalJobInstallCost' => 500.0,
                    'totalTaxAmount' => 360.0,
                    'totalProfit' => 4140.0,
                    'quantitySold' => 50,
                    'matchCount' => 3,
                    'lastMatchedAt' => '2026-02-15',
                ],
                [
                    'productTypeId' => 200,
                    'totalRevenue' => 5000.0,
                    'totalMaterialCost' => 6000.0,
                    'totalJobInstallCost' => 200.0,
                    'totalTaxAmount' => 180.0,
                    'totalProfit' => -1380.0,
                    'quantitySold' => 20,
                    'matchCount' => 2,
                    'lastMatchedAt' => '2026-02-14',
                ],
            ]);

        $this->setupTypeNames([100 => 'Raven', 200 => 'Tristanium Plate']);

        // Filter to only loss items
        $items = $this->service->getItemProfits($user, 30, 'profit', 'desc', 'loss');
        $this->assertCount(1, $items);
        $this->assertSame(200, $items[0]['productTypeId']);
    }

    public function testGetItemProfitsSortByRevenueAsc(): void
    {
        $user = $this->createUserStub();

        $this->profitMatchRepository
            ->method('getAggregatedByProductType')
            ->willReturn([
                [
                    'productTypeId' => 100,
                    'totalRevenue' => 10000.0,
                    'totalMaterialCost' => 5000.0,
                    'totalJobInstallCost' => 500.0,
                    'totalTaxAmount' => 360.0,
                    'totalProfit' => 4140.0,
                    'quantitySold' => 50,
                    'matchCount' => 3,
                    'lastMatchedAt' => '2026-02-15',
                ],
                [
                    'productTypeId' => 200,
                    'totalRevenue' => 5000.0,
                    'totalMaterialCost' => 3000.0,
                    'totalJobInstallCost' => 200.0,
                    'totalTaxAmount' => 180.0,
                    'totalProfit' => 1620.0,
                    'quantitySold' => 20,
                    'matchCount' => 2,
                    'lastMatchedAt' => '2026-02-14',
                ],
            ]);

        $this->setupTypeNames([100 => 'Raven', 200 => 'Rifter']);

        $items = $this->service->getItemProfits($user, 30, 'revenue', 'asc');
        $this->assertSame(200, $items[0]['productTypeId']); // Rifter (5000) first
        $this->assertSame(100, $items[1]['productTypeId']); // Raven (10000) second
    }

    public function testGetItemProfitsWithMissingTypeName(): void
    {
        $user = $this->createUserStub();

        $this->profitMatchRepository
            ->method('getAggregatedByProductType')
            ->willReturn([
                [
                    'productTypeId' => 99999,
                    'totalRevenue' => 1000.0,
                    'totalMaterialCost' => 500.0,
                    'totalJobInstallCost' => 50.0,
                    'totalTaxAmount' => 36.0,
                    'totalProfit' => 414.0,
                    'quantitySold' => 5,
                    'matchCount' => 1,
                    'lastMatchedAt' => '2026-02-15',
                ],
            ]);

        $this->invTypeRepository->method('findByTypeIds')->willReturn([]);

        $items = $this->service->getItemProfits($user);
        $this->assertCount(1, $items);
        $this->assertSame('Type #99999', $items[0]['typeName']);
    }

    // ===========================================
    // getUnmatched Tests
    // ===========================================

    public function testGetUnmatchedReturnsCorrectUnmatchedJobsAndSales(): void
    {
        $user = $this->createUserWithCharacter();

        // Build a service with a mock EM for this test
        $emMock = $this->createMock(EntityManagerInterface::class);
        $profitMatchRepo = $this->createStub(ProfitMatchRepository::class);
        $invTypeRepo = $this->createStub(InvTypeRepository::class);
        $serviceWithMock = new ProfitCalculationService($profitMatchRepo, $invTypeRepo, $emMock);

        // One matched job (jobId 1) and one matched transaction (txId 100)
        $matchedJob = $this->createStub(CachedIndustryJob::class);
        $matchedJob->method('getJobId')->willReturn(1);

        $matchedTx = $this->createStub(CachedWalletTransaction::class);
        $matchedTx->method('getTransactionId')->willReturn(100);

        $match = $this->createStub(ProfitMatch::class);
        $match->method('getJob')->willReturn($matchedJob);
        $match->method('getTransaction')->willReturn($matchedTx);

        $profitMatchRepo
            ->method('findByUserAndPeriod')
            ->willReturn([$match]);

        // All jobs: id 1 (matched) + id 2 (unmatched)
        $job1 = $this->createStub(CachedIndustryJob::class);
        $job1->method('getJobId')->willReturn(1);
        $job1->method('getProductTypeId')->willReturn(100);
        $job1->method('getRuns')->willReturn(5);
        $job1->method('getCompletedDate')->willReturn(new \DateTimeImmutable('-1 day'));

        $job2 = $this->createStub(CachedIndustryJob::class);
        $job2->method('getJobId')->willReturn(2);
        $job2->method('getProductTypeId')->willReturn(200);
        $job2->method('getRuns')->willReturn(3);
        $job2->method('getCompletedDate')->willReturn(new \DateTimeImmutable('-2 days'));

        // All transactions: id 100 (matched) + id 200 (unmatched)
        $tx1 = $this->createStub(CachedWalletTransaction::class);
        $tx1->method('getTransactionId')->willReturn(100);
        $tx1->method('getTypeId')->willReturn(100);
        $tx1->method('getQuantity')->willReturn(5);
        $tx1->method('getUnitPrice')->willReturn(150.0);
        $tx1->method('getDate')->willReturn(new \DateTimeImmutable('-1 day'));

        $tx2 = $this->createStub(CachedWalletTransaction::class);
        $tx2->method('getTransactionId')->willReturn(200);
        $tx2->method('getTypeId')->willReturn(300);
        $tx2->method('getQuantity')->willReturn(10);
        $tx2->method('getUnitPrice')->willReturn(200.0);
        $tx2->method('getDate')->willReturn(new \DateTimeImmutable('-2 days'));

        $callIndex = 0;
        $emMock
            ->expects($this->exactly(2))
            ->method('createQueryBuilder')
            ->willReturnCallback(function () use (&$callIndex, $job1, $job2, $tx1, $tx2): QueryBuilder {
                $callIndex++;

                $query = $this->createStub(Query::class);
                $query->method('getResult')->willReturn(
                    $callIndex === 1 ? [$job1, $job2] : [$tx1, $tx2]
                );

                $qb = $this->createStub(QueryBuilder::class);
                $qb->method('select')->willReturnSelf();
                $qb->method('from')->willReturnSelf();
                $qb->method('where')->willReturnSelf();
                $qb->method('andWhere')->willReturnSelf();
                $qb->method('setParameter')->willReturnSelf();
                $qb->method('orderBy')->willReturnSelf();
                $qb->method('getQuery')->willReturn($query);

                return $qb;
            });

        $this->setupTypeNamesOn($invTypeRepo, [200 => 'Tritanium', 300 => 'Pyerite']);

        $result = $serviceWithMock->getUnmatched($user);

        // Job 2 is unmatched
        $this->assertCount(1, $result['unmatchedJobs']);
        $this->assertSame(2, $result['unmatchedJobs'][0]['jobId']);
        $this->assertSame(200, $result['unmatchedJobs'][0]['productTypeId']);
        $this->assertSame('Tritanium', $result['unmatchedJobs'][0]['typeName']);

        // Transaction 200 is unmatched
        $this->assertCount(1, $result['unmatchedSales']);
        $this->assertSame(200, $result['unmatchedSales'][0]['transactionId']);
        $this->assertSame(300, $result['unmatchedSales'][0]['typeId']);
        $this->assertSame('Pyerite', $result['unmatchedSales'][0]['typeName']);
    }

    public function testGetUnmatchedWithNoCharacters(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());
        $user->method('getCharacters')->willReturn(new ArrayCollection());

        $profitMatchRepo = $this->createStub(ProfitMatchRepository::class);
        $emMock = $this->createMock(EntityManagerInterface::class);
        $serviceWithMock = new ProfitCalculationService($profitMatchRepo, $this->invTypeRepository, $emMock);

        $profitMatchRepo
            ->method('findByUserAndPeriod')
            ->willReturn([]);

        $emMock->expects($this->never())->method('createQueryBuilder');

        $result = $serviceWithMock->getUnmatched($user);

        $this->assertEmpty($result['unmatchedJobs']);
        $this->assertEmpty($result['unmatchedSales']);
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createUserStub(): User&Stub
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());

        return $user;
    }

    private function createUserWithCharacter(): User&Stub
    {
        $character = $this->createStub(Character::class);
        $character->method('getId')->willReturn(Uuid::v4());

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());
        $user->method('getCharacters')->willReturn(new ArrayCollection([$character]));

        return $user;
    }

    /**
     * @param array<int, string> $names
     */
    private function setupTypeNames(array $names): void
    {
        $this->setupTypeNamesOn($this->invTypeRepository, $names);
    }

    /**
     * @param array<int, string> $names
     */
    private function setupTypeNamesOn(InvTypeRepository&Stub $repo, array $names): void
    {
        $invTypes = [];
        foreach ($names as $typeId => $name) {
            $invType = $this->createStub(InvType::class);
            $invType->method('getTypeName')->willReturn($name);
            $invTypes[$typeId] = $invType;
        }

        $repo->method('findByTypeIds')->willReturn($invTypes);
    }
}
