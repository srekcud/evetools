<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Industry\ProjectStepResource;
use App\ApiResource\Input\Industry\SplitStepInput;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\SplitGroupMaterialNeeds;
use App\Service\Industry\SplitGroupQuantities;
use App\State\Processor\Industry\SplitStepProcessor;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SplitStepProcessor::class)]
#[CoversClass(SplitGroupMaterialNeeds::class)]
#[CoversClass(SplitGroupQuantities::class)]
final class SplitStepProcessorTest extends TestCase
{
    use BuildsSplitGroupMaterialNeeds;

    private const int OXYGEN_FUEL_BLOCK = 4312;
    private const int OXYGEN_FUEL_BLOCK_BLUEPRINT = 4313;
    private const int OXYGEN_FUEL_BLOCK_OUTPUT_PER_RUN = 40;
    private const int FERNITE_ALLOY = 16656;
    private const int FERNITE_ALLOY_FORMULA = 17960;
    private const int FERNITE_ALLOY_OUTPUT_PER_RUN = 100;
    private const int NOMAD_BLUEPRINT = 28847;
    private const int NOMAD = 28846;
    private const int NOMAD_OUTPUT_PER_RUN_READ_AS_IF_MANUFACTURED = 10;
    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int NITROGEN_FUEL_BLOCK_BLUEPRINT_MISSING_FROM_THE_SDE = 4314;
    private const int MANUFACTURING_ACTIVITY_ID = 1;
    private const int REACTION_ACTIVITY_ID = 11;
    private const float RAITARU_BASE_MATERIAL_BONUS = 1.0;
    private const float ADVANCED_COMPONENT_RIG_MATERIAL_BONUS = 2.4;

    private User $user;
    private IndustryProject $project;

    protected function setUp(): void
    {
        $this->user = new User();
        $this->project = (new IndustryProject())->setUser($this->user);
    }

    #[Group('issue-112')]
    public function testManualSplitOfAManufacturingStepGivesItsLastJobTheRestOfTheNeed(): void
    {
        $step = $this->stepOfTheProject(self::OXYGEN_FUEL_BLOCK, self::OXYGEN_FUEL_BLOCK_BLUEPRINT, 'manufacturing', runs: 5, quantity: 190);

        $this->split($step, numberOfJobs: 2);

        self::assertSame([[3, 120, 0, 5], [2, 70, 1, 5]], $this->jobsOfTheProject());
    }

    #[Group('issue-112')]
    public function testManualSplitOfAReactionStepUsesTheOutputPerRunOfTheFormula(): void
    {
        $step = $this->stepOfTheProject(self::FERNITE_ALLOY, self::FERNITE_ALLOY_FORMULA, 'reaction', runs: 10, quantity: 990);

        $this->split($step, numberOfJobs: 3);

        self::assertSame([[4, 400, 0, 10], [3, 300, 1, 10], [3, 290, 2, 10]], $this->jobsOfTheProject());
    }

    #[Group('issue-112')]
    public function testManualSplitOfAStepNeedingLessThanOneRunGivesTheNextJobsNothing(): void
    {
        $step = $this->stepOfTheProject(self::OXYGEN_FUEL_BLOCK, self::OXYGEN_FUEL_BLOCK_BLUEPRINT, 'manufacturing', runs: 2, quantity: 30);

        $this->split($step, numberOfJobs: 2);

        self::assertSame([[1, 30, 0, 2], [1, 0, 1, 2]], $this->jobsOfTheProject());
    }

    #[Group('issue-112')]
    public function testManualSplitOfACopyStepGivesEachJobOneUnitPerRun(): void
    {
        $step = $this->stepOfTheProject(self::NOMAD, self::NOMAD_BLUEPRINT, 'copy', runs: 10, quantity: 10);

        $this->split($step, numberOfJobs: 2);

        self::assertSame([[5, 5, 0, 10], [5, 5, 1, 10]], $this->jobsOfTheProject());
    }

