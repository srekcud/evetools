<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\Character;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Enum\ContributionType;
use App\Enum\SaleChannel;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectSaleRepository;
use App\Service\Industry\Project\DistributionResult;
use App\Service\Industry\Project\ProjectDistributionService;
use App\Service\Industry\Project\ParticipantDistribution;
use App\Service\Industry\Project\UnsoldProduct;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectDistributionService::class)]
class ProjectDistributionServiceTest extends TestCase
{
    private IndustryProjectContributionRepository&MockObject $contributionRepository;
    private IndustryProjectSaleRepository&MockObject $saleRepository;
    private ProjectDistributionService $service;

    protected function setUp(): void
    {
        $this->contributionRepository = $this->createMock(IndustryProjectContributionRepository::class);
        $this->saleRepository = $this->createMock(IndustryProjectSaleRepository::class);

        $this->service = new ProjectDistributionService(
            $this->contributionRepository,
            $this->saleRepository,
        );
    }

    // ===========================================
    // Helper methods
    // ===========================================

    private function stubProject(?float $brokerFeePercent = 3.6, ?float $salesTaxPercent = 3.6, array $bomLines = []): IndustryProject&Stub
    {
        $project = $this->createStub(IndustryProject::class);
        $project->method('getBrokerFeePercent')->willReturn($brokerFeePercent);
        $project->method('getSalesTaxPercent')->willReturn($salesTaxPercent);
        $project->method('getBomItems')->willReturn(new ArrayCollection($bomLines));

        return $project;
    }

    private function stubBomLine(
        int $typeId,
        string $typeName,
        int $requiredQuantity,
        bool $isJob = true,
        ?string $jobGroup = 'final',
    ): IndustryProjectBomLine&Stub {
        $line = $this->createStub(IndustryProjectBomLine::class);
        $line->method('getTypeId')->willReturn($typeId);
        $line->method('getTypeName')->willReturn($typeName);
        $line->method('getRequiredQuantity')->willReturn($requiredQuantity);
        $line->method('isJob')->willReturn($isJob);
        $line->method('getJobGroup')->willReturn($jobGroup);

        return $line;
    }

    private function stubProductSale(
        int $typeId,
        int $quantity,
        float $unitPrice,
        string $soldAt = '2026-11-01 10:00:00',
    ): IndustryProjectSale&Stub {
        $sale = $this->stubSale($quantity * $unitPrice);
        $sale->method('getTypeId')->willReturn($typeId);
        $sale->method('getQuantity')->willReturn($quantity);
        $sale->method('getUnitPrice')->willReturn($unitPrice);
        $sale->method('getSoldAt')->willReturn(new \DateTimeImmutable($soldAt));
        $sale->method('getCreatedAt')->willReturn(new \DateTimeImmutable($soldAt));

        return $sale;
    }

    private function standardContributions(): array
    {
        $alice = $this->stubMember('Alice', '00000000-0000-0000-0000-000000000001');
        $bob = $this->stubMember('Bob', '00000000-0000-0000-0000-000000000002');

        return [
            $this->stubContribution($alice, ContributionType::Material, 600_000.0),
            $this->stubContribution($bob, ContributionType::Material, 400_000.0),
        ];
    }

    private function stubMember(string $characterName, ?string $memberId = null): IndustryProjectParticipant&Stub
    {
        $uuid = $memberId !== null ? Uuid::fromString($memberId) : Uuid::v4();

        $character = $this->createStub(Character::class);
        $character->method('getName')->willReturn($characterName);

        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn($character);

        $member = $this->createStub(IndustryProjectParticipant::class);
        $member->method('getId')->willReturn($uuid);
        $member->method('getUser')->willReturn($user);

        return $member;
    }

    private function stubContribution(
        IndustryProjectParticipant&Stub $member,
        ContributionType $type,
        float $estimatedValue,
    ): IndustryProjectContribution&Stub {
        $contribution = $this->createStub(IndustryProjectContribution::class);
        $contribution->method('getMember')->willReturn($member);
        $contribution->method('getType')->willReturn($type);
        $contribution->method('getEstimatedValue')->willReturn($estimatedValue);

        return $contribution;
    }

