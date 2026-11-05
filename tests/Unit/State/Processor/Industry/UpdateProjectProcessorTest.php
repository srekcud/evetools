<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\Industry\ProjectResource;
use App\ApiResource\Input\Industry\UpdateProjectInput;
use App\Entity\IndustryProject;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\EstimatedSellPriceEstimator;
use App\Service\Industry\SalesFees\SalesFees;
use App\Service\Industry\SalesFees\SellerSalesFeesResolver;
use App\Service\Industry\IndustryProjectFactory;
use App\Service\Industry\ProductionCostService;
use App\Service\Industry\ProjectEstimatesRefresher;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\State\Processor\Industry\UpdateProjectProcessor;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(UpdateProjectProcessor::class)]
#[Group('issue-200')]
final class UpdateProjectProcessorTest extends TestCase
{
    private const int RIFTER = 587;
    private const int INITIAL_RUNS = 1;
    private const int INITIAL_ME_LEVEL = 5;
    private const int INITIAL_TE_LEVEL = 10;
    private const float INITIAL_MAX_JOB_DURATION_DAYS = 2.0;
    private const int PATCHED_RUNS = 10;
    private const float RIFTER_JITA_PRICE = 100_000.0;
    private const float INITIAL_ESTIMATED_JOB_COST = 111_000.0;
    private const float INITIAL_ESTIMATED_MATERIAL_COST = 222_000.0;
    private const float ESTIMATED_JOB_COST_OF_THE_NEW_STEPS = 1_250_000.0;
    private const float ESTIMATED_MATERIAL_COST_OF_THE_NEW_STEPS = 400_000.0;

    private array $events = [];

    public function testProjectIdThatIsNotAUuidIsNotFoundWithoutReadingTheProjectsNorFlushing(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());
        $projectRepository = $this->createMock(IndustryProjectRepository::class);
        $projectRepository->expects($this->never())->method('find');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');
        $processor = new UpdateProjectProcessor(
            $security,
            $projectRepository,
            $this->createStub(IndustryProjectFactory::class),
            $this->createStub(IndustryResourceMapper::class),
            $this->createStub(ProjectEstimatesRefresher::class),
            $entityManager,
        );
        $input = new UpdateProjectInput();
        $input->name = 'A';

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Project not found');

