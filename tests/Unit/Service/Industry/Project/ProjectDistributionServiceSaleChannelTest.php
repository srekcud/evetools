<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Enum\ContributionType;
use App\Enum\SaleChannel;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectSaleRepository;
use App\Service\Industry\Project\ProjectDistributionService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectDistributionService::class)]
#[Group('issue-325')]
class ProjectDistributionServiceSaleChannelTest extends TestCase
{
    private const float MARKET_REVENUE = 100_000_000.0;
    private const float CONTRACT_REVENUE = 50_000_000.0;
    private const float PROJECT_COST = 120_000_000.0;
    private const float BROKER_FEE_PERCENT = 3.6;
    private const float SALES_TAX_PERCENT = 3.6;

    public function testContractRevenuePaysNeitherBrokerFeeNorSalesTax(): void
    {
        $result = $this->distributionOf(
            [$this->saleOf(SaleChannel::Market, self::MARKET_REVENUE), $this->saleOf(SaleChannel::Contract, self::CONTRACT_REVENUE)],
        );

        self::assertEqualsWithDelta(150_000_000.0, $result->totalRevenue, 0.001);
        self::assertEqualsWithDelta(100_000_000.0, $result->marketRevenue, 0.001);
        self::assertEqualsWithDelta(50_000_000.0, $result->contractRevenue, 0.001);
        self::assertEqualsWithDelta(3_600_000.0, $result->brokerFee, 0.001);
        self::assertEqualsWithDelta(3_600_000.0, $result->salesTax, 0.001);
        self::assertEqualsWithDelta(142_800_000.0, $result->netRevenue, 0.001);
    }

    public function testMarginIsComputedOnTheNetRevenueThatSpareContractsFromFees(): void
    {
        $result = $this->distributionOf(
            [$this->saleOf(SaleChannel::Market, self::MARKET_REVENUE), $this->saleOf(SaleChannel::Contract, self::CONTRACT_REVENUE)],
        );

        self::assertEqualsWithDelta((142_800_000.0 - self::PROJECT_COST) / self::PROJECT_COST * 100, $result->marginPercent, 0.001);
    }

    public function testProjectSoldOnlyByContractPaysNoFeeEvenWhenTheFeesAreUnknown(): void
    {
        $result = $this->distributionOf(
            [$this->saleOf(SaleChannel::Contract, self::CONTRACT_REVENUE)],
            brokerFeePercent: null,
            salesTaxPercent: null,
        );

        self::assertSame(0.0, $result->brokerFee);
        self::assertSame(0.0, $result->salesTax);
        self::assertEqualsWithDelta(self::CONTRACT_REVENUE, $result->netRevenue, 0.001);
        self::assertSame(0.0, $result->marketRevenue);
    }

    public function testMarketRevenueWithUnknownFeesStaysUnknownEvenBesideAContract(): void
    {
        $result = $this->distributionOf(
            [$this->saleOf(SaleChannel::Market, self::MARKET_REVENUE), $this->saleOf(SaleChannel::Contract, self::CONTRACT_REVENUE)],
            brokerFeePercent: null,
            salesTaxPercent: null,
        );

        self::assertNull($result->brokerFee);
        self::assertNull($result->salesTax);
        self::assertNull($result->netRevenue);
    }

    public function testProjectSoldOnTheMarketOnlyKeepsItsFees(): void
    {
        $result = $this->distributionOf([$this->saleOf(SaleChannel::Market, self::MARKET_REVENUE)]);

        self::assertEqualsWithDelta(self::MARKET_REVENUE, $result->marketRevenue, 0.001);
        self::assertSame(0.0, $result->contractRevenue);
        self::assertEqualsWithDelta(92_800_000.0, $result->netRevenue, 0.001);
    }

    /**
     * @param list<IndustryProjectSale> $sales
     */
    private function distributionOf(array $sales, ?float $brokerFeePercent = self::BROKER_FEE_PERCENT, ?float $salesTaxPercent = self::SALES_TAX_PERCENT): \App\Service\Industry\Project\DistributionResult
    {
        $project = $this->createStub(IndustryProject::class);
        $project->method('getBrokerFeePercent')->willReturn($brokerFeePercent);
        $project->method('getSalesTaxPercent')->willReturn($salesTaxPercent);
        $project->method('getBomItems')->willReturn(new ArrayCollection());

        $saleRepository = $this->createStub(IndustryProjectSaleRepository::class);
        $saleRepository->method('findBy')->willReturn($sales);
        $contributionRepository = $this->createStub(IndustryProjectContributionRepository::class);
        $contributionRepository->method('findApprovedByProject')->willReturn([$this->contributionOf(self::PROJECT_COST)]);

        return (new ProjectDistributionService($contributionRepository, $saleRepository))->calculateDistribution($project);
    }

    private function saleOf(SaleChannel $channel, float $totalPrice): IndustryProjectSale
    {
        return (new IndustryProjectSale())
            ->setChannel($channel)
            ->setTypeId(0)
            ->setTypeName('Hammerhead II')
            ->setQuantity(1)
            ->setUnitPrice($totalPrice)
            ->recalculateTotalPrice()
            ->setSoldAt(new \DateTimeImmutable('2026-11-01 10:00:00'));
    }

    private function contributionOf(float $value): IndustryProjectContribution
    {
        $member = $this->createStub(IndustryProjectParticipant::class);
        $member->method('getId')->willReturn(Uuid::v4());
        $member->method('getUser')->willReturn($this->createStub(User::class));

        $contribution = $this->createStub(IndustryProjectContribution::class);
        $contribution->method('getMember')->willReturn($member);
        $contribution->method('getType')->willReturn(ContributionType::Material);
        $contribution->method('getEstimatedValue')->willReturn($value);

        return $contribution;
    }
}