    #[Group('issue-112')]
    public function testManualSplitOfAStepWhoseProductIsMissingFromTheSdeIsRefusedWithoutChangingTheStep(): void
    {
        $step = $this->stepOfTheProject(self::NITROGEN_FUEL_BLOCK, self::NITROGEN_FUEL_BLOCK_BLUEPRINT_MISSING_FROM_THE_SDE, 'manufacturing', runs: 6, quantity: 230);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        try {
            $this->split($step, numberOfJobs: 2, entityManager: $entityManager);
            self::fail('A step whose product is missing from the SDE must not be split.');
        } catch (BadRequestHttpException $refusal) {
            self::assertSame('Product of blueprint 4314 missing from the SDE', $refusal->getMessage());
        }

        self::assertSame([[6, 230, null]], array_map(
            static fn (IndustryProjectStep $unchanged): array => [$unchanged->getRuns(), $unchanged->getQuantity(), $unchanged->getSplitGroupId()],
            array_values($this->project->getSteps()->toArray()),
        ));
    }

    #[Group('issue-73')]
    public function testManualSplitOfAConsumerGivesItsMaterialStepTheMaterialsOfEachNewJob(): void
    {
        $quantumMicroprocessor = $this->stepOfTheProject(self::QUANTUM_MICROPROCESSOR, self::blueprintOfRecipe(self::QUANTUM_MICROPROCESSOR), 'manufacturing', runs: 32, quantity: 32);
        $this->stepOfTheProject(self::TITANIUM_CARBIDE, self::blueprintOfRecipe(self::TITANIUM_CARBIDE), 'reaction', runs: 1, quantity: 490);

        $this->split($quantumMicroprocessor, numberOfJobs: 3);

        self::assertSame([[11, 11, 0, 32], [11, 11, 1, 32], [10, 10, 2, 32]], $this->jobsOfProduct(self::QUANTUM_MICROPROCESSOR));
        self::assertSame([[1, 491, 0, null]], $this->jobsOfProduct(self::TITANIUM_CARBIDE));
    }

    #[Group('issue-73')]
    public function testManualSplitLeavesUnchangedTheMaterialStepWhoseJobByJobNeedIsTheSame(): void
    {
        $quantumMicroprocessor = $this->stepOfTheProject(self::QUANTUM_MICROPROCESSOR, self::blueprintOfRecipe(self::QUANTUM_MICROPROCESSOR), 'manufacturing', runs: 32, quantity: 32);
        $this->stepOfTheProject(self::PHENOLIC_COMPOSITES, self::blueprintOfRecipe(self::PHENOLIC_COMPOSITES), 'reaction', runs: 1, quantity: 173);
        $this->stepOfTheProject(self::NANOTRANSISTORS, self::blueprintOfRecipe(self::NANOTRANSISTORS), 'reaction', runs: 1, quantity: 58);

        $this->split($quantumMicroprocessor, numberOfJobs: 3);

        self::assertSame(
            [self::PHENOLIC_COMPOSITES => [[1, 174, 0, null]], self::NANOTRANSISTORS => [[1, 58, 0, null]]],
            [self::PHENOLIC_COMPOSITES => $this->jobsOfProduct(self::PHENOLIC_COMPOSITES), self::NANOTRANSISTORS => $this->jobsOfProduct(self::NANOTRANSISTORS)],
        );
    }

    #[Group('issue-73')]
    public function testManualSplitWhoseNewJobsNeedMoreThanTheProductionOfAMaterialStepRaisesItsRuns(): void
    {
        $scorpion = $this->stepOfTheProject(self::SCORPION, self::blueprintOfRecipe(self::SCORPION), 'manufacturing', runs: 5, quantity: 5);
        $this->stepOfTheProject(self::AUTO_INTEGRITY_PRESERVATION_SEAL, self::blueprintOfRecipe(self::AUTO_INTEGRITY_PRESERVATION_SEAL), 'manufacturing', runs: 168, quantity: 504);
        $this->stepOfTheProject(self::LIFE_SUPPORT_BACKUP_UNIT, self::blueprintOfRecipe(self::LIFE_SUPPORT_BACKUP_UNIT), 'manufacturing', runs: 84, quantity: 252);

        $this->split($scorpion, numberOfJobs: 5);

        self::assertSame(
            [self::AUTO_INTEGRITY_PRESERVATION_SEAL => [[169, 505, 0, null]], self::LIFE_SUPPORT_BACKUP_UNIT => [[85, 255, 0, null]]],
            [self::AUTO_INTEGRITY_PRESERVATION_SEAL => $this->jobsOfProduct(self::AUTO_INTEGRITY_PRESERVATION_SEAL), self::LIFE_SUPPORT_BACKUP_UNIT => $this->jobsOfProduct(self::LIFE_SUPPORT_BACKUP_UNIT)],
        );
    }

