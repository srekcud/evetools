<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\User;
use App\Exception\ProducerMissingFromSdeException;
use App\Service\Industry\EstimatedSellPrice;
use App\Service\Industry\EstimatedSellPriceEstimator;
use App\Service\Industry\ProductionCostService;
use App\Service\Industry\ProjectEstimatesRefresher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectEstimatesRefresher::class)]
#[Group('issue-179')]
final class ProjectEstimatesRefresherTest extends TestCase
{
    private const int RIFTER = 587;
    private const int PROJECT_RUNS = 10;
    private const float STALE_ESTIMATED_JOB_COST = 111_000.0;
    private const float STALE_ESTIMATED_MATERIAL_COST = 222_000.0;
    private const float STALE_ESTIMATED_SELL_PRICE = 100_000.0;
    private const float STALE_ESTIMATED_TAX_AMOUNT = 3_000.0;
    private const float ESTIMATED_JOB_COST = 1_250_000.0;
    private const float ESTIMATED_MATERIAL_COST = 400_000.0;
    private const float ESTIMATED_SELL_PRICE = 1_000_000.0;
    private const float ESTIMATED_TAX_AMOUNT = 30_000.0;
    private const string JOB_INSTALL_COST_FAILURE_REASON = 'Cost index missing for Rifter';
    private const string MATERIAL_COST_FAILURE_REASON = 'Jita prices unavailable for Robotics';

    public function testRefreshRewritesTheThreeEstimationsOfAProjectThatAlreadyHadStaleOnes(): void
    {
        $project = $this->estimatedProject();

        $this->refresherReturning(
            ['total' => self::ESTIMATED_JOB_COST, 'steps' => []],
            ['total' => self::ESTIMATED_MATERIAL_COST, 'items' => [], 'failedProducts' => []],
            new EstimatedSellPrice(self::ESTIMATED_SELL_PRICE, 'structure', self::ESTIMATED_TAX_AMOUNT),
        )->refresh($project);

        self::assertSame(
            [self::ESTIMATED_JOB_COST, self::ESTIMATED_MATERIAL_COST, self::ESTIMATED_SELL_PRICE, 'structure', self::ESTIMATED_TAX_AMOUNT],
            $this->estimationsOf($project),
        );
    }

    public function testUnknownJobInstallCostAndUnknownMaterialCostReplaceTheStaleAmountsWithNull(): void
    {
        $project = $this->estimatedProject();

        $this->refresherReturning(
            ['total' => null, 'steps' => []],
            ['total' => null, 'items' => [], 'failedProducts' => []],
            new EstimatedSellPrice(self::ESTIMATED_SELL_PRICE, 'jita', self::ESTIMATED_TAX_AMOUNT),
        )->refresh($project);

        self::assertSame(
            [null, null, self::ESTIMATED_SELL_PRICE, 'jita', self::ESTIMATED_TAX_AMOUNT],
            $this->estimationsOf($project),
        );
    }

    public function testFailedJobInstallCostEstimationLeavesItUnknownLogsAWarningAndRefreshesTheOtherTwoEstimations(): void
    {
        $project = $this->estimatedProject();
        $logger = new BufferingLogger();
        $productionCostService = $this->createStub(ProductionCostService::class);
        $productionCostService->method('estimateJobInstallCosts')->willThrowException(new \RuntimeException(self::JOB_INSTALL_COST_FAILURE_REASON));
        $productionCostService->method('estimateMaterialCost')->willReturn(['total' => self::ESTIMATED_MATERIAL_COST, 'items' => [], 'failedProducts' => []]);
        $sellPriceEstimator = $this->createStub(EstimatedSellPriceEstimator::class);
        $sellPriceEstimator->method('estimateFor')->willReturn(new EstimatedSellPrice(self::ESTIMATED_SELL_PRICE, 'jita', self::ESTIMATED_TAX_AMOUNT));

        (new ProjectEstimatesRefresher($productionCostService, $sellPriceEstimator, $logger))->refresh($project);

        self::assertSame(
            [null, self::ESTIMATED_MATERIAL_COST, self::ESTIMATED_SELL_PRICE, 'jita', self::ESTIMATED_TAX_AMOUNT],
            $this->estimationsOf($project),
        );
        self::assertSame([['job_install_cost', self::JOB_INSTALL_COST_FAILURE_REASON]], $this->estimationAndReasonOfTheWarnings($logger));
    }