    private function stubSale(float $totalPrice): IndustryProjectSale&Stub
    {
        $sale = $this->createStub(IndustryProjectSale::class);
        $sale->method('getTotalPrice')->willReturn($totalPrice);
        $sale->method('getChannel')->willReturn(SaleChannel::Market);

        return $sale;
    }

    private function configureRepositories(
        IndustryProject $project,
        array $sales,
        array $contributions,
    ): void {
        $this->saleRepository->method('findBy')
            ->with(['project' => $project])
            ->willReturn($sales);

        $this->contributionRepository->method('findApprovedByProject')
            ->with($project)
            ->willReturn($contributions);
    }

    // ===========================================
    // Tests
    // ===========================================

    public function testNormalProfitScenario(): void
    {
        $project = $this->stubProject(3.0, 2.0);

        $alice = $this->stubMember('Alice', '00000000-0000-0000-0000-000000000001');
        $bob = $this->stubMember('Bob', '00000000-0000-0000-0000-000000000002');
        $carol = $this->stubMember('Carol', '00000000-0000-0000-0000-000000000003');

        $contributions = [
            $this->stubContribution($alice, ContributionType::Material, 500_000.0),
            $this->stubContribution($alice, ContributionType::JobInstall, 100_000.0),
            $this->stubContribution($bob, ContributionType::Material, 300_000.0),
            $this->stubContribution($bob, ContributionType::Bpc, 50_000.0),
            $this->stubContribution($carol, ContributionType::Material, 200_000.0),
            $this->stubContribution($carol, ContributionType::LineRental, 50_000.0),
        ];

        // Total costs: 600k + 350k + 250k = 1,200,000
        // Total revenue: 2,000,000
        // Broker fee: 2,000,000 * 3% = 60,000
        // Sales tax: 2,000,000 * 2% = 40,000
        // Net revenue: 2,000,000 - 60,000 - 40,000 = 1,900,000
        // Margin%: (1,900,000 - 1,200,000) / 1,200,000 * 100 = 58.333...%

        $sales = [
            $this->stubSale(1_200_000.0),
            $this->stubSale(800_000.0),
        ];

        $this->configureRepositories($project, $sales, $contributions);

        $result = $this->service->calculateDistribution($project);

        self::assertSame(2_000_000.0, $result->totalRevenue);
        self::assertSame(60_000.0, $result->brokerFee);
        self::assertSame(40_000.0, $result->salesTax);
        self::assertSame(1_900_000.0, $result->netRevenue);
        self::assertSame(1_200_000.0, $result->totalProjectCost);
        self::assertEqualsWithDelta(58.3333, $result->marginPercent, 0.001);

        self::assertCount(3, $result->members);

        // Alice: 600k costs, 50% share
        $aliceDist = $this->findMember($result, '00000000-0000-0000-0000-000000000001');
        self::assertSame('Alice', $aliceDist->characterName);
        self::assertSame(600_000.0, $aliceDist->totalCostsEngaged);
        self::assertSame(500_000.0, $aliceDist->materialCosts);
        self::assertSame(100_000.0, $aliceDist->jobInstallCosts);
        self::assertSame(0.0, $aliceDist->bpcCosts);
        self::assertSame(0.0, $aliceDist->lineRentalCosts);
        self::assertEqualsWithDelta(50.0, $aliceDist->sharePercent, 0.01);
        self::assertEqualsWithDelta(350_000.0, $aliceDist->profitPart, 1.0);
        self::assertEqualsWithDelta(950_000.0, $aliceDist->payoutTotal, 1.0);

        // Bob: 350k costs, ~29.17% share
        $bobDist = $this->findMember($result, '00000000-0000-0000-0000-000000000002');
        self::assertSame('Bob', $bobDist->characterName);
        self::assertSame(350_000.0, $bobDist->totalCostsEngaged);
        self::assertSame(300_000.0, $bobDist->materialCosts);
        self::assertSame(0.0, $bobDist->jobInstallCosts);
        self::assertSame(50_000.0, $bobDist->bpcCosts);
        self::assertSame(0.0, $bobDist->lineRentalCosts);
        self::assertEqualsWithDelta(29.167, $bobDist->sharePercent, 0.01);
        self::assertEqualsWithDelta(204_166.67, $bobDist->profitPart, 1.0);
        self::assertEqualsWithDelta(554_166.67, $bobDist->payoutTotal, 1.0);

        // Carol: 250k costs, ~20.83% share
        $carolDist = $this->findMember($result, '00000000-0000-0000-0000-000000000003');
        self::assertSame('Carol', $carolDist->characterName);
        self::assertSame(250_000.0, $carolDist->totalCostsEngaged);
        self::assertSame(200_000.0, $carolDist->materialCosts);
        self::assertSame(0.0, $carolDist->jobInstallCosts);
        self::assertSame(0.0, $carolDist->bpcCosts);
        self::assertSame(50_000.0, $carolDist->lineRentalCosts);
        self::assertEqualsWithDelta(20.833, $carolDist->sharePercent, 0.01);
        self::assertEqualsWithDelta(145_833.33, $carolDist->profitPart, 1.0);
        self::assertEqualsWithDelta(395_833.33, $carolDist->payoutTotal, 1.0);

        // Verify payouts sum to net revenue
        $totalPayouts = array_sum(array_map(fn ($m) => $m->payoutTotal, $result->members));
        self::assertEqualsWithDelta($result->netRevenue, $totalPayouts, 1.0);
    }

