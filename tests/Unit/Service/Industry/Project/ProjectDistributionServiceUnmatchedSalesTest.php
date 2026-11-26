<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectSaleRepository;
use App\Service\Industry\Project\DistributionResult;
use App\Service\Industry\Project\ProjectDistributionService;
use App\Service\Industry\Project\UnmatchedSale;
use App\Service\Industry\Project\UnsoldProduct;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectDistributionService::class)]
#[Group('issue-1376')]
final class ProjectDistributionServiceUnmatchedSalesTest extends TestCase
{
    private const int HAMMERHEAD_II = 11_993;
    private const int RAVEN = 638;

    private IndustryProject $project;

    protected function setUp(): void
    {
        $this->project = (new IndustryProject())->setOwner(new User())->setName('Hammerhead batch')->setStatus(ProjectStatus::Selling)->setBrokerFeePercent(2.0)->setSalesTaxPercent(3.0);
        $this->project->addBomItem(
            (new IndustryProjectBomLine())->setTypeId(self::HAMMERHEAD_II)->setTypeName('Hammerhead II')->setRequiredQuantity(100)->setIsJob(true)->setJobGroup('final'),
        );
    }

    public function testRevenueOfASaleOutsideTheFinalProductsIsRecordedButEveryFinalProductStaysUnsold(): void
    {
        $result = $this->distributionOf($this->sale(self::RAVEN, 'Raven', 3, 11_100_000.0));

        self::assertSame(33_300_000.0, $result->totalRevenue);
        self::assertEquals([new UnsoldProduct(self::HAMMERHEAD_II, 'Hammerhead II', 100, null)], $result->unsoldProducts);
        self::assertEquals([new UnmatchedSale(self::RAVEN, 'Raven', 3, 33_300_000.0)], $result->unmatchedSales);
    }

    public function testSaleOfAFinalProductReducesTheUnsoldQuantityAndIsNotUnmatched(): void
    {
        $result = $this->distributionOf($this->sale(self::HAMMERHEAD_II, 'Hammerhead II', 40, 800_000.0));

        self::assertSame(32_000_000.0, $result->totalRevenue);
        self::assertEquals([new UnsoldProduct(self::HAMMERHEAD_II, 'Hammerhead II', 60, 800_000.0)], $result->unsoldProducts);
        self::assertSame([], $result->unmatchedSales);
    }

    public function testNoSaleLeavesNothingUnmatched(): void
    {
        $result = $this->distributionOf();

        self::assertSame([], $result->unmatchedSales);
    }

    private function distributionOf(IndustryProjectSale ...$sales): DistributionResult
    {
        $contributions = $this->createStub(IndustryProjectContributionRepository::class);
        $contributions->method('findApprovedByProject')->willReturn([]);
        $saleRepository = $this->createStub(IndustryProjectSaleRepository::class);
        $saleRepository->method('findBy')->willReturn(array_values($sales));

        return (new ProjectDistributionService($contributions, $saleRepository))->calculateDistribution($this->project);
    }

    private function sale(int $typeId, string $typeName, int $quantity, float $unitPrice): IndustryProjectSale
    {
        return (new IndustryProjectSale())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setTotalPrice($quantity * $unitPrice)
            ->setSoldAt(new \DateTimeImmutable('2026-11-01 10:00:00'));
    }
}
