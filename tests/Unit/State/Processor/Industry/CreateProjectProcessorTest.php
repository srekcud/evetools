<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Industry\ProjectResource;
use App\ApiResource\Input\Industry\CreateProjectInput;
use App\Entity\IndustryProject;
use App\Entity\IndustryUserSettings;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\User;
use App\Exception\TypeMissingFromSdeException;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\IndustryProjectFactory;
use App\Service\Industry\ProductionCostService;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\State\Processor\Industry\CreateProjectProcessor;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[CoversClass(CreateProjectProcessor::class)]
final class CreateProjectProcessorTest extends TestCase
{
    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int ROBOTICS = 9848;
    private const int RIFTER = 587;
    private const int HAMMERHEAD_I = 2185;
    private const int ACOLYTE_I = 2203;
    private const int SABRE = 22456;
    private const int ISHTAR = 12005;
    private const int PROJECT_RUNS = 10;
    private const int OUTPUT_QUANTITY_PER_RUN = 1;
    private const int PREFERRED_MARKET_STRUCTURE_ID = 1035466617946;
    private const float BROKER_FEE_RATE = 0.01;
    private const float SALES_TAX_RATE = 0.02;
    private const float ESTIMATED_JOB_COST = 1_250_000.0;
    private const string MATERIAL_COST_FAILURE_REASON = 'Jita prices unavailable for Robotics';

    public function testProjectOnATreeWithATypeMissingFromTheSdeIsABadRequestNamingTheMissingType(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createStub(User::class));
        $projectFactory = $this->createStub(IndustryProjectFactory::class);
        $projectFactory->method('createProject')->willThrowException(new TypeMissingFromSdeException([self::ROBOTICS]));
        $processor = new CreateProjectProcessor(
            $security,
            $projectFactory,
            $this->createStub(IndustryResourceMapper::class),
            $this->createStub(ProductionCostService::class),
            $this->createStub(JitaMarketService::class),
            $this->createStub(StructureMarketService::class),
            $this->createStub(IndustryActivityProductRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(IndustryUserSettingsRepository::class),
            new NullLogger(),
        );
        $input = new CreateProjectInput();
        $input->typeId = self::NITROGEN_FUEL_BLOCK;
        $input->runs = 25;

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Types missing from the SDE: 9848');

        $processor->process($input, new Post());
    }

    #[Group('issue-21')]
    public function testEstimatedTaxAmountAtJitaPriceUsesBrokerFeeAndSalesTaxFromUserSettings(): void
    {
        $project = $this->createProjectWithSellPrices(
            self::RIFTER,
            jitaPrice: 100_000.0,
            structurePrice: null,
            settings: $this->userSettings(),
        );

        self::assertSame('jita', $project->getEstimatedSellPriceSource());
        self::assertEqualsWithDelta(1_000_000.0, $project->getEstimatedSellPrice(), 0.001);
        self::assertEqualsWithDelta(30_000.0, $project->getEstimatedTaxAmount(), 0.001);
    }

    #[Group('issue-21')]
    public function testEstimatedTaxAmountAtStructurePriceUsesTheSameRatesAsAtJita(): void
    {
        $project = $this->createProjectWithSellPrices(
            self::HAMMERHEAD_I,
            jitaPrice: 80_000.0,
            structurePrice: 100_000.0,
            settings: $this->userSettings(),
        );

        self::assertSame('structure', $project->getEstimatedSellPriceSource());
        self::assertEqualsWithDelta(1_000_000.0, $project->getEstimatedSellPrice(), 0.001);
        self::assertEqualsWithDelta(30_000.0, $project->getEstimatedTaxAmount(), 0.001);
    }

    #[Group('issue-21')]
    public function testEstimatedTaxAmountWithoutUserSettingsUsesDefaultBrokerFeeAndSalesTax(): void
    {
        $project = $this->createProjectWithSellPrices(
            self::ACOLYTE_I,
            jitaPrice: 100_000.0,
            structurePrice: null,
            settings: null,
        );

        self::assertSame('jita', $project->getEstimatedSellPriceSource());
        self::assertEqualsWithDelta(72_000.0, $project->getEstimatedTaxAmount(), 0.001);
    }