    #[Group('issue-73')]
    public function testManualSplitKeepsTheNeedOfTheOtherConsumerOfAMaterial(): void
    {
        $scorpion = $this->stepOfTheProject(self::SCORPION, self::blueprintOfRecipe(self::SCORPION), 'manufacturing', runs: 5, quantity: 5);
        $this->stepOfTheProject(self::NESTOR, self::blueprintOfRecipe(self::NESTOR), 'manufacturing', runs: 1, quantity: 1);
        $this->stepOfTheProject(self::AUTO_INTEGRITY_PRESERVATION_SEAL, self::blueprintOfRecipe(self::AUTO_INTEGRITY_PRESERVATION_SEAL), 'manufacturing', runs: 248, quantity: 742);
        $this->stepOfTheProject(self::LIFE_SUPPORT_BACKUP_UNIT, self::blueprintOfRecipe(self::LIFE_SUPPORT_BACKUP_UNIT), 'manufacturing', runs: 124, quantity: 371);

        $this->split($scorpion, numberOfJobs: 5);

        self::assertSame(
            [self::NESTOR => [[1, 1, 0, null]], self::AUTO_INTEGRITY_PRESERVATION_SEAL => [[248, 743, 0, null]], self::LIFE_SUPPORT_BACKUP_UNIT => [[125, 374, 0, null]]],
            [self::NESTOR => $this->jobsOfProduct(self::NESTOR), self::AUTO_INTEGRITY_PRESERVATION_SEAL => $this->jobsOfProduct(self::AUTO_INTEGRITY_PRESERVATION_SEAL), self::LIFE_SUPPORT_BACKUP_UNIT => $this->jobsOfProduct(self::LIFE_SUPPORT_BACKUP_UNIT)],
        );
    }

    #[Group('issue-73')]
    public function testManualSplitOfAConsumerWhoseMaterialStepProductIsMissingFromTheSdeIsRefusedWithoutChangingAnyStep(): void
    {
        $scorpion = $this->stepOfTheProject(self::SCORPION, self::blueprintOfRecipe(self::SCORPION), 'manufacturing', runs: 5, quantity: 5);
        $this->stepOfTheProject(self::AUTO_INTEGRITY_PRESERVATION_SEAL, self::blueprintOfRecipe(self::AUTO_INTEGRITY_PRESERVATION_SEAL), 'manufacturing', runs: 168, quantity: 504);
        $this->stepOfTheProject(self::LIFE_SUPPORT_BACKUP_UNIT, self::blueprintOfRecipe(self::LIFE_SUPPORT_BACKUP_UNIT), 'manufacturing', runs: 84, quantity: 252);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        try {
            $this->split(
                $scorpion,
                numberOfJobs: 5,
                entityManager: $entityManager,
                splitGroupMaterialNeeds: $this->splitGroupMaterialNeedsOnRecipes([self::blueprintOfRecipe(self::AUTO_INTEGRITY_PRESERVATION_SEAL)]),
            );
            self::fail('A consumer whose material step has no product in the SDE must not be split.');
        } catch (BadRequestHttpException $refusal) {
            self::assertSame('Product of blueprint 1057478 missing from the SDE', $refusal->getMessage());
        }

        self::assertSame(
            [[self::SCORPION, 5, 5, null], [self::AUTO_INTEGRITY_PRESERVATION_SEAL, 168, 504, null], [self::LIFE_SUPPORT_BACKUP_UNIT, 84, 252, null]],
            array_map(
                static fn (IndustryProjectStep $unchanged): array => [$unchanged->getProductTypeId(), $unchanged->getRuns(), $unchanged->getQuantity(), $unchanged->getSplitGroupId()],
                array_values($this->project->getSteps()->toArray()),
            ),
        );
    }