    public function testFailedMaterialCostEstimationLeavesItUnknownLogsAWarningAndRefreshesTheOtherTwoEstimations(): void
    {
        $project = $this->estimatedProject();
        $logger = new BufferingLogger();
        $productionCostService = $this->createStub(ProductionCostService::class);
        $productionCostService->method('estimateJobInstallCosts')->willReturn(['total' => self::ESTIMATED_JOB_COST, 'steps' => []]);
        $productionCostService->method('estimateMaterialCost')->willThrowException(new \RuntimeException(self::MATERIAL_COST_FAILURE_REASON));
        $sellPriceEstimator = $this->createStub(EstimatedSellPriceEstimator::class);
        $sellPriceEstimator->method('estimateFor')->willReturn(new EstimatedSellPrice(self::ESTIMATED_SELL_PRICE, 'jita', self::ESTIMATED_TAX_AMOUNT));

        (new ProjectEstimatesRefresher($productionCostService, $sellPriceEstimator, $logger))->refresh($project);

        self::assertSame(
            [self::ESTIMATED_JOB_COST, null, self::ESTIMATED_SELL_PRICE, 'jita', self::ESTIMATED_TAX_AMOUNT],
            $this->estimationsOf($project),
        );
        self::assertSame([['material_cost', self::MATERIAL_COST_FAILURE_REASON]], $this->estimationAndReasonOfTheWarnings($logger));
    }

    public function testFailedSellPriceEstimationLeavesTheWholeTripletUnknownLogsAWarningAndRefreshesTheCosts(): void
    {
        $project = $this->estimatedProject();
        $logger = new BufferingLogger();
        $productionCostService = $this->createStub(ProductionCostService::class);
        $productionCostService->method('estimateJobInstallCosts')->willReturn(['total' => self::ESTIMATED_JOB_COST, 'steps' => []]);
        $productionCostService->method('estimateMaterialCost')->willReturn(['total' => self::ESTIMATED_MATERIAL_COST, 'items' => [], 'failedProducts' => []]);
        $sellPriceEstimator = $this->createStub(EstimatedSellPriceEstimator::class);
        $sellPriceEstimator->method('estimateFor')->willThrowException(new ProducerMissingFromSdeException(self::RIFTER));

        (new ProjectEstimatesRefresher($productionCostService, $sellPriceEstimator, $logger))->refresh($project);

        self::assertSame(
            [self::ESTIMATED_JOB_COST, self::ESTIMATED_MATERIAL_COST, null, null, null],
            $this->estimationsOf($project),
        );
        self::assertSame([['sell_price', 'No blueprint or reaction found for product type ID 587']], $this->estimationAndReasonOfTheWarnings($logger));
    }

    public function testSellPriceThatDisappearedFromTheMarketsLeavesTheWholeTripletUnknownWithoutAWarning(): void
    {
        $project = $this->estimatedProject();
        $logger = new BufferingLogger();

        (new ProjectEstimatesRefresher(
            $this->productionCostServiceReturning(['total' => self::ESTIMATED_JOB_COST, 'steps' => []], ['total' => self::ESTIMATED_MATERIAL_COST, 'items' => [], 'failedProducts' => []]),
            $this->sellPriceEstimatorReturning(null),
            $logger,
        ))->refresh($project);

        self::assertSame(
            [self::ESTIMATED_JOB_COST, self::ESTIMATED_MATERIAL_COST, null, null, null],
            $this->estimationsOf($project),
        );
        self::assertSame([], $this->estimationAndReasonOfTheWarnings($logger));
    }

