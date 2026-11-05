<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Industry\ProjectResource;
use App\ApiResource\Input\Industry\CreateProjectInput;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\IndustryProjectFactory;
use App\Service\Industry\ProductionCostService;
use App\Service\Industry\SalesFees\SellerSalesFeesResolver;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\State\Processor\Industry\CreateProjectProcessor;
use App\State\Provider\Industry\IndustryResourceMapper;
use App\Tests\Unit\Service\Industry\AutowiresByType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(CreateProjectProcessor::class)]
final class CreateProjectProcessorOutsideFavoriteSystemTest extends TestCase
{
    use AutowiresByType;

    private const int RIFTER = 587;
    private const int RIFTER_RUNS = 12;
    private const float MINIMUM_OUTSIDE_SAVING_IN_ISK = 125000.0;
    private const float DEFAULT_MINIMUM_OUTSIDE_SAVING_IN_ISK = 500000.0;

    public function testOutsideFavoriteSystemOptionEnteredAtCreationIsGivenToTheFactory(): void
    {
        $input = $this->input();
        $input->acceptsOutsideFavoriteSystem = true;
        $input->minimumOutsideSaving = self::MINIMUM_OUTSIDE_SAVING_IN_ISK;

        $optionGivenToTheFactory = $this->optionGivenToTheFactoryFor($input);

        $this->assertSame([true, self::MINIMUM_OUTSIDE_SAVING_IN_ISK], $optionGivenToTheFactory);
    }

    public function testProjectCreatedWithoutTheOptionRefusesAStructureOutsideTheFavoriteSystemWithTheDefaultMinimumSaving(): void
    {
        $optionGivenToTheFactory = $this->optionGivenToTheFactoryFor($this->input());

        $this->assertSame([false, self::DEFAULT_MINIMUM_OUTSIDE_SAVING_IN_ISK], $optionGivenToTheFactory);
    }

    private function optionGivenToTheFactoryFor(CreateProjectInput $input): array
    {
        $optionGivenToTheFactory = [];
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createStub(User::class));
        $projectFactory = $this->createStub(IndustryProjectFactory::class);
        $projectFactory->method('createProject')->willReturnCallback(
            function (User $user, int $productTypeId, int $runs, int $meLevel, float $maxJobDurationDays, int $teLevel, ?string $name, array $excludedTypeIds = [], bool $acceptsOutsideFavoriteSystem = false, float $minimumOutsideSaving = 0.0) use (&$optionGivenToTheFactory): IndustryProject {
                $optionGivenToTheFactory = [$acceptsOutsideFavoriteSystem, $minimumOutsideSaving];

                return (new IndustryProject())->setOwner($user)->setProductTypeId($productTypeId)->setRuns($runs);
            },
        );
        $mapper = $this->createStub(IndustryResourceMapper::class);
        $mapper->method('projectToResource')->willReturn(new ProjectResource());
        $productionCostService = $this->createStub(ProductionCostService::class);
        $productionCostService->method('estimateJobInstallCosts')->willReturn(['total' => 0.0, 'steps' => []]);
        $productionCostService->method('estimateMaterialCost')->willReturn(['total' => 0.0, 'items' => [], 'failedProducts' => []]);

        $processor = self::autowired(CreateProjectProcessor::class, [
            $security,
            $projectFactory,
            $mapper,
            $productionCostService,
            $this->createStub(JitaMarketService::class),
            $this->createStub(StructureMarketService::class),
            $this->createStub(IndustryActivityProductRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(SellerSalesFeesResolver::class),
            new NullLogger(),
        ]);

        $processor->process($input, new Post());

        return $optionGivenToTheFactory;
    }

    private function input(): CreateProjectInput
    {
        $input = new CreateProjectInput();
        $input->typeId = self::RIFTER;
        $input->runs = self::RIFTER_RUNS;

        return $input;
    }
}
