<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\CachedIndustryJob;
use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\ProfitMatch;
use App\Entity\ProfitSettings;
use App\Entity\User;
use App\Repository\ProfitMatchRepository;
use App\Repository\ProfitSettingsRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\ProfitMatchingService;
use App\Service\JitaMarketService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProfitMatchingService::class)]
class ProfitMatchingServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private ProfitMatchRepository&Stub $profitMatchRepository;
    private ProfitSettingsRepository&Stub $profitSettingsRepository;
    private IndustryActivityProductRepository&Stub $activityProductRepository;
    private IndustryActivityMaterialRepository&Stub $activityMaterialRepository;
    private JitaMarketService&Stub $jitaMarketService;
    private ProfitMatchingService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->profitMatchRepository = $this->createStub(ProfitMatchRepository::class);
        $this->profitSettingsRepository = $this->createStub(ProfitSettingsRepository::class);
        $this->activityProductRepository = $this->createStub(IndustryActivityProductRepository::class);
        $this->activityMaterialRepository = $this->createStub(IndustryActivityMaterialRepository::class);
        $this->jitaMarketService = $this->createStub(JitaMarketService::class);

        $this->service = new ProfitMatchingService(
            $this->entityManager,
            $this->profitMatchRepository,
            $this->profitSettingsRepository,
            $this->activityProductRepository,
            $this->activityMaterialRepository,
            $this->jitaMarketService,
            new NullLogger(),
        );
    }

    // ===========================================
    // computeMatches — basic FIFO matching
    // ===========================================

    public function testBasicFifoMatchingOneJobOneSale(): void
    {
        $user = $this->createUserWithCharacter();
        $this->setupDefaultSettings($user);

        $job = $this->createJob(1001, 100, 10, 1, 1000.0);
        $tx = $this->createTransaction(2001, 100, 10, 150.0);

        $this->setupQueryBuilderReturns([$job], [$tx]);
        $this->setupProductLookup(100, 1001, 1);
        $this->activityMaterialRepository->method('findByBlueprintAndActivity')->willReturn([]);
        $this->activityProductRepository->method('findBy')->willReturn([]);
        $this->jitaMarketService->method('getPrice')->willReturn(null);

        $persistedMatches = [];
        $this->entityManager->expects($this->atLeastOnce())
            ->method('persist')
            ->with($this->callback(function (ProfitMatch $m) use (&$persistedMatches): bool {
                $persistedMatches[] = $m;
                return true;
            }));

        $count = $this->service->computeMatches($user);

        $this->assertSame(1, $count);
        $this->assertCount(1, $persistedMatches);
        $this->assertSame(10, $persistedMatches[0]->getQuantitySold());
        $this->assertSame(round(150.0 * 10, 2), $persistedMatches[0]->getRevenue());
    }

    public function testPartialMatchingOneJobTwoSales(): void
    {
        $user = $this->createUserWithCharacter();
        $this->setupDefaultSettings($user);

        $job = $this->createJob(1001, 100, 10, 1, 1000.0);
        $tx1 = $this->createTransaction(2001, 100, 6, 150.0);
        $tx2 = $this->createTransaction(2002, 100, 4, 155.0);

        $this->setupQueryBuilderReturns([$job], [$tx1, $tx2]);
        $this->setupProductLookup(100, 1001, 1);
        $this->activityMaterialRepository->method('findByBlueprintAndActivity')->willReturn([]);
        $this->activityProductRepository->method('findBy')->willReturn([]);
        $this->jitaMarketService->method('getPrice')->willReturn(null);

        $persistedMatches = [];
        $this->entityManager->expects($this->atLeastOnce())
            ->method('persist')
            ->with($this->callback(function (ProfitMatch $m) use (&$persistedMatches): bool {
                $persistedMatches[] = $m;
                return true;
            }));

        $count = $this->service->computeMatches($user);

        $this->assertSame(2, $count);
        $this->assertCount(2, $persistedMatches);
        $this->assertSame(6, $persistedMatches[0]->getQuantitySold());
        $this->assertSame(4, $persistedMatches[1]->getQuantitySold());
    }

    public function testMultipleJobsMultipleSalesFifoOrder(): void
    {
        $user = $this->createUserWithCharacter();
        $this->setupDefaultSettings($user);

        // 2 jobs producing type 100: 5 units + 5 units = 10 total
        $job1 = $this->createJob(1001, 100, 5, 1, 500.0);
        $job2 = $this->createJob(1001, 100, 5, 1, 600.0);
        // 1 sale of 10 units
        $tx = $this->createTransaction(2001, 100, 10, 200.0);

        $this->setupQueryBuilderReturns([$job1, $job2], [$tx]);
        $this->setupProductLookup(100, 1001, 1);
        $this->activityMaterialRepository->method('findByBlueprintAndActivity')->willReturn([]);
        $this->activityProductRepository->method('findBy')->willReturn([]);
        $this->jitaMarketService->method('getPrice')->willReturn(null);

        $persistedMatches = [];
        $this->entityManager->expects($this->atLeastOnce())
            ->method('persist')
            ->with($this->callback(function (ProfitMatch $m) use (&$persistedMatches): bool {
                $persistedMatches[] = $m;
                return true;
            }));

        $count = $this->service->computeMatches($user);

        $this->assertSame(2, $count);
        // First job matched first 5 units, second job matched remaining 5
        $this->assertSame(5, $persistedMatches[0]->getQuantitySold());
        $this->assertSame(5, $persistedMatches[1]->getQuantitySold());
    }

    // ===========================================
    // computeMatches — no data
    // ===========================================

    public function testNoSalesReturnsZeroMatches(): void
    {
        $user = $this->createUserWithCharacter();
        $this->setupDefaultSettings($user);

        $job = $this->createJob(1001, 100, 10, 1, 1000.0);

        $this->setupQueryBuilderReturns([$job], []);
        $this->entityManager->expects($this->never())->method('persist');

        $count = $this->service->computeMatches($user);

        $this->assertSame(0, $count);
    }

    public function testNoJobsReturnsZeroMatches(): void
    {
        $user = $this->createUserWithCharacter();
        $this->setupDefaultSettings($user);

        $tx = $this->createTransaction(2001, 100, 10, 150.0);

        $this->setupQueryBuilderReturns([], [$tx]);
        $this->entityManager->expects($this->never())->method('persist');

        $count = $this->service->computeMatches($user);

        $this->assertSame(0, $count);
    }

    public function testNoCharactersReturnsZeroMatches(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());
        $user->method('getCharacters')->willReturn(new ArrayCollection());
        $this->setupDefaultSettings($user);

        $this->setupQueryBuilderReturns([], []);
        $this->entityManager->expects($this->never())->method('persist');

        $count = $this->service->computeMatches($user);

        $this->assertSame(0, $count);
    }

    // ===========================================
    // computeMatches — material cost estimation
    // ===========================================

    public function testMaterialCostEstimationWithJitaPrices(): void
    {
        $user = $this->createUserWithCharacter();
        $settings = new ProfitSettings();
        $settings->setUser($user);
        $settings->setSalesTaxRate(0.0); // zero tax to simplify assertion
        $this->profitSettingsRepository->method('getOrCreate')->willReturn($settings);

        $job = $this->createJob(1001, 100, 1, 1, 0.0); // 0 install cost
        $tx = $this->createTransaction(2001, 100, 1, 1000.0);

        $this->setupQueryBuilderReturns([$job], [$tx]);
        $this->setupProductLookup(100, 1001, 1);

        // Blueprint needs 10 units of type 34 and 5 units of type 35
        $mat1 = $this->createMaterial(34, 10);
        $mat2 = $this->createMaterial(35, 5);
        $this->activityMaterialRepository
            ->method('findByBlueprintAndActivity')
            ->willReturn([$mat1, $mat2]);

        // Buy prices available
        $this->jitaMarketService->method('getBuyPrices')->willReturn([34 => 5.0, 35 => 20.0]);

        $persistedMatches = [];
        $this->entityManager->expects($this->atLeastOnce())
            ->method('persist')
            ->with($this->callback(function (ProfitMatch $m) use (&$persistedMatches): bool {
                $persistedMatches[] = $m;
                return true;
            }));

        $this->service->computeMatches($user);

        $this->assertCount(1, $persistedMatches);
        // Material cost: (10 * 5.0) + (5 * 20.0) = 50 + 100 = 150
        $this->assertSame(150.0, $persistedMatches[0]->getMaterialCost());
        // Revenue: 1 * 1000 = 1000
        $this->assertSame(1000.0, $persistedMatches[0]->getRevenue());
        // Profit: 1000 - 150 - 0 (install) - 0 (tax) = 850
        $this->assertSame(850.0, $persistedMatches[0]->getProfit());
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createUserWithCharacter(): User&Stub
    {
        $character = $this->createStub(Character::class);
        $character->method('getId')->willReturn(Uuid::v4());

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());
        $user->method('getCharacters')->willReturn(new ArrayCollection([$character]));

        return $user;
    }

    private function setupDefaultSettings(User $user): void
    {
        $settings = new ProfitSettings();
        $settings->setUser($user);
        $this->profitSettingsRepository->method('getOrCreate')->willReturn($settings);
    }

    private function createJob(int $blueprintTypeId, int $productTypeId, int $runs, int $jobId, float $cost): CachedIndustryJob&Stub
    {
        $job = $this->createStub(CachedIndustryJob::class);
        $job->method('getBlueprintTypeId')->willReturn($blueprintTypeId);
        $job->method('getProductTypeId')->willReturn($productTypeId);
        $job->method('getRuns')->willReturn($runs);
        $job->method('getJobId')->willReturn($jobId);
        $job->method('getCost')->willReturn($cost);
        $job->method('getCompletedDate')->willReturn(new \DateTimeImmutable('-1 day'));
        $job->method('getEndDate')->willReturn(new \DateTimeImmutable('-1 day'));
        $job->method('getStatus')->willReturn('delivered');
        $job->method('getActivityId')->willReturn(1);

        return $job;
    }

    private function createTransaction(int $transactionId, int $typeId, int $quantity, float $unitPrice): CachedWalletTransaction&Stub
    {
        $tx = $this->createStub(CachedWalletTransaction::class);
        $tx->method('getTransactionId')->willReturn($transactionId);
        $tx->method('getTypeId')->willReturn($typeId);
        $tx->method('getQuantity')->willReturn($quantity);
        $tx->method('getUnitPrice')->willReturn($unitPrice);
        $tx->method('getDate')->willReturn(new \DateTimeImmutable('-1 day'));
        $tx->method('isBuy')->willReturn(false);

        return $tx;
    }

    private function createMaterial(int $materialTypeId, int $quantity): \App\Entity\Sde\IndustryActivityMaterial&Stub
    {
        $mat = $this->createStub(\App\Entity\Sde\IndustryActivityMaterial::class);
        $mat->method('getMaterialTypeId')->willReturn($materialTypeId);
        $mat->method('getQuantity')->willReturn($quantity);

        return $mat;
    }

    private function setupProductLookup(int $productTypeId, int $blueprintTypeId, int $outputPerRun): void
    {
        $product = $this->createStub(\App\Entity\Sde\IndustryActivityProduct::class);
        $product->method('getTypeId')->willReturn($blueprintTypeId);
        $product->method('getQuantity')->willReturn($outputPerRun);

        $this->activityProductRepository
            ->method('findBlueprintForProduct')
            ->willReturn($product);
    }

    /**
     * @param CachedIndustryJob[] $jobs
     * @param CachedWalletTransaction[] $transactions
     */
    private function setupQueryBuilderReturns(array $jobs, array $transactions): void
    {
        $callIndex = 0;

        $this->entityManager
            ->method('createQueryBuilder')
            ->willReturnCallback(function () use (&$callIndex, $jobs, $transactions): QueryBuilder {
                $callIndex++;

                $query = $this->createStub(Query::class);
                $query->method('getResult')->willReturn($callIndex === 1 ? $jobs : $transactions);

                $qb = $this->createStub(QueryBuilder::class);
                $qb->method('select')->willReturnSelf();
                $qb->method('from')->willReturnSelf();
                $qb->method('where')->willReturnSelf();
                $qb->method('andWhere')->willReturnSelf();
                $qb->method('setParameter')->willReturnSelf();
                $qb->method('orderBy')->willReturnSelf();
                $qb->method('addOrderBy')->willReturnSelf();
                $qb->method('getQuery')->willReturn($query);

                return $qb;
            });
    }
}
