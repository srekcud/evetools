<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Industry\CostEstimationResource;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\ProductionCostService;
use App\State\Provider\Industry\CostEstimationProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(CostEstimationProvider::class)]
final class CostEstimationProviderTest extends TestCase
{
    private const string PROJECT_ID = '0192f0a4-6b1e-7c3a-9d2f-5e8b4c1a7d90';
    private const int PYERITE_TYPE_ID = 35;
    private const int RIFTER_TYPE_ID = 587;
    private const int ROBOTICS_TYPE_ID = 9848;
    private const int FERNITE_CARBIDE_TYPE_ID = 16673;
    private const string ROBOTICS_FAILURE_REASON = 'No blueprint or reaction found for product type ID 9848';

    #[Group('issue-119')]
    public function testCostEstimationOfAProjectWithAFailedProductExposesUnknownMaterialTotalAndPerUnitCostsAndTheFailedProduct(): void
    {
        $failedProducts = [['typeId' => self::ROBOTICS_TYPE_ID, 'reason' => self::ROBOTICS_FAILURE_REASON]];

        $resource = $this->provideFor([
            'materialCost' => null,
            'jobInstallCost' => 5000.0,
            'bpoCost' => 1000.0,
            'totalCost' => null,
            'perUnit' => null,
            'outputQuantity' => 2,
            'materials' => [$this->pyeriteMaterial()],
            'jobInstallSteps' => [],
            'failedProducts' => $failedProducts,
        ]);

        self::assertSame(self::PROJECT_ID, $resource->id);
        self::assertNull($resource->materialCost);
        self::assertNull($resource->totalCost);
        self::assertNull($resource->perUnit);
        self::assertSame(5000.0, $resource->jobInstallCost);
        self::assertSame(1000.0, $resource->bpoCost);
        self::assertSame([$this->pyeriteMaterial()], $resource->materials);
        self::assertSame($failedProducts, $resource->failedProducts);
    }

    #[Group('issue-119')]
    public function testCostEstimationWithoutFailedProductExposesPricedCostsAndNoFailedProduct(): void
    {
        $resource = $this->provideFor([
            'materialCost' => 8700.0,
            'jobInstallCost' => 5000.0,
            'bpoCost' => 1000.0,
            'totalCost' => 14700.0,
            'perUnit' => 7350.0,
            'outputQuantity' => 2,
            'materials' => [$this->pyeriteMaterial()],
            'jobInstallSteps' => [],
            'failedProducts' => [],
        ]);

        self::assertSame(8700.0, $resource->materialCost);
        self::assertSame(14700.0, $resource->totalCost);
        self::assertSame(7350.0, $resource->perUnit);
        self::assertSame([], $resource->failedProducts);
    }

    #[Group('issue-7')]
    public function testCostEstimationWithAnUnknownJobInstallStepExposesUnknownJobTotalTotalAndPerUnitCostsAndTheUnknownStep(): void
    {
        $unknownStep = [
            'stepId' => 'step-unindexed',
            'productTypeId' => self::RIFTER_TYPE_ID,
            'productName' => 'Rifter',
            'solarSystemId' => 30_002_510,
            'systemName' => 'Unindexed',
            'costIndex' => null,
            'runs' => 2,
            'installCost' => null,
            'unknownReason' => 'missing_cost_index',
            'missingAdjustedPriceTypeIds' => [],
        ];

        $resource = $this->provideFor([
            'materialCost' => 8700.0,
            'jobInstallCost' => null,
            'bpoCost' => 1000.0,
            'totalCost' => null,
            'perUnit' => null,
            'outputQuantity' => 2,
            'materials' => [$this->pyeriteMaterial()],
            'jobInstallSteps' => [$unknownStep],
            'failedProducts' => [],
        ]);

        self::assertSame(8700.0, $resource->materialCost);
        self::assertNull($resource->jobInstallCost);
        self::assertNull($resource->totalCost);
        self::assertNull($resource->perUnit);
        self::assertSame([$unknownStep], $resource->jobInstallSteps);
    }

    #[Group('issue-281')]
    public function testCostEstimationExposesTheOutputQuantityOfTheProject(): void
    {
        $resource = $this->provideFor([
            'materialCost' => 76_400.0,
            'jobInstallCost' => 3_600.0,
            'bpoCost' => 12_000.0,
            'totalCost' => 92_000.0,
            'perUnit' => 1.15,
            'outputQuantity' => 80_000,
            'materials' => [],
            'jobInstallSteps' => [],
            'failedProducts' => [],
        ], self::FERNITE_CARBIDE_TYPE_ID, 8);

        self::assertSame(80_000, $resource->outputQuantity);
    }

    private function provideFor(array $estimation, int $productTypeId = self::RIFTER_TYPE_ID, int $runs = 2): CostEstimationResource
    {
        $user = new User();
        $project = (new IndustryProject())
            ->setUser($user)
            ->setProductTypeId($productTypeId)
            ->setRuns($runs);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($project);
        $productionCostService = $this->createStub(ProductionCostService::class);
        $productionCostService->method('estimateTotalCost')->willReturn($estimation);

        return (new CostEstimationProvider($security, $projectRepository, $productionCostService))
            ->provide(new Get(), ['id' => self::PROJECT_ID]);
    }

    private function pyeriteMaterial(): array
    {
        return ['typeId' => self::PYERITE_TYPE_ID, 'typeName' => 'Pyerite', 'quantity' => 1200, 'unitPrice' => 7.25, 'totalPrice' => 8700.0];
    }
}