        $processor->process($input, new Patch(), ['id' => 'not-a-uuid']);
    }

    #[Group('issue-179')]
    public function testProjectPassedFromOneRunToTenRunsHasItsEstimatedSellPriceMultipliedByTenAndItsCostsRefreshed(): void
    {
        $user = new User();
        $project = $this->projectOf($user)
            ->setEstimatedJobCost(self::INITIAL_ESTIMATED_JOB_COST)
            ->setEstimatedMaterialCost(self::INITIAL_ESTIMATED_MATERIAL_COST)
            ->recordEstimatedSellPrice(self::RIFTER_JITA_PRICE, 'jita', 3_000.0);
        $input = new UpdateProjectInput();
        $input->runs = self::PATCHED_RUNS;

        $this->processorWith($user, $project, $this->refresherOnJitaPrice($user))->process($input, new Patch(), ['id' => Uuid::v4()->toRfc4122()]);

        self::assertSame(self::PATCHED_RUNS, $project->getRuns());
        self::assertEqualsWithDelta(1_000_000.0, $project->getEstimatedSellPrice(), 0.001);
        self::assertSame('jita', $project->getEstimatedSellPriceSource());
        self::assertEqualsWithDelta(30_000.0, $project->getEstimatedTaxAmount(), 0.001);
        self::assertEqualsWithDelta(self::ESTIMATED_JOB_COST_OF_THE_NEW_STEPS, $project->getEstimatedJobCost(), 0.001);
        self::assertEqualsWithDelta(self::ESTIMATED_MATERIAL_COST_OF_THE_NEW_STEPS, $project->getEstimatedMaterialCost(), 0.001);
    }

    #[Group('issue-179')]
    #[DataProvider('changesThatRegenerateTheSteps')]
    public function testEstimationsAreRefreshedAfterTheStepsAreRegeneratedAndBeforeTheLastFlush(string $field, int|float $value): void
    {
        $user = new User();
        $project = $this->projectOf($user);
        $input = new UpdateProjectInput();
        $input->{$field} = $value;

        $this->processorWith($user, $project, $this->refresherRecordingTheProjectsRefreshed($refreshed))->process($input, new Patch(), ['id' => Uuid::v4()->toRfc4122()]);

        self::assertCount(1, $refreshed);
        self::assertSame($project, $refreshed[0]);
        self::assertSame(['regenerateSteps', 'refresh', 'flush'], \array_slice($this->events, (int) array_search('regenerateSteps', $this->events, true)));
    }

    public static function changesThatRegenerateTheSteps(): iterable
    {
        yield 'runs' => ['runs', self::PATCHED_RUNS];
        yield 'max job duration' => ['maxJobDurationDays', 3.5];
        yield 'material efficiency' => ['meLevel', 8];
        yield 'time efficiency' => ['teLevel', 14];
    }

    #[Group('issue-179')]
    public function testPatchOfTheNameAloneRefreshesNothingAndRegeneratesNothing(): void
    {
        $user = new User();
        $project = $this->projectOf($user);
        $input = new UpdateProjectInput();
        $input->name = 'Rifter run';

        $this->processorWith($user, $project, $this->refresherRecordingTheProjectsRefreshed($refreshed))->process($input, new Patch(), ['id' => Uuid::v4()->toRfc4122()]);

        self::assertSame('Rifter run', $project->getName());
        self::assertCount(0, $refreshed);
        self::assertNotContains('regenerateSteps', $this->events);
    }

    #[Group('issue-179')]
    public function testPatchOfTheRunsAlreadyOfTheProjectRefreshesNothing(): void
    {
        $user = new User();
        $project = $this->projectOf($user);
        $input = new UpdateProjectInput();
        $input->runs = self::INITIAL_RUNS;

        $this->processorWith($user, $project, $this->refresherRecordingTheProjectsRefreshed($refreshed))->process($input, new Patch(), ['id' => Uuid::v4()->toRfc4122()]);

        self::assertCount(0, $refreshed);
    }

    private function projectOf(User $user): IndustryProject
    {
        return (new IndustryProject())
            ->setUser($user)
            ->setProductTypeId(self::RIFTER)
            ->setRuns(self::INITIAL_RUNS)
            ->setMeLevel(self::INITIAL_ME_LEVEL)
            ->setTeLevel(self::INITIAL_TE_LEVEL)
            ->setMaxJobDurationDays(self::INITIAL_MAX_JOB_DURATION_DAYS);
    }

    private function processorWith(User $user, IndustryProject $project, ProjectEstimatesRefresher $refresher): UpdateProjectProcessor
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($project);
        $projectFactory = $this->createStub(IndustryProjectFactory::class);
        $projectFactory->method('regenerateSteps')->willReturnCallback(function (): void {
            $this->events[] = 'regenerateSteps';
        });
        $mapper = $this->createStub(IndustryResourceMapper::class);
        $mapper->method('projectToResource')->willReturn(new ProjectResource());
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            $this->events[] = 'flush';
        });

        return new UpdateProjectProcessor($security, $projectRepository, $projectFactory, $mapper, $refresher, $entityManager);
    }

    private function refresherRecordingTheProjectsRefreshed(?array &$refreshed): ProjectEstimatesRefresher
    {
        $refreshed = [];
        $refresher = $this->createStub(ProjectEstimatesRefresher::class);
        $refresher->method('refresh')->willReturnCallback(function (IndustryProject $project) use (&$refreshed): void {
            $refreshed[] = $project;
            $this->events[] = 'refresh';
        });

        return $refresher;
    }

    private function refresherOnJitaPrice(User $user): ProjectEstimatesRefresher
    {
        $productionCostService = $this->createStub(ProductionCostService::class);
        $productionCostService->method('estimateJobInstallCosts')->willReturn(['total' => self::ESTIMATED_JOB_COST_OF_THE_NEW_STEPS, 'steps' => []]);
        $productionCostService->method('estimateMaterialCost')->willReturn(['total' => self::ESTIMATED_MATERIAL_COST_OF_THE_NEW_STEPS, 'items' => [], 'failedProducts' => []]);
        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getPrice')->willReturnMap([[self::RIFTER, self::RIFTER_JITA_PRICE]]);
        $activityProductRepository = $this->createStub(IndustryActivityProductRepository::class);
        $activityProductRepository->method('findBlueprintForProduct')->willReturn((new IndustryActivityProduct())->setQuantity(1));
        $salesFeesResolver = $this->createStub(SellerSalesFeesResolver::class);
        $salesFeesResolver->method('forUser')->willReturn(new SalesFees(0.01, 0.02));
        $sellPriceEstimator = new EstimatedSellPriceEstimator($jitaMarketService, $this->createStub(StructureMarketService::class), $activityProductRepository, $salesFeesResolver);

        return new ProjectEstimatesRefresher($productionCostService, $sellPriceEstimator, new NullLogger());
    }
}
