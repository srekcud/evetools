<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Industry\ProjectStepResource;
use App\ApiResource\Input\Industry\CreateStepInput;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\State\Processor\Industry\CreateStepProcessor;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(CreateStepProcessor::class)]
final class CreateStepProcessorTest extends TestCase
{
    private const int HELIUM_FUEL_BLOCK = 4247;
    private const int HELIUM_FUEL_BLOCK_BLUEPRINT = 1_004_247;
    private const int HELIUM_FUEL_BLOCK_UNITS_PER_RUN = 40;
    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_CARBIDE_REACTION_FORMULA = 46166;
    private const int FERNITE_CARBIDE_UNITS_PER_RUN = 10_000;
    private const int PRODUCT_MANUFACTURED_AND_REACTED = 990_457;
    private const int BLUEPRINT_OF_THE_PRODUCT_MANUFACTURED_AND_REACTED = 991_457;
    private const int FORMULA_OF_THE_PRODUCT_MANUFACTURED_AND_REACTED = 992_457;
    private const int TRITANIUM = 34;
    private const int TYPE_MISSING_FROM_THE_SDE = 999_999_999;

    private User $pilot;
    private IndustryProject $project;

    protected function setUp(): void
    {
        $this->pilot = new User();
        $this->project = (new IndustryProject())->setUser($this->pilot);
    }

    #[Group('issue-280')]
    public function testStepAddedForAManufacturedTypeIsMadeByItsBlueprintAndCountsItsUnitsPerRun(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $this->post($this->typeEntered(self::HELIUM_FUEL_BLOCK, runs: 5), $this->productRepositoryWith($this->heliumFuelBlockBlueprint()), $entityManager);

        self::assertSame(
            [self::HELIUM_FUEL_BLOCK_BLUEPRINT, self::HELIUM_FUEL_BLOCK, 'manufacturing', 5, 200, 0],
            $this->blueprintProductActivityRunsQuantityAndDepthOf($this->addedStep()),
        );
    }

    #[Group('issue-280')]
    public function testStepAddedForAReactedTypeIsMadeByItsReactionFormulaAndCountsItsUnitsPerRun(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');
        $ferniteCarbideFormula = $this->activityProduct(self::FERNITE_CARBIDE_REACTION_FORMULA, IndustryActivityType::Reaction, self::FERNITE_CARBIDE, self::FERNITE_CARBIDE_UNITS_PER_RUN);

        $this->post($this->typeEntered(self::FERNITE_CARBIDE, runs: 3), $this->productRepositoryWith($ferniteCarbideFormula), $entityManager);

        self::assertSame(
            [self::FERNITE_CARBIDE_REACTION_FORMULA, self::FERNITE_CARBIDE, 'reaction', 3, 30_000, 0],
            $this->blueprintProductActivityRunsQuantityAndDepthOf($this->addedStep()),
        );
    }

    #[Group('issue-280')]
    public function testStepAddedForATypeManufacturedAndReactedIsMadeByItsBlueprint(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');
        $productRepository = $this->productRepositoryWith(
            $this->activityProduct(self::FORMULA_OF_THE_PRODUCT_MANUFACTURED_AND_REACTED, IndustryActivityType::Reaction, self::PRODUCT_MANUFACTURED_AND_REACTED, 200),
            $this->activityProduct(self::BLUEPRINT_OF_THE_PRODUCT_MANUFACTURED_AND_REACTED, IndustryActivityType::Manufacturing, self::PRODUCT_MANUFACTURED_AND_REACTED, 40),
        );

        $this->post($this->typeEntered(self::PRODUCT_MANUFACTURED_AND_REACTED, runs: 2), $productRepository, $entityManager);

        self::assertSame(
            [self::BLUEPRINT_OF_THE_PRODUCT_MANUFACTURED_AND_REACTED, self::PRODUCT_MANUFACTURED_AND_REACTED, 'manufacturing', 2, 80, 0],
            $this->blueprintProductActivityRunsQuantityAndDepthOf($this->addedStep()),
        );
    }

    #[Group('issue-280')]
    public function testStepAddedForATypeWithoutProducerIsRefusedWithoutAddingAnyStep(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        try {
            $this->post($this->typeEntered(self::TRITANIUM, runs: 4), $this->productRepositoryWith($this->heliumFuelBlockBlueprint()), $entityManager);
            self::fail('A step for a type without blueprint nor reaction formula must be refused.');
        } catch (BadRequestHttpException $refusal) {
            self::assertSame('No blueprint or reaction found for product type ID 34', $refusal->getMessage());
        }

        self::assertCount(0, $this->project->getSteps());
    }