    #[Group('issue-21')]
    public function testFailedMaterialCostEstimationLeavesItUnknownAndLogsAWarningWithTheReason(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getPreferredMarketStructureId')->willReturn(null);
        $project = $this->projectFor(self::NITROGEN_FUEL_BLOCK);
        $productionCostService = $this->createStub(ProductionCostService::class);
        $productionCostService->method('estimateJobInstallCosts')->willReturn(['total' => self::ESTIMATED_JOB_COST, 'steps' => []]);
        $productionCostService->method('estimateMaterialCost')->willThrowException(new \RuntimeException(self::MATERIAL_COST_FAILURE_REASON));
        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getPrice')->willReturn(null);
        $logger = new BufferingLogger();
        $processor = $this->processor(
            $user,
            $project,
            $productionCostService,
            $jitaMarketService,
            $this->createStub(StructureMarketService::class),
            $this->settingsRepositoryReturning($this->userSettings()),
            $logger,
        );

        $resource = $processor->process($this->inputFor(self::NITROGEN_FUEL_BLOCK), new Post());

        self::assertInstanceOf(ProjectResource::class, $resource);
        self::assertNull($project->getEstimatedMaterialCost());
        self::assertEqualsWithDelta(self::ESTIMATED_JOB_COST, $project->getEstimatedJobCost(), 0.001);
        $warnings = array_values(array_filter(
            $logger->cleanLogs(),
            static fn (array $log): bool => $log[0] === LogLevel::WARNING,
        ));
        self::assertCount(1, $warnings);
        self::assertTrue($this->contextMentions($warnings[0][2], self::MATERIAL_COST_FAILURE_REASON));
    }

    #[Group('issue-119')]
    public function testUnknownMaterialCostOfAProjectWithAFailedProductLeavesTheEstimatedMaterialCostUnknown(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getPreferredMarketStructureId')->willReturn(null);
        $project = $this->projectFor(self::RIFTER);
        $productionCostService = $this->createStub(ProductionCostService::class);
        $productionCostService->method('estimateJobInstallCosts')->willReturn(['total' => self::ESTIMATED_JOB_COST, 'steps' => []]);
        $productionCostService->method('estimateMaterialCost')->willReturn([
            'total' => null,
            'items' => [['typeId' => 35, 'typeName' => 'Pyerite', 'quantity' => 1200, 'unitPrice' => 7.25, 'totalPrice' => 8700.0]],
            'failedProducts' => [['typeId' => self::ROBOTICS, 'reason' => 'No blueprint or reaction found for product type ID 9848']],
        ]);
        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getPrice')->willReturn(null);
        $processor = $this->processor(
            $user,
            $project,
            $productionCostService,
            $jitaMarketService,
            $this->createStub(StructureMarketService::class),
            $this->settingsRepositoryReturning($this->userSettings()),
            new NullLogger(),
        );

        $processor->process($this->inputFor(self::RIFTER), new Post());

        self::assertNull($project->getEstimatedMaterialCost());
        self::assertEqualsWithDelta(self::ESTIMATED_JOB_COST, $project->getEstimatedJobCost(), 0.001);
    }

    #[Group('issue-7')]
    public function testUnknownJobInstallCostOfAProjectWithAnUnknownStepLeavesTheEstimatedJobCostUnknown(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getPreferredMarketStructureId')->willReturn(null);
        $project = $this->projectFor(self::RIFTER);
        $productionCostService = $this->createStub(ProductionCostService::class);
        $productionCostService->method('estimateJobInstallCosts')->willReturn(['total' => null, 'steps' => []]);
        $productionCostService->method('estimateMaterialCost')->willReturn(['total' => 8700.0, 'items' => [], 'failedProducts' => []]);
        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getPrice')->willReturn(null);
        $processor = $this->processor(
            $user,
            $project,
            $productionCostService,
            $jitaMarketService,
            $this->createStub(StructureMarketService::class),
            $this->settingsRepositoryReturning($this->userSettings()),
            new NullLogger(),
        );

        $processor->process($this->inputFor(self::RIFTER), new Post());

        self::assertNull($project->getEstimatedJobCost());
        self::assertEqualsWithDelta(8700.0, $project->getEstimatedMaterialCost(), 0.001);
    }