    #[Group('issue-73')]
    public function testManualSplitOfAConsumerInAStructureGivesItsMaterialStepsTheMaterialsOfEachNewJobWithTheStructureBonus(): void
    {
        $quantumMicroprocessor = $this->stepOfTheProject(self::QUANTUM_MICROPROCESSOR, self::blueprintOfRecipe(self::QUANTUM_MICROPROCESSOR), 'manufacturing', runs: 32, quantity: 32);
        $quantumMicroprocessor->setStructureConfig((new IndustryStructureConfig())->setName('Raitaru')->setStructureType('raitaru')->setSecurityType('highsec'));
        $this->stepOfTheProject(self::TITANIUM_CARBIDE, self::blueprintOfRecipe(self::TITANIUM_CARBIDE), 'reaction', runs: 1, quantity: 474);
        $this->stepOfTheProject(self::PHENOLIC_COMPOSITES, self::blueprintOfRecipe(self::PHENOLIC_COMPOSITES), 'reaction', runs: 1, quantity: 167);
        $this->stepOfTheProject(self::NANOTRANSISTORS, self::blueprintOfRecipe(self::NANOTRANSISTORS), 'reaction', runs: 1, quantity: 56);
        $this->stepOfTheProject(self::NONLINEAR_METAMATERIALS, self::blueprintOfRecipe(self::NONLINEAR_METAMATERIALS), 'reaction', runs: 1, quantity: 56);

        $this->split(
            $quantumMicroprocessor,
            numberOfJobs: 3,
            splitGroupMaterialNeeds: $this->splitGroupMaterialNeedsOnRecipesInAStructure(self::RAITARU_BASE_MATERIAL_BONUS, self::ADVANCED_COMPONENT_RIG_MATERIAL_BONUS),
        );

        self::assertSame(
            [self::TITANIUM_CARBIDE => [[1, 474, 0, null]], self::PHENOLIC_COMPOSITES => [[1, 169, 0, null]], self::NANOTRANSISTORS => [[1, 58, 0, null]], self::NONLINEAR_METAMATERIALS => [[1, 58, 0, null]]],
            [
                self::TITANIUM_CARBIDE => $this->jobsOfProduct(self::TITANIUM_CARBIDE),
                self::PHENOLIC_COMPOSITES => $this->jobsOfProduct(self::PHENOLIC_COMPOSITES),
                self::NANOTRANSISTORS => $this->jobsOfProduct(self::NANOTRANSISTORS),
                self::NONLINEAR_METAMATERIALS => $this->jobsOfProduct(self::NONLINEAR_METAMATERIALS),
            ],
        );
    }

    #[Group('issue-73')]
    public function testManualSplitOfACopyStepLeavesTheMaterialStepsOfItsBlueprintUnchanged(): void
    {
        $quantumMicroprocessorCopy = $this->stepOfTheProject(self::QUANTUM_MICROPROCESSOR, self::blueprintOfRecipe(self::QUANTUM_MICROPROCESSOR), 'copy', runs: 32, quantity: 32);
        $this->stepOfTheProject(self::TITANIUM_CARBIDE, self::blueprintOfRecipe(self::TITANIUM_CARBIDE), 'reaction', runs: 1, quantity: 490);

        $this->split($quantumMicroprocessorCopy, numberOfJobs: 3);

        self::assertSame([[11, 11, 0, 32], [11, 11, 1, 32], [10, 10, 2, 32]], $this->jobsOfProduct(self::QUANTUM_MICROPROCESSOR));
        self::assertSame([[1, 490, 0, null]], $this->jobsOfProduct(self::TITANIUM_CARBIDE));
    }

    public function testManualSplitGivesEachNewJobTheBlueprintProductActivityDepthSortOrderLevelsStructureAndMatchModeOfTheStep(): void
    {
        $step = $this->stepOfTheProject(self::FERNITE_ALLOY, self::FERNITE_ALLOY_FORMULA, 'reaction', runs: 7, quantity: 650)
            ->setDepth(3)
            ->setSortOrder(6)
            ->setMeLevel(2)
            ->setTeLevel(14)
            ->setStructureConfig((new IndustryStructureConfig())->setName('Tatara'))
            ->setJobMatchMode('manual');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $resources = $this->split($step, numberOfJobs: 2, entityManager: $entityManager);

        $newJob = $this->project->getSteps()->last();
        self::assertInstanceOf(IndustryProjectStep::class, $newJob);
        self::assertSame(
            [self::FERNITE_ALLOY_FORMULA, self::FERNITE_ALLOY, 'reaction', 3, 6, $step->getSplitGroupId(), 2, 14, 'Tatara', 'manual'],
            [
                $newJob->getBlueprintTypeId(),
                $newJob->getProductTypeId(),
                $newJob->getActivityType(),
                $newJob->getDepth(),
                $newJob->getSortOrder(),
                $newJob->getSplitGroupId(),
                $newJob->getMeLevel(),
                $newJob->getTeLevel(),
                $newJob->getStructureConfig()?->getName(),
                $newJob->getJobMatchMode(),
            ],
        );
        self::assertSame(
            [[4, 0], [3, 1]],
            array_map(static fn (ProjectStepResource $job): array => [$job->runs, $job->splitIndex], $resources),
        );
    }

