<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\GroupIndustry;

use App\Entity\Character;
use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\GroupIndustrySale;
use App\Entity\User;
use App\Enum\ContributionType;
use App\Repository\GroupIndustryContributionRepository;
use App\Repository\GroupIndustrySaleRepository;
use App\Service\GroupIndustry\DistributionResult;
use App\Service\GroupIndustry\GroupIndustryDistributionService;
use App\Service\GroupIndustry\MemberDistribution;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GroupIndustryDistributionService::class)]
class GroupIndustryDistributionServiceTest extends TestCase
{
    private GroupIndustryContributionRepository&MockObject $contributionRepository;
    private GroupIndustrySaleRepository&MockObject $saleRepository;
    private GroupIndustryDistributionService $service;

    protected function setUp(): void
    {
        $this->contributionRepository = $this->createMock(GroupIndustryContributionRepository::class);
        $this->saleRepository = $this->createMock(GroupIndustrySaleRepository::class);

        $this->service = new GroupIndustryDistributionService(
            $this->contributionRepository,
            $this->saleRepository,
        );
    }

    // ===========================================
    // Helper methods
    // ===========================================

    private function stubProject(float $brokerFeePercent = 3.6, float $salesTaxPercent = 3.6): GroupIndustryProject&Stub
    {
        $project = $this->createStub(GroupIndustryProject::class);
        $project->method('getBrokerFeePercent')->willReturn($brokerFeePercent);
        $project->method('getSalesTaxPercent')->willReturn($salesTaxPercent);

        return $project;
    }

    private function stubMember(string $characterName, ?string $memberId = null): GroupIndustryProjectMember&Stub
    {
        $uuid = $memberId !== null ? Uuid::fromString($memberId) : Uuid::v4();

        $character = $this->createStub(Character::class);
        $character->method('getName')->willReturn($characterName);

        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn($character);

        $member = $this->createStub(GroupIndustryProjectMember::class);
        $member->method('getId')->willReturn($uuid);
        $member->method('getUser')->willReturn($user);

        return $member;
    }

    private function stubContribution(
        GroupIndustryProjectMember&Stub $member,
        ContributionType $type,
        float $estimatedValue,
    ): GroupIndustryContribution&Stub {
        $contribution = $this->createStub(GroupIndustryContribution::class);
        $contribution->method('getMember')->willReturn($member);
        $contribution->method('getType')->willReturn($type);
        $contribution->method('getEstimatedValue')->willReturn($estimatedValue);

        return $contribution;
    }

    private function stubSale(float $totalPrice): GroupIndustrySale&Stub
    {
        $sale = $this->createStub(GroupIndustrySale::class);
        $sale->method('getTotalPrice')->willReturn($totalPrice);

        return $sale;
    }

    private function configureRepositories(
        GroupIndustryProject $project,
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
        // Margin%: (1,900,000 - 1,200,000) / 1,200,000 = 0.58333...

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
        self::assertEqualsWithDelta(0.58333, $result->marginPercent, 0.001);

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
        // Margin%: (475,000 - 1,000,000) / 1,000,000 = -0.525
        $sales = [$this->stubSale(500_000.0)];

        $this->configureRepositories($project, $sales, $contributions);

        $result = $this->service->calculateDistribution($project);

        self::assertSame(500_000.0, $result->totalRevenue);
        self::assertSame(475_000.0, $result->netRevenue);
        self::assertSame(1_000_000.0, $result->totalProjectCost);
        self::assertEqualsWithDelta(-0.525, $result->marginPercent, 0.001);

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

    public function testZeroCostsNoContributions(): void
    {
        $project = $this->stubProject(3.0, 3.0);

        $sales = [$this->stubSale(500_000.0)];

        $this->configureRepositories($project, $sales, []);

        $result = $this->service->calculateDistribution($project);

        self::assertSame(500_000.0, $result->totalRevenue);
        self::assertSame(0.0, $result->totalProjectCost);
        self::assertEqualsWithDelta(0.0, $result->marginPercent, 0.001);
        self::assertCount(0, $result->members);
    }

    public function testZeroRevenueNoSales(): void
    {
        $project = $this->stubProject(3.0, 3.0);

        $alice = $this->stubMember('Alice', '00000000-0000-0000-0000-000000000001');
        $bob = $this->stubMember('Bob', '00000000-0000-0000-0000-000000000002');

        $contributions = [
            $this->stubContribution($alice, ContributionType::Material, 600_000.0),
            $this->stubContribution($bob, ContributionType::Material, 400_000.0),
        ];

        $this->configureRepositories($project, [], $contributions);

        $result = $this->service->calculateDistribution($project);

        // Revenue = 0, Net = 0, Cost = 1,000,000
        // Margin = (0 - 1,000,000) / 1,000,000 = -1.0
        self::assertSame(0.0, $result->totalRevenue);
        self::assertSame(0.0, $result->netRevenue);
        self::assertSame(0.0, $result->brokerFee);
        self::assertSame(0.0, $result->salesTax);
        self::assertSame(1_000_000.0, $result->totalProjectCost);
        self::assertEqualsWithDelta(-1.0, $result->marginPercent, 0.001);

        // Each member loses 100% => payout = 0
        $aliceDist = $this->findMember($result, '00000000-0000-0000-0000-000000000001');
        self::assertEqualsWithDelta(-600_000.0, $aliceDist->profitPart, 1.0);
        self::assertEqualsWithDelta(0.0, $aliceDist->payoutTotal, 1.0);

        $bobDist = $this->findMember($result, '00000000-0000-0000-0000-000000000002');
        self::assertEqualsWithDelta(-400_000.0, $bobDist->profitPart, 1.0);
        self::assertEqualsWithDelta(0.0, $bobDist->payoutTotal, 1.0);
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

        // Margin: (870,000 - 500,000) / 500,000 = 0.74
        self::assertEqualsWithDelta(0.74, $result->marginPercent, 0.001);

        $aliceDist = $result->members[0];
        // Payout = 500,000 * (1 + 0.74) = 870,000 = net revenue
        self::assertEqualsWithDelta(870_000.0, $aliceDist->payoutTotal, 1.0);
    }

    public function testMemberWithNoMainCharacterShowsUnknown(): void
    {
        $project = $this->stubProject(0.0, 0.0);

        $uuid = Uuid::fromString('00000000-0000-0000-0000-000000000099');
        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn(null);

        $member = $this->createStub(GroupIndustryProjectMember::class);
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

    private function findMember(DistributionResult $result, string $memberId): MemberDistribution
    {
        foreach ($result->members as $member) {
            if ($member->memberId === $memberId) {
                return $member;
            }
        }

        self::fail("Member {$memberId} not found in distribution result");
    }
}