    public function testWarningOfAFailedEstimationNamesTheProjectAndKeepsTheException(): void
    {
        $project = $this->estimatedProject();
        $projectId = Uuid::v4();
        (new \ReflectionProperty(IndustryProject::class, 'id'))->setValue($project, $projectId);
        $logger = new BufferingLogger();
        $failure = new \RuntimeException(self::MATERIAL_COST_FAILURE_REASON);
        $productionCostService = $this->createStub(ProductionCostService::class);
        $productionCostService->method('estimateJobInstallCosts')->willReturn(['total' => self::ESTIMATED_JOB_COST, 'steps' => []]);
        $productionCostService->method('estimateMaterialCost')->willThrowException($failure);

        (new ProjectEstimatesRefresher($productionCostService, $this->sellPriceEstimatorReturning(null), $logger))->refresh($project);

        $warnings = $this->warningsOf($logger);
        self::assertCount(1, $warnings);
        self::assertSame('Industry project estimation failed', $warnings[0][1]);
        self::assertSame($projectId->toRfc4122(), $warnings[0][2]['projectId']);
        self::assertSame($failure, $warnings[0][2]['exception']);
    }

    private function refresherReturning(array $jobInstallCosts, array $materialCost, ?EstimatedSellPrice $estimatedSellPrice): ProjectEstimatesRefresher
    {
        return new ProjectEstimatesRefresher(
            $this->productionCostServiceReturning($jobInstallCosts, $materialCost),
            $this->sellPriceEstimatorReturning($estimatedSellPrice),
            new BufferingLogger(),
        );
    }

    private function productionCostServiceReturning(array $jobInstallCosts, array $materialCost): ProductionCostService
    {
        $productionCostService = $this->createStub(ProductionCostService::class);
        $productionCostService->method('estimateJobInstallCosts')->willReturn($jobInstallCosts);
        $productionCostService->method('estimateMaterialCost')->willReturn($materialCost);

        return $productionCostService;
    }

    private function sellPriceEstimatorReturning(?EstimatedSellPrice $estimatedSellPrice): EstimatedSellPriceEstimator
    {
        $sellPriceEstimator = $this->createStub(EstimatedSellPriceEstimator::class);
        $sellPriceEstimator->method('estimateFor')->willReturn($estimatedSellPrice);

        return $sellPriceEstimator;
    }

    private function estimatedProject(): IndustryProject
    {
        return (new IndustryProject())->setOwner(new User())
            ->setProductTypeId(self::RIFTER)
            ->setRuns(self::PROJECT_RUNS)
            ->setEstimatedJobCost(self::STALE_ESTIMATED_JOB_COST)
            ->setEstimatedMaterialCost(self::STALE_ESTIMATED_MATERIAL_COST)
            ->recordEstimatedSellPrice(self::STALE_ESTIMATED_SELL_PRICE, 'jita', self::STALE_ESTIMATED_TAX_AMOUNT);
    }

    private function estimationsOf(IndustryProject $project): array
    {
        return [
            $project->getEstimatedJobCost(),
            $project->getEstimatedMaterialCost(),
            $project->getEstimatedSellPrice(),
            $project->getEstimatedSellPriceSource(),
            $project->getEstimatedTaxAmount(),
        ];
    }

    private function warningsOf(BufferingLogger $logger): array
    {
        return array_values(array_filter(
            $logger->cleanLogs(),
            static fn (array $log): bool => $log[0] === LogLevel::WARNING,
        ));
    }

    private function estimationAndReasonOfTheWarnings(BufferingLogger $logger): array
    {
        return array_map(
            static fn (array $warning): array => [$warning[2]['estimation'], $warning[2]['reason']],
            $this->warningsOf($logger),
        );
    }
}