    public function testLossScenario(): void
    {
        $project = $this->stubProject(3.0, 2.0);

        $alice = $this->stubMember('Alice', '00000000-0000-0000-0000-000000000001');
        $bob = $this->stubMember('Bob', '00000000-0000-0000-0000-000000000002');

        // Total costs: 600k + 400k = 1,000,000
        $contributions = [
            $this->stubContribution($alice, ContributionType::Material, 600_000.0),
            $this->stubContribution($bob, ContributionType::Material, 400_000.0),
        ];

        // Revenue: 500,000
        // Broker fee: 500,000 * 3% = 15,000
        // Sales tax: 500,000 * 2% = 10,000
        // Net revenue: 500,000 - 15,000 - 10,000 = 475,000
        // Margin%: (475,000 - 1,000,000) / 1,000,000 * 100 = -52.5%
        $sales = [$this->stubSale(500_000.0)];

        $this->configureRepositories($project, $sales, $contributions);

        $result = $this->service->calculateDistribution($project);

        self::assertSame(500_000.0, $result->totalRevenue);
        self::assertSame(475_000.0, $result->netRevenue);
        self::assertSame(1_000_000.0, $result->totalProjectCost);
        self::assertEqualsWithDelta(-52.5, $result->marginPercent, 0.001);

        // Alice: 600k costs, 60% share, loses proportionally
        $aliceDist = $this->findMember($result, '00000000-0000-0000-0000-000000000001');
        self::assertEqualsWithDelta(60.0, $aliceDist->sharePercent, 0.01);
        self::assertEqualsWithDelta(-315_000.0, $aliceDist->profitPart, 1.0);
        self::assertEqualsWithDelta(285_000.0, $aliceDist->payoutTotal, 1.0);

        // Bob: 400k costs, 40% share
        $bobDist = $this->findMember($result, '00000000-0000-0000-0000-000000000002');
        self::assertEqualsWithDelta(40.0, $bobDist->sharePercent, 0.01);
        self::assertEqualsWithDelta(-210_000.0, $bobDist->profitPart, 1.0);
        self::assertEqualsWithDelta(190_000.0, $bobDist->payoutTotal, 1.0);

        // Payouts sum to net revenue
        $totalPayouts = array_sum(array_map(fn ($m) => $m->payoutTotal, $result->members));
        self::assertEqualsWithDelta($result->netRevenue, $totalPayouts, 1.0);
    }