    #[Group('issue-280')]
    public function testStepAddedForATypeMissingFromTheSdeIsRefusedBeforeLookingForItsProducer(): void
    {
        $productRepository = $this->createMock(IndustryActivityProductRepository::class);
        $productRepository->expects($this->never())->method('findBlueprintForProduct');

        try {
            $this->post($this->typeEntered(self::TYPE_MISSING_FROM_THE_SDE, runs: 1), $productRepository, $this->createStub(EntityManagerInterface::class));
            self::fail('A step for a type missing from the SDE must be refused.');
        } catch (BadRequestHttpException $refusal) {
            self::assertSame('Unknown type', $refusal->getMessage());
        }
    }

    #[Group('issue-280')]
    public function testStepAddedWithMeAndTeLevelsKeepsThemAndComesAfterTheLastStepOfTheProject(): void
    {
        $this->project->addStep((new IndustryProjectStep())->setSortOrder(4));
        $typeEntered = $this->typeEntered(self::HELIUM_FUEL_BLOCK, runs: 2);
        $typeEntered->meLevel = 10;
        $typeEntered->teLevel = 20;

        $this->post($typeEntered, $this->productRepositoryWith($this->heliumFuelBlockBlueprint()), $this->createStub(EntityManagerInterface::class));

        $addedStep = $this->addedStep();
        self::assertSame([10, 20, 5, 80], [$addedStep->getMeLevel(), $addedStep->getTeLevel(), $addedStep->getSortOrder(), $addedStep->getQuantity()]);
    }

    private function typeEntered(int $typeId, int $runs): CreateStepInput
    {
        $input = new CreateStepInput();
        $input->typeId = $typeId;
        $input->runs = $runs;

        return $input;
    }

    private function addedStep(): IndustryProjectStep
    {
        $addedStep = $this->project->getSteps()->last();
        self::assertInstanceOf(IndustryProjectStep::class, $addedStep);

        return $addedStep;
    }

    private function blueprintProductActivityRunsQuantityAndDepthOf(IndustryProjectStep $step): array
    {
        return [$step->getBlueprintTypeId(), $step->getProductTypeId(), $step->getActivityType(), $step->getRuns(), $step->getQuantity(), $step->getDepth()];
    }

    private function heliumFuelBlockBlueprint(): IndustryActivityProduct
    {
        return $this->activityProduct(self::HELIUM_FUEL_BLOCK_BLUEPRINT, IndustryActivityType::Manufacturing, self::HELIUM_FUEL_BLOCK, self::HELIUM_FUEL_BLOCK_UNITS_PER_RUN);
    }

    private function post(CreateStepInput $input, IndustryActivityProductRepository $productRepository, EntityManagerInterface $entityManager): ProjectStepResource|array
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->pilot);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($this->project);
        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('find')->willReturnCallback(
            static fn (int $typeId): ?InvType => $typeId === self::TYPE_MISSING_FROM_THE_SDE ? null : (new InvType())->setTypeId($typeId),
        );
        $mapper = $this->createStub(IndustryResourceMapper::class);
        $mapper->method('stepToResource')->willReturn(new ProjectStepResource());

        $processor = new CreateStepProcessor(
            $security,
            $projectRepository,
            $this->createStub(IndustryProjectStepRepository::class),
            $invTypeRepository,
            $productRepository,
            $mapper,
            $entityManager,
        );

        return $processor->process($input, new Post(), ['id' => Uuid::v4()->toRfc4122()]);
    }

    private function productRepositoryWith(IndustryActivityProduct ...$products): IndustryActivityProductRepository
    {
        $matches = static fn (IndustryActivityProduct $product, array $criteria): bool => array_intersect_assoc([
            'typeId' => $product->getTypeId(),
            'activityId' => $product->getActivityId(),
            'productTypeId' => $product->getProductTypeId(),
        ], $criteria) == $criteria;
        $repository = $this->createStub(IndustryActivityProductRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            static function (array $criteria) use ($products, $matches): ?IndustryActivityProduct {
                foreach ($products as $product) {
                    if ($matches($product, $criteria)) {
                        return $product;
                    }
                }

                return null;
            },
        );
        $repository->method('findBlueprintForProduct')->willReturnCallback(
            static function (int $productTypeId, int $activityId = 1) use ($products, $matches): ?IndustryActivityProduct {
                foreach ($products as $product) {
                    if ($matches($product, ['productTypeId' => $productTypeId, 'activityId' => $activityId])) {
                        return $product;
                    }
                }

                return null;
            },
        );

        return $repository;
    }

    private function activityProduct(int $blueprintTypeId, IndustryActivityType $activity, int $productTypeId, int $unitsPerRun): IndustryActivityProduct
    {
        return (new IndustryActivityProduct())
            ->setTypeId($blueprintTypeId)
            ->setActivityId($activity->value)
            ->setProductTypeId($productTypeId)
            ->setQuantity($unitsPerRun);
    }
}