    #[Group('issue-21')]
    public function testT2ProjectIsCreatedWithTheEnteredMeAndTe(): void
    {
        $factoryArguments = $this->createProjectArgumentsFor(self::SABRE, runs: 3, meLevel: 4, teLevel: 8);

        self::assertSame(self::SABRE, $factoryArguments['productTypeId']);
        self::assertSame(3, $factoryArguments['runs']);
        self::assertSame(4, $factoryArguments['meLevel']);
        self::assertSame(8, $factoryArguments['teLevel']);
    }

    #[Group('issue-21')]
    public function testT2ProjectKeepsThePrefilledInventedBlueprintMeAndTe(): void
    {
        $factoryArguments = $this->createProjectArgumentsFor(self::ISHTAR, runs: 5, meLevel: 2, teLevel: 4);

        self::assertSame(self::ISHTAR, $factoryArguments['productTypeId']);
        self::assertSame(5, $factoryArguments['runs']);
        self::assertSame(2, $factoryArguments['meLevel']);
        self::assertSame(4, $factoryArguments['teLevel']);
    }

    #[Group('issue-21')]
    public function testT1ProjectIsCreatedWithTheEnteredMeAndTe(): void
    {
        $factoryArguments = $this->createProjectArgumentsFor(self::RIFTER, runs: 12, meLevel: 10, teLevel: 20);

        self::assertSame(self::RIFTER, $factoryArguments['productTypeId']);
        self::assertSame(12, $factoryArguments['runs']);
        self::assertSame(10, $factoryArguments['meLevel']);
        self::assertSame(20, $factoryArguments['teLevel']);
    }

    private function createProjectArgumentsFor(int $productTypeId, int $runs, int $meLevel, int $teLevel): array
    {
        $factoryArguments = [];
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createStub(User::class));
        $projectFactory = $this->createStub(IndustryProjectFactory::class);
        $projectFactory->method('createProject')->willReturnCallback(
            function (User $user, int $productTypeId, int $runs, int $meLevel, float $maxJobDurationDays, int $teLevel) use (&$factoryArguments): IndustryProject {
                $factoryArguments = ['productTypeId' => $productTypeId, 'runs' => $runs, 'meLevel' => $meLevel, 'teLevel' => $teLevel];

                return $this->projectFor($productTypeId);
            },
        );
        $mapper = $this->createStub(IndustryResourceMapper::class);
        $mapper->method('projectToResource')->willReturn(new ProjectResource());
        $productionCostService = $this->createStub(ProductionCostService::class);
        $productionCostService->method('estimateJobInstallCosts')->willReturn(['total' => self::ESTIMATED_JOB_COST, 'steps' => []]);
        $productionCostService->method('estimateMaterialCost')->willReturn(['total' => 400_000.0, 'items' => [], 'failedProducts' => []]);
        $processor = new CreateProjectProcessor(
            $security,
            $projectFactory,
            $mapper,
            $productionCostService,
            $this->createStub(JitaMarketService::class),
            $this->createStub(StructureMarketService::class),
            $this->createStub(IndustryActivityProductRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->settingsRepositoryReturning(null),
            new NullLogger(),
        );
        $input = new CreateProjectInput();
        $input->typeId = $productTypeId;
        $input->runs = $runs;
        $input->meLevel = $meLevel;
        $input->teLevel = $teLevel;

        $processor->process($input, new Post());

        return $factoryArguments;
    }