    public function testBreakEvenScenario(): void
    {
        $project = $this->stubProject(0.0, 0.0);

        $alice = $this->stubMember('Alice', '00000000-0000-0000-0000-000000000001');

        $contributions = [
            $this->stubContribution($alice, ContributionType::Material, 1_000_000.0),
        ];

        $sales = [$this->stubSale(1_000_000.0)];

        $this->configureRepositories($project, $sales, $contributions);

        $result = $this->service->calculateDistribution($project);

        self::assertSame(1_000_000.0, $result->totalRevenue);
        self::assertSame(1_000_000.0, $result->netRevenue);
        self::assertSame(1_000_000.0, $result->totalProjectCost);
        self::assertEqualsWithDelta(0.0, $result->marginPercent, 0.001);

        $aliceDist = $this->findMember($result, '00000000-0000-0000-0000-000000000001');
        self::assertSame(1_000_000.0, $aliceDist->totalCostsEngaged);
        self::assertEqualsWithDelta(100.0, $aliceDist->sharePercent, 0.01);
        self::assertEqualsWithDelta(0.0, $aliceDist->profitPart, 0.01);
        self::assertEqualsWithDelta(1_000_000.0, $aliceDist->payoutTotal, 0.01);
    }

    public function testSingleMemberGetsFullShare(): void
    {
        $project = $this->stubProject(2.0, 2.0);

        $alice = $this->stubMember('Alice', '00000000-0000-0000-0000-000000000001');

        $contributions = [
            $this->stubContribution($alice, ContributionType::Material, 500_000.0),
            $this->stubContribution($alice, ContributionType::JobInstall, 100_000.0),
        ];

        // Revenue: 1,000,000
        // Broker: 1,000,000 * 2% = 20,000
        // Tax: 1,000,000 * 2% = 20,000
        // Net: 960,000
        // Total cost: 600,000
        // Margin: (960,000 - 600,000) / 600,000 = 0.6
        $sales = [$this->stubSale(1_000_000.0)];

        $this->configureRepositories($project, $sales, $contributions);

        $result = $this->service->calculateDistribution($project);

        self::assertCount(1, $result->members);

        $aliceDist = $result->members[0];
        self::assertSame('Alice', $aliceDist->characterName);
        self::assertSame(600_000.0, $aliceDist->totalCostsEngaged);
        self::assertEqualsWithDelta(100.0, $aliceDist->sharePercent, 0.01);
        self::assertEqualsWithDelta(960_000.0, $aliceDist->payoutTotal, 1.0);
    }

    #[Group('issue-146')]
    public function testMarginIsUnknownWhenNoContributionIsApproved(): void
    {
        $project = $this->stubProject(3.0, 3.0);

        $sales = [$this->stubSale(500_000.0)];

        $this->configureRepositories($project, $sales, []);

        $result = $this->service->calculateDistribution($project);

        self::assertSame(
            [500_000.0, 15_000.0, 15_000.0, 470_000.0, 0.0, null, []],
            [$result->totalRevenue, $result->brokerFee, $result->salesTax, $result->netRevenue, $result->totalProjectCost, $result->marginPercent, $result->members],
        );
    }

    #[Group('issue-323')]
    public function testNoSalesAwaitsSalesInsteadOfReportingTotalLoss(): void
    {
        $project = $this->stubProject(3.0, 3.0, [$this->stubBomLine(1, 'Hammerhead II', 50)]);

        $this->configureRepositories($project, [], $this->standardContributions());

        $result = $this->service->calculateDistribution($project);

        self::assertTrue($result->awaitingSales);
        self::assertSame(0.0, $result->totalRevenue);
        self::assertSame(0.0, $result->brokerFee);
        self::assertSame(0.0, $result->salesTax);
        self::assertNull($result->netRevenue);
        self::assertNull($result->marginPercent);
        self::assertSame(1_000_000.0, $result->totalProjectCost);
        self::assertSame([], $result->unsoldProducts);
        self::assertNull($result->projectedMarginPercent);

        $aliceDist = $this->findMember($result, '00000000-0000-0000-0000-000000000001');
        self::assertSame(600_000.0, $aliceDist->totalCostsEngaged);
        self::assertEqualsWithDelta(60.0, $aliceDist->sharePercent, 0.001);
        self::assertNull($aliceDist->profitPart);
        self::assertNull($aliceDist->payoutTotal);

        $bobDist = $this->findMember($result, '00000000-0000-0000-0000-000000000002');
        self::assertSame(400_000.0, $bobDist->totalCostsEngaged);
        self::assertEqualsWithDelta(40.0, $bobDist->sharePercent, 0.001);
        self::assertNull($bobDist->profitPart);
        self::assertNull($bobDist->payoutTotal);
    }