    public function testManualSplitOfAStepOfAProjectOfAnotherPilotIsRefusedAsProjectNotFound(): void
    {
        $step = $this->stepOfTheProject(self::OXYGEN_FUEL_BLOCK, self::OXYGEN_FUEL_BLOCK_BLUEPRINT, 'manufacturing', runs: 4, quantity: 150);
        $this->project->setUser(new User());

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Project not found');

        $this->split($step, numberOfJobs: 2);
    }

    public function testManualSplitIntoMoreJobsThanRunsIsRefused(): void
    {
        $step = $this->stepOfTheProject(self::OXYGEN_FUEL_BLOCK, self::OXYGEN_FUEL_BLOCK_BLUEPRINT, 'manufacturing', runs: 3, quantity: 110);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Cannot split 3 runs into 4 jobs');

        $this->split($step, numberOfJobs: 4);
    }

    public function testManualSplitOfAJobOfASplitGroupIsRefused(): void
    {
        $job = $this->stepOfTheProject(self::FERNITE_ALLOY, self::FERNITE_ALLOY_FORMULA, 'reaction', runs: 5, quantity: 500)
            ->setSplitGroupId('3f6d2b81-7a4c-4e09-b5d3-8c1e9f2a6b47')
            ->setSplitIndex(1)
            ->setTotalGroupRuns(9);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Step is already split. Merge first before re-splitting.');

        $this->split($job, numberOfJobs: 2);
    }

    public function testManualSplitOfAStepOfAnotherProjectIsRefusedAsStepNotFound(): void
    {
        $stepOfAnotherProject = (new IndustryProjectStep())->setRuns(8);
        (new IndustryProject())->setUser($this->user)->addStep($stepOfAnotherProject);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Step not found');

        $this->split($stepOfAnotherProject, numberOfJobs: 2);
    }

    #[Group('issue-200')]
    public function testProjectIdThatIsNotAUuidIsNotFoundWithoutReadingTheProjectsNorTheSteps(): void
    {
        $projectRepository = $this->createMock(IndustryProjectRepository::class);
        $projectRepository->expects($this->never())->method('find');
        $stepRepository = $this->createMock(IndustryProjectStepRepository::class);
        $stepRepository->expects($this->never())->method('find');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Project not found');

        $this->splitAt(['id' => 'abc', 'stepId' => 'def'], $projectRepository, $stepRepository);
    }

    #[Group('issue-200')]
    public function testStepIdThatIsNotAUuidUnderAnOwnedProjectIsNotFoundWithoutReadingTheSteps(): void
    {
        $projectRepository = $this->createMock(IndustryProjectRepository::class);
        $projectRepository->expects($this->once())->method('find')->willReturn($this->project);
        $stepRepository = $this->createMock(IndustryProjectStepRepository::class);
        $stepRepository->expects($this->never())->method('find');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Step not found');

        $this->splitAt(['id' => '0192f0c4-7b1e-7a3c-9d2e-200000000001', 'stepId' => 'def'], $projectRepository, $stepRepository);
    }

    private function stepOfTheProject(int $productTypeId, int $blueprintTypeId, string $activityType, int $runs, int $quantity): IndustryProjectStep
    {
        $step = (new IndustryProjectStep())
            ->setProductTypeId($productTypeId)
            ->setBlueprintTypeId($blueprintTypeId)
            ->setActivityType($activityType)
            ->setRuns($runs)
            ->setQuantity($quantity)
            ->setDepth(1)
            ->setMeLevel(10)
            ->setTeLevel(20);
        $this->project->addStep($step);

        return $step;
    }