    private function createProjectWithSellPrices(
        int $productTypeId,
        float $jitaPrice,
        ?float $structurePrice,
        ?IndustryUserSettings $settings,
    ): IndustryProject {
        $user = $this->createStub(User::class);
        $user->method('getPreferredMarketStructureId')->willReturn($structurePrice === null ? null : self::PREFERRED_MARKET_STRUCTURE_ID);
        $project = $this->projectFor($productTypeId);
        $productionCostService = $this->createStub(ProductionCostService::class);
        $productionCostService->method('estimateJobInstallCosts')->willReturn(['total' => self::ESTIMATED_JOB_COST, 'steps' => []]);
        $productionCostService->method('estimateMaterialCost')->willReturn(['total' => 400_000.0, 'items' => [], 'failedProducts' => []]);
        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getPrice')->willReturnMap([[$productTypeId, $jitaPrice]]);
        $structureMarketService = $this->createStub(StructureMarketService::class);
        $structureMarketService->method('getLowestSellPrice')->willReturnMap([
            [self::PREFERRED_MARKET_STRUCTURE_ID, $productTypeId, $structurePrice],
        ]);
        $processor = $this->processor(
            $user,
            $project,
            $productionCostService,
            $jitaMarketService,
            $structureMarketService,
            $this->settingsRepositoryReturning($settings),
            new NullLogger(),
        );

        $processor->process($this->inputFor($productTypeId), new Post());

        return $project;
    }

    private function processor(
        User $user,
        IndustryProject $project,
        ProductionCostService $productionCostService,
        JitaMarketService $jitaMarketService,
        StructureMarketService $structureMarketService,
        IndustryUserSettingsRepository $settingsRepository,
        LoggerInterface $logger,
    ): CreateProjectProcessor {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $projectFactory = $this->createStub(IndustryProjectFactory::class);
        $projectFactory->method('createProject')->willReturn($project);
        $mapper = $this->createStub(IndustryResourceMapper::class);
        $mapper->method('projectToResource')->willReturn(new ProjectResource());
        $outputPerRun = $this->createStub(IndustryActivityProduct::class);
        $outputPerRun->method('getQuantity')->willReturn(self::OUTPUT_QUANTITY_PER_RUN);
        $activityProductRepository = $this->createStub(IndustryActivityProductRepository::class);
        $activityProductRepository->method('findBlueprintForProduct')->willReturn($outputPerRun);

        return new CreateProjectProcessor(
            $security,
            $projectFactory,
            $mapper,
            $productionCostService,
            $jitaMarketService,
            $structureMarketService,
            $activityProductRepository,
            $this->createStub(EntityManagerInterface::class),
            $settingsRepository,
            $logger,
        );
    }

    private function settingsRepositoryReturning(?IndustryUserSettings $settings): IndustryUserSettingsRepository
    {
        $settingsRepository = $this->createStub(IndustryUserSettingsRepository::class);
        $settingsRepository->method('findOneBy')->willReturn($settings);

        return $settingsRepository;
    }

    private function userSettings(): IndustryUserSettings
    {
        return (new IndustryUserSettings())
            ->setBrokerFeeRate(self::BROKER_FEE_RATE)
            ->setSalesTaxRate(self::SALES_TAX_RATE);
    }

    private function projectFor(int $productTypeId): IndustryProject
    {
        return (new IndustryProject())
            ->setProductTypeId($productTypeId)
            ->setRuns(self::PROJECT_RUNS);
    }

    private function inputFor(int $productTypeId): CreateProjectInput
    {
        $input = new CreateProjectInput();
        $input->typeId = $productTypeId;
        $input->runs = self::PROJECT_RUNS;

        return $input;
    }

    private function contextMentions(array $context, string $reason): bool
    {
        foreach ($context as $value) {
            if ($value instanceof \Throwable && str_contains($value->getMessage(), $reason)) {
                return true;
            }
            if (\is_string($value) && str_contains($value, $reason)) {
                return true;
            }
        }

        return false;
    }
}