    #[Group('issue-323')]
    public function testPartialSalesListUnsoldProductsAndProjectMarginAtLastUnitPrice(): void
    {
        $project = $this->stubProject(1.5, 3.5, [$this->stubBomLine(1, 'Hammerhead II', 50)]);

        $this->configureRepositories($project, [$this->stubProductSale(1, 30, 40_000.0)], $this->standardContributions());

        $result = $this->service->calculateDistribution($project);

        self::assertFalse($result->awaitingSales);
        self::assertSame(1_200_000.0, $result->totalRevenue);
        self::assertEqualsWithDelta(1_140_000.0, $result->netRevenue, 0.001);
        self::assertEqualsWithDelta(14.0, $result->marginPercent, 0.001);
        self::assertEquals([new UnsoldProduct(1, 'Hammerhead II', 20, 40_000.0)], $result->unsoldProducts);
        self::assertEqualsWithDelta(90.0, $result->projectedMarginPercent, 0.001);

        $aliceDist = $this->findMember($result, '00000000-0000-0000-0000-000000000001');
        self::assertEqualsWithDelta(84_000.0, $aliceDist->profitPart, 0.001);
        self::assertEqualsWithDelta(684_000.0, $aliceDist->payoutTotal, 0.001);
    }

    #[Group('issue-323')]
    public function testLastUnitPriceIsTheOneOfTheMostRecentSale(): void
    {
        $project = $this->stubProject(1.5, 3.5, [$this->stubBomLine(1, 'Hammerhead II', 50)]);

        $sales = [
            $this->stubProductSale(1, 20, 40_000.0, '2026-11-02 10:00:00'),
            $this->stubProductSale(1, 10, 30_000.0, '2026-11-01 10:00:00'),
        ];
        $this->configureRepositories($project, $sales, $this->standardContributions());

        $result = $this->service->calculateDistribution($project);

        self::assertEquals([new UnsoldProduct(1, 'Hammerhead II', 20, 40_000.0)], $result->unsoldProducts);
    }

    #[Group('issue-323')]
    public function testNeverSoldProductHasNoLastUnitPriceAndMakesProjectedMarginUnknown(): void
    {
        $project = $this->stubProject(1.5, 3.5, [
            $this->stubBomLine(1, 'Hammerhead II', 50),
            $this->stubBomLine(2, 'Warrior II', 30),
        ]);

        $this->configureRepositories($project, [$this->stubProductSale(1, 25, 40_000.0)], $this->standardContributions());

        $result = $this->service->calculateDistribution($project);

        self::assertEquals(
            [
                new UnsoldProduct(1, 'Hammerhead II', 25, 40_000.0),
                new UnsoldProduct(2, 'Warrior II', 30, null),
            ],
            $result->unsoldProducts,
        );
        self::assertNull($result->projectedMarginPercent);
        self::assertEqualsWithDelta(-5.0, $result->marginPercent, 0.001);
    }

    #[Group('issue-323')]
    public function testFullySoldProjectKeepsItsLossAndHasNoUnsoldProducts(): void
    {
        $project = $this->stubProject(1.5, 3.5, [$this->stubBomLine(1, 'Hammerhead II', 50)]);

        $this->configureRepositories($project, [$this->stubProductSale(1, 50, 16_000.0)], $this->standardContributions());

        $result = $this->service->calculateDistribution($project);

        self::assertFalse($result->awaitingSales);
        self::assertSame([], $result->unsoldProducts);
        self::assertNull($result->projectedMarginPercent);
        self::assertEqualsWithDelta(-24.0, $result->marginPercent, 0.001);
    }

    #[Group('issue-323')]
    public function testProjectedMarginIsUnknownWhenFeesAreUnknown(): void
    {
        $project = $this->stubProject(null, 3.5, [$this->stubBomLine(1, 'Hammerhead II', 50)]);

        $this->configureRepositories($project, [$this->stubProductSale(1, 30, 40_000.0)], $this->standardContributions());

        $result = $this->service->calculateDistribution($project);

        self::assertNull($result->netRevenue);
        self::assertEquals([new UnsoldProduct(1, 'Hammerhead II', 20, 40_000.0)], $result->unsoldProducts);
        self::assertNull($result->projectedMarginPercent);
    }