    private function split(IndustryProjectStep $step, int $numberOfJobs, ?EntityManagerInterface $entityManager = null, ?SplitGroupMaterialNeeds $splitGroupMaterialNeeds = null): array
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->user);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($this->project);
        $stepRepository = $this->createStub(IndustryProjectStepRepository::class);
        $stepRepository->method('find')->willReturn($step);
        $mapper = $this->createStub(IndustryResourceMapper::class);
        $mapper->method('stepToResource')->willReturnCallback(static function (IndustryProjectStep $job): ProjectStepResource {
            $resource = new ProjectStepResource();
            $resource->runs = $job->getRuns();
            $resource->splitIndex = $job->getSplitIndex();

            return $resource;
        });

        $input = new SplitStepInput();
        $input->numberOfJobs = $numberOfJobs;

        $processor = new SplitStepProcessor(
            $security,
            $projectRepository,
            $stepRepository,
            $this->activityProductRepositoryOfTheSde(),
            $mapper,
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $splitGroupMaterialNeeds ?? $this->splitGroupMaterialNeedsOnRecipes(),
        );
        return $processor->process($input, new Post(), ['id' => Uuid::v4()->toRfc4122(), 'stepId' => Uuid::v4()->toRfc4122()]);
    }

    private function splitAt(array $uriVariables, IndustryProjectRepository $projectRepository, IndustryProjectStepRepository $stepRepository): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->user);
        $input = new SplitStepInput();
        $input->numberOfJobs = 3;

        $processor = new SplitStepProcessor(
            $security,
            $projectRepository,
            $stepRepository,
            $this->activityProductRepositoryOfTheSde(),
            $this->createStub(IndustryResourceMapper::class),
            $this->createStub(EntityManagerInterface::class),
            $this->splitGroupMaterialNeedsOnRecipes(),
        );
        $processor->process($input, new Post(), $uriVariables);
    }

    private function activityProductRepositoryOfTheSde(): IndustryActivityProductRepository
    {
        $products = [
            self::OXYGEN_FUEL_BLOCK_BLUEPRINT.'-'.self::MANUFACTURING_ACTIVITY_ID => $this->activityProduct(self::OXYGEN_FUEL_BLOCK_BLUEPRINT, self::MANUFACTURING_ACTIVITY_ID, self::OXYGEN_FUEL_BLOCK, self::OXYGEN_FUEL_BLOCK_OUTPUT_PER_RUN),
            self::NOMAD_BLUEPRINT.'-'.self::MANUFACTURING_ACTIVITY_ID => $this->activityProduct(self::NOMAD_BLUEPRINT, self::MANUFACTURING_ACTIVITY_ID, self::NOMAD, self::NOMAD_OUTPUT_PER_RUN_READ_AS_IF_MANUFACTURED),
            self::FERNITE_ALLOY_FORMULA.'-'.self::REACTION_ACTIVITY_ID => $this->activityProduct(self::FERNITE_ALLOY_FORMULA, self::REACTION_ACTIVITY_ID, self::FERNITE_ALLOY, self::FERNITE_ALLOY_OUTPUT_PER_RUN),
            ...$this->recipeProductsByBlueprintAndActivity(),
        ];
        $repository = $this->createStub(IndustryActivityProductRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?IndustryActivityProduct => $products[($criteria['typeId'] ?? '').'-'.($criteria['activityId'] ?? '')] ?? null,
        );

        return $repository;
    }

    private function activityProduct(int $blueprintTypeId, int $activityId, int $productTypeId, int $outputPerRun): IndustryActivityProduct
    {
        return (new IndustryActivityProduct())
            ->setTypeId($blueprintTypeId)
            ->setActivityId($activityId)
            ->setProductTypeId($productTypeId)
            ->setQuantity($outputPerRun);
    }

    private function jobsOfTheProject(): array
    {
        $steps = $this->project->getSteps()->toArray();
        usort($steps, static fn (IndustryProjectStep $a, IndustryProjectStep $b): int => $a->getSplitIndex() <=> $b->getSplitIndex());

        return array_map(
            static fn (IndustryProjectStep $step): array => [$step->getRuns(), $step->getQuantity(), $step->getSplitIndex(), $step->getTotalGroupRuns()],
            $steps,
        );
    }

    private function jobsOfProduct(int $productTypeId): array
    {
        $steps = array_filter($this->project->getSteps()->toArray(), static fn (IndustryProjectStep $step): bool => $step->getProductTypeId() === $productTypeId);
        usort($steps, static fn (IndustryProjectStep $a, IndustryProjectStep $b): int => $a->getSplitIndex() <=> $b->getSplitIndex());

        return array_map(
            static fn (IndustryProjectStep $step): array => [$step->getRuns(), $step->getQuantity(), $step->getSplitIndex(), $step->getTotalGroupRuns()],
            $steps,
        );
    }
}
