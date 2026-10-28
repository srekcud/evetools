<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\Industry\ProjectStepResource;
use App\ApiResource\Input\Industry\UpdateStepInput;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\IndustryStepCalculator;
use App\State\Processor\Industry\UpdateStepProcessor;
use App\State\Provider\Industry\IndustryResourceMapper;
use App\Tests\Unit\Service\Industry\BuildsProjectOnGoldenSde;
use App\Tests\Unit\Service\Industry\TataraAndRaitaruBonusService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(UpdateStepProcessor::class)]
final class UpdateStepProcessorTest extends TestCase
{
    use BuildsProjectOnGoldenSde;

    private const int QUANTUM_MICROPROCESSOR = 11540;
    private const int TITANIUM_CARBIDE = 16671;
    private const int PHENOLIC_COMPOSITES = 16680;
    private const int FERNITE_CARBIDE = 16673;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int BLUEPRINT_MISSING_FROM_THE_SDE = 999_999_999;
    private const int FORMULA_MISSING_FROM_THE_SDE = 999_999_998;

    private User $pilot;
    private IndustryProject $project;

    protected function setUp(): void
    {
        $this->pilot = new User();
        $this->project = (new IndustryProject())->setUser($this->pilot);
    }

    #[Group('issue-137')]
    public function testRunsEnteredOnAStepWhoseBlueprintHasNoProductForItsActivityAreRefusedWithoutChangingTheStep(): void
    {
        $phenolicComposites = $this->stepOfTheProject(self::PHENOLIC_COMPOSITES, self::FORMULA_MISSING_FROM_THE_SDE, 'reaction', runs: 4, quantity: 8_800, depth: 0, meLevel: 0);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        try {
            $this->patch($phenolicComposites, $this->runsEntered(5), $entityManager);
            self::fail('Runs entered on a step whose blueprint has no product in the SDE must be refused.');
        } catch (BadRequestHttpException $refusal) {
            self::assertSame('Product of blueprint 999999998 missing from the SDE', $refusal->getMessage());
        }

        self::assertSame([4, 8_800], [$phenolicComposites->getRuns(), $phenolicComposites->getQuantity()]);
    }

    #[Group('issue-137')]
    public function testMeLevelEnteredOnTheRootStepOfAProjectWithAChildWithoutProductInTheSdeIsRefusedWithoutFlushingAnyStep(): void
    {
        $quantumMicroprocessor = $this->stepOfTheProject(self::QUANTUM_MICROPROCESSOR, self::QUANTUM_MICROPROCESSOR + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'manufacturing', runs: 32, quantity: 32, depth: 0, meLevel: 10);
        $this->stepOfTheProject(self::TITANIUM_CARBIDE, self::BLUEPRINT_MISSING_FROM_THE_SDE, 'reaction', runs: 1, quantity: 1, depth: 1, meLevel: 0);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');
        $meLevelEntered = new UpdateStepInput();
        $meLevelEntered->meLevel = 8;

        try {
            $this->patch($quantumMicroprocessor, $meLevelEntered, $entityManager);
            self::fail('A cascade reaching a child without product in the SDE must refuse the update.');
        } catch (BadRequestHttpException $refusal) {
            self::assertSame('Product of blueprint 999999999 missing from the SDE', $refusal->getMessage());
        }
    }

    #[Group('issue-137')]
    public function testRunsEnteredOnAReactionStepGiveItTheUnitsPerRunOfItsReactionFormula(): void
    {
        $ferniteCarbide = $this->stepOfTheProject(self::FERNITE_CARBIDE, self::FERNITE_CARBIDE + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'reaction', runs: 2, quantity: 20_000, depth: 0, meLevel: 0);

        $this->patch($ferniteCarbide, $this->runsEntered(3), $this->createStub(EntityManagerInterface::class));

        self::assertSame([3, 30_000], [$ferniteCarbide->getRuns(), $ferniteCarbide->getQuantity()]);
    }

    #[Group('issue-137')]
    public function testRunsEnteredOnACopyStepCurrentlyCountTheUnitsPerRunOfTheProductManufacturedByItsBlueprintIssue137(): void
    {
        $hydrogenFuelBlockCopy = $this->stepOfTheProject(self::HYDROGEN_FUEL_BLOCK, self::HYDROGEN_FUEL_BLOCK + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'copy', runs: 6, quantity: 6, depth: 1, meLevel: 0);

        $this->patch($hydrogenFuelBlockCopy, $this->runsEntered(10), $this->createStub(EntityManagerInterface::class));

        self::assertSame([10, 400], [$hydrogenFuelBlockCopy->getRuns(), $hydrogenFuelBlockCopy->getQuantity()]);
    }

    #[Group('issue-137')]
    public function testRunsEnteredOnARootStepAreFlushedOnceAfterTheCascadeRecalculatedItsMaterialStep(): void
    {
        $ferniteCarbide = $this->stepOfTheProject(self::FERNITE_CARBIDE, self::FERNITE_CARBIDE + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'reaction', runs: 2, quantity: 20_000, depth: 0, meLevel: 0);
        $hydrogenFuelBlock = $this->stepOfTheProject(self::HYDROGEN_FUEL_BLOCK, self::HYDROGEN_FUEL_BLOCK + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'manufacturing', runs: 1, quantity: 10, depth: 1, meLevel: 0);
        $stepsAtFlush = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush')->willReturnCallback(
            static function () use ($ferniteCarbide, $hydrogenFuelBlock, &$stepsAtFlush): void {
                $stepsAtFlush = [
                    [$ferniteCarbide->getRuns(), $ferniteCarbide->getQuantity()],
                    [$hydrogenFuelBlock->getRuns(), $hydrogenFuelBlock->getQuantity()],
                ];
            },
        );