    #[Group('issue-323')]
    public function testProjectedMarginIsUnknownWhenProjectCostIsZero(): void
    {
        $project = $this->stubProject(1.5, 3.5, [$this->stubBomLine(1, 'Hammerhead II', 50)]);

        $this->configureRepositories($project, [$this->stubProductSale(1, 30, 40_000.0)], []);

        $result = $this->service->calculateDistribution($project);

        self::assertEquals([new UnsoldProduct(1, 'Hammerhead II', 20, 40_000.0)], $result->unsoldProducts);
        self::assertNull($result->projectedMarginPercent);
    }

    #[Group('issue-323')]
    public function testSaleOfTypeOutsideFinalBomCountsInRevenueButNotInUnsoldProducts(): void
    {
        $project = $this->stubProject(1.5, 3.5, [$this->stubBomLine(1, 'Hammerhead II', 50)]);

        $sales = [
            $this->stubProductSale(1, 30, 40_000.0),
            $this->stubProductSale(99, 5, 10_000.0),
        ];
        $this->configureRepositories($project, $sales, $this->standardContributions());

        $result = $this->service->calculateDistribution($project);

        self::assertSame(1_250_000.0, $result->totalRevenue);
        self::assertEqualsWithDelta(1_187_500.0, $result->netRevenue, 0.001);
        self::assertEquals([new UnsoldProduct(1, 'Hammerhead II', 20, 40_000.0)], $result->unsoldProducts);
        self::assertEqualsWithDelta(94.75, $result->projectedMarginPercent, 0.001);
    }

    #[Group('issue-323')]
    public function testOversoldProductNeverGivesNegativeUnsoldQuantity(): void
    {
        $project = $this->stubProject(1.5, 3.5, [
            $this->stubBomLine(1, 'Hammerhead II', 50),
            $this->stubBomLine(2, 'Warrior II', 30),
        ]);

        $sales = [
            $this->stubProductSale(1, 60, 20_000.0),
            $this->stubProductSale(2, 10, 5_000.0),
        ];
        $this->configureRepositories($project, $sales, $this->standardContributions());

        $result = $this->service->calculateDistribution($project);

        self::assertEquals([new UnsoldProduct(2, 'Warrior II', 20, 5_000.0)], $result->unsoldProducts);
    }

    #[Group('issue-323')]
    public function testSeveralFinalLinesOfTheSameTypeAddUpIntoOneUnsoldProduct(): void
    {
        $project = $this->stubProject(1.5, 3.5, [
            $this->stubBomLine(1, 'Hammerhead II', 30),
            $this->stubBomLine(1, 'Hammerhead II', 20),
        ]);

        $this->configureRepositories($project, [$this->stubProductSale(1, 30, 40_000.0)], $this->standardContributions());

        $result = $this->service->calculateDistribution($project);

        self::assertEquals([new UnsoldProduct(1, 'Hammerhead II', 20, 40_000.0)], $result->unsoldProducts);
    }

    #[Group('issue-323')]
    public function testBomLinesThatAreNotFinalJobsAreNotProductsToSell(): void
    {
        $project = $this->stubProject(1.5, 3.5, [
            $this->stubBomLine(1, 'Hammerhead II', 50),
            $this->stubBomLine(5, 'Tritanium', 400, false, null),
            $this->stubBomLine(6, 'Hobgoblin II', 15, true, 'intermediate'),
            $this->stubBomLine(7, 'Warrior II', 12, false, 'final'),
        ]);

        $this->configureRepositories($project, [$this->stubProductSale(1, 30, 40_000.0)], $this->standardContributions());

        $result = $this->service->calculateDistribution($project);

        self::assertEquals([new UnsoldProduct(1, 'Hammerhead II', 20, 40_000.0)], $result->unsoldProducts);
    }

    public function testMixedContributionTypesBreakdown(): void
    {
        $project = $this->stubProject(0.0, 0.0);

        $alice = $this->stubMember('Alice', '00000000-0000-0000-0000-000000000001');

        $contributions = [
            $this->stubContribution($alice, ContributionType::Material, 100_000.0),
            $this->stubContribution($alice, ContributionType::JobInstall, 50_000.0),
            $this->stubContribution($alice, ContributionType::Bpc, 30_000.0),
            $this->stubContribution($alice, ContributionType::LineRental, 20_000.0),
        ];

        $sales = [$this->stubSale(400_000.0)];

        $this->configureRepositories($project, $sales, $contributions);

        $result = $this->service->calculateDistribution($project);

        $aliceDist = $result->members[0];
        self::assertSame(200_000.0, $aliceDist->totalCostsEngaged);
        self::assertSame(100_000.0, $aliceDist->materialCosts);
        self::assertSame(50_000.0, $aliceDist->jobInstallCosts);
        self::assertSame(30_000.0, $aliceDist->bpcCosts);
        self::assertSame(20_000.0, $aliceDist->lineRentalCosts);

        // Verify total = sum of individual types
        $sumByType = $aliceDist->materialCosts + $aliceDist->jobInstallCosts
            + $aliceDist->bpcCosts + $aliceDist->lineRentalCosts;
        self::assertSame($aliceDist->totalCostsEngaged, $sumByType);
    }

    public function testSeveralContributionsOfTheSameTypeAddUpInTheirCostLine(): void
    {
        $project = $this->stubProject(0.0, 0.0);
        $alice = $this->stubMember('Alice', '00000000-0000-0000-0000-000000000001');
        $this->configureRepositories($project, [$this->stubSale(46_000.0)], [
            $this->stubContribution($alice, ContributionType::Material, 12_000.0),
            $this->stubContribution($alice, ContributionType::JobInstall, 1_500.0),
            $this->stubContribution($alice, ContributionType::Bpc, 4_000.0),
            $this->stubContribution($alice, ContributionType::LineRental, 600.0),
            $this->stubContribution($alice, ContributionType::Material, 3_400.0),
            $this->stubContribution($alice, ContributionType::JobInstall, 2_750.0),
            $this->stubContribution($alice, ContributionType::Bpc, 5_250.0),
            $this->stubContribution($alice, ContributionType::LineRental, 450.0),
        ]);

        $aliceDistribution = $this->service->calculateDistribution($project)->members[0];

        self::assertSame(
            [15_400.0, 4_250.0, 9_250.0, 1_050.0, 29_950.0],
            [
                $aliceDistribution->materialCosts,
                $aliceDistribution->jobInstallCosts,
                $aliceDistribution->bpcCosts,
                $aliceDistribution->lineRentalCosts,
                $aliceDistribution->totalCostsEngaged,
            ],
        );
    }

    public function testBrokerFeeAndSalesTaxApplied(): void
    {
        $project = $this->stubProject(5.0, 8.0);

        $alice = $this->stubMember('Alice', '00000000-0000-0000-0000-000000000001');

        $contributions = [
            $this->stubContribution($alice, ContributionType::Material, 500_000.0),
        ];

        // Revenue: 1,000,000
        // Broker: 1,000,000 * 5% = 50,000
        // Tax: 1,000,000 * 8% = 80,000
        // Net: 1,000,000 - 50,000 - 80,000 = 870,000
        $sales = [$this->stubSale(1_000_000.0)];

        $this->configureRepositories($project, $sales, $contributions);

        $result = $this->service->calculateDistribution($project);

        self::assertSame(1_000_000.0, $result->totalRevenue);
        self::assertSame(50_000.0, $result->brokerFee);
        self::assertSame(80_000.0, $result->salesTax);
        self::assertSame(870_000.0, $result->netRevenue);

        // Margin: (870,000 - 500,000) / 500,000 * 100 = 74%
        self::assertEqualsWithDelta(74.0, $result->marginPercent, 0.001);

        $aliceDist = $result->members[0];
        // Payout = 500,000 * (1 + 0.74) = 870,000 = net revenue
        self::assertEqualsWithDelta(870_000.0, $aliceDist->payoutTotal, 1.0);
    }