        $this->patch($ferniteCarbide, $this->runsEntered(3), $entityManager, $this->createStub(EntityManagerInterface::class));

        self::assertSame([[3, 30_000], [1, 15]], $stepsAtFlush);
    }

    #[Group('issue-137')]
    public function testMeLevelEnteredOnTheRootStepRecalculatesTheQuantityOfItsMaterialStep(): void
    {
        $quantumMicroprocessor = $this->stepOfTheProject(self::QUANTUM_MICROPROCESSOR, self::QUANTUM_MICROPROCESSOR + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'manufacturing', runs: 32, quantity: 32, depth: 0, meLevel: 10);
        $titaniumCarbide = $this->stepOfTheProject(self::TITANIUM_CARBIDE, self::TITANIUM_CARBIDE + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'reaction', runs: 1, quantity: 490, depth: 1, meLevel: 0);
        $meLevelEntered = new UpdateStepInput();
        $meLevelEntered->meLevel = 8;

        $this->patch($quantumMicroprocessor, $meLevelEntered, $this->createStub(EntityManagerInterface::class));

        self::assertSame([8, 1, 501], [$quantumMicroprocessor->getMeLevel(), $titaniumCarbide->getRuns(), $titaniumCarbide->getQuantity()]);
    }

    private function stepOfTheProject(int $productTypeId, int $blueprintTypeId, string $activityType, int $runs, int $quantity, int $depth, int $meLevel): IndustryProjectStep
    {
        $step = (new IndustryProjectStep())
            ->setProductTypeId($productTypeId)
            ->setBlueprintTypeId($blueprintTypeId)
            ->setActivityType($activityType)
            ->setRuns($runs)
            ->setQuantity($quantity)
            ->setDepth($depth)
            ->setMeLevel($meLevel);
        $this->project->addStep($step);

        return $step;
    }

    private function runsEntered(int $runs): UpdateStepInput
    {
        $input = new UpdateStepInput();
        $input->runs = $runs;

        return $input;
    }

    private function patch(IndustryProjectStep $step, UpdateStepInput $input, EntityManagerInterface $entityManager, ?EntityManagerInterface $calculatorEntityManager = null): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->pilot);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($this->project);
        $stepRepository = $this->createStub(IndustryProjectStepRepository::class);
        $stepRepository->method('find')->willReturn($step);
        $mapper = $this->createStub(IndustryResourceMapper::class);
        $mapper->method('stepToResource')->willReturn(new ProjectStepResource());
        $structureConfigRepository = $this->createStub(IndustryStructureConfigRepository::class);
        $productRepository = $this->productRepositoryOfTheGoldenBlueprints();
        $calculationService = $this->calculationServiceOnGoldenSde($this->bonusServiceWithoutStructure(), $structureConfigRepository);

        $processor = new UpdateStepProcessor(
            $security,
            $projectRepository,
            $stepRepository,
            $productRepository,
            $structureConfigRepository,
            new IndustryStepCalculator($calculationService, $this->materialRepositoryOnGoldenSde(), $productRepository, $calculatorEntityManager ?? $entityManager),
            $mapper,
            $entityManager,
        );
        $processor->process($input, new Patch(), ['id' => Uuid::v4()->toRfc4122(), 'stepId' => Uuid::v4()->toRfc4122()]);
    }

    private function productRepositoryOfTheGoldenBlueprints(): IndustryActivityProductRepository
    {
        $productOf = static function (int $blueprintTypeId, int $activityId): ?IndustryActivityProduct {
            $productTypeId = $blueprintTypeId - self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET;
            $recipe = self::goldenRecipes()[$productTypeId] ?? null;
            if ($recipe === null || self::activityIdOf($recipe['activity']) !== $activityId) {
                return null;
            }

            return self::activityProduct($productTypeId, $recipe);
        };
        $repository = $this->createStub(IndustryActivityProductRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?IndustryActivityProduct => $productOf($criteria['typeId'], $criteria['activityId']),
        );
        $repository->method('findProductsForBlueprints')->willReturnCallback(
            static function (array $blueprintTypeIds, array $activityIds) use ($productOf): array {
                $products = [];
                foreach (array_unique($blueprintTypeIds) as $blueprintTypeId) {
                    foreach (array_unique($activityIds) as $activityId) {
                        $product = $productOf($blueprintTypeId, $activityId);
                        if ($product !== null) {
                            $products[$blueprintTypeId.'-'.$activityId] = $product;
                        }
                    }
                }

                return $products;
            },
        );

        return $repository;
    }

    private function bonusServiceWithoutStructure(): TataraAndRaitaruBonusService
    {
        return new TataraAndRaitaruBonusService(
            static fn (): bool => false,
            static fn (int $typeId): bool => (self::goldenRecipes()[$typeId]['activity'] ?? null) === 'reaction',
            $this->createStub(IndustryRigCategoryRepository::class),
            $this->createStub(IndustryStructureConfigRepository::class),
            $this->createStub(InvTypeRepository::class),
            $this->createStub(IndustryActivityProductRepository::class),
        );
    }
}