    public function testMarginPercentIsExpressedAsPercentage(): void
    {
        $project = $this->stubProject(0.0, 0.0);

        $alice = $this->stubMember('Alice', '00000000-0000-0000-0000-000000000001');

        $contributions = [
            $this->stubContribution($alice, ContributionType::Material, 500.0),
        ];

        // Net revenue 1000, total project cost 500 => margin 100 % (not the 1.0 ratio)
        $sales = [$this->stubSale(1_000.0)];

        $this->configureRepositories($project, $sales, $contributions);

        $result = $this->service->calculateDistribution($project);

        self::assertSame(100.0, $result->marginPercent);

        // Payout still uses the ratio: 500 * (1 + 1.0) = 1000
        $aliceDist = $result->members[0];
        self::assertSame(500.0, $aliceDist->profitPart);
        self::assertSame(1_000.0, $aliceDist->payoutTotal);
    }

    // Issue #10: a kicked member keeps the payout of his approved contributions
    public function testRemovedMemberApprovedContributionStillProducesPayout(): void
    {
        $project = $this->stubProject(0.0, 0.0);

        $alice = $this->stubMember('Alice', '00000000-0000-0000-0000-000000000001');
        $alice->method('getStatus')->willReturn(ParticipantStatus::Accepted);
        $removedBob = $this->stubMember('Bob', '00000000-0000-0000-0000-000000000002');
        $removedBob->method('getStatus')->willReturn(ParticipantStatus::Removed);

        $contributions = [
            $this->stubContribution($alice, ContributionType::Material, 600_000.0),
            $this->stubContribution($removedBob, ContributionType::Material, 300_000.0),
            $this->stubContribution($removedBob, ContributionType::JobInstall, 100_000.0),
        ];

        // Revenue 1,500,000, no fees -> net 1,500,000
        // Total cost 1,000,000 -> margin 50%
        // Bob (removed): 400,000 x 1.5 = 600,000 ; Alice: 600,000 x 1.5 = 900,000
        $sales = [$this->stubSale(1_500_000.0)];

        $this->configureRepositories($project, $sales, $contributions);

        $result = $this->service->calculateDistribution($project);

        self::assertSame(1_000_000.0, $result->totalProjectCost);
        self::assertEqualsWithDelta(50.0, $result->marginPercent, 0.0001);
        self::assertCount(2, $result->members);

        $bobDist = $this->findMember($result, '00000000-0000-0000-0000-000000000002');
        self::assertSame('Bob', $bobDist->characterName);
        self::assertSame(400_000.0, $bobDist->totalCostsEngaged);
        self::assertSame(300_000.0, $bobDist->materialCosts);
        self::assertSame(100_000.0, $bobDist->jobInstallCosts);
        self::assertEqualsWithDelta(40.0, $bobDist->sharePercent, 0.0001);
        self::assertEqualsWithDelta(200_000.0, $bobDist->profitPart, 0.01);
        self::assertEqualsWithDelta(600_000.0, $bobDist->payoutTotal, 0.01);

        $aliceDist = $this->findMember($result, '00000000-0000-0000-0000-000000000001');
        self::assertEqualsWithDelta(900_000.0, $aliceDist->payoutTotal, 0.01);
    }

    public function testMemberWithNoMainCharacterShowsUnknown(): void
    {
        $project = $this->stubProject(0.0, 0.0);

        $uuid = Uuid::fromString('00000000-0000-0000-0000-000000000099');
        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn(null);

        $member = $this->createStub(IndustryProjectParticipant::class);
        $member->method('getId')->willReturn($uuid);
        $member->method('getUser')->willReturn($user);

        $contributions = [
            $this->stubContribution($member, ContributionType::Material, 100_000.0),
        ];

        $sales = [$this->stubSale(200_000.0)];

        $this->configureRepositories($project, $sales, $contributions);

        $result = $this->service->calculateDistribution($project);

        self::assertSame('Unknown', $result->members[0]->characterName);
    }

    // ===========================================
    // Private helpers
    // ===========================================

    private function findMember(DistributionResult $result, string $memberId): ParticipantDistribution
    {
        foreach ($result->members as $member) {
            if ($member->memberId === $memberId) {
                return $member;
            }
        }

        self::fail("Member {$memberId} not found in distribution result");
    }
}
