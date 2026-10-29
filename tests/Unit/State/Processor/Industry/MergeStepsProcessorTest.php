<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Industry\ProjectStepResource;
use App\ApiResource\Input\Industry\SplitStepInput;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Service\Industry\SplitGroupMaterialNeeds;
use App\Service\Industry\SplitGroupQuantities;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\State\Processor\Industry\MergeStepsProcessor;
use App\State\Processor\Industry\SplitStepProcessor;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(MergeStepsProcessor::class)]
#[CoversClass(SplitStepProcessor::class)]
#[CoversClass(SplitGroupMaterialNeeds::class)]
#[CoversClass(SplitGroupQuantities::class)]
final class MergeStepsProcessorTest extends TestCase
{
    use BuildsSplitGroupMaterialNeeds;

    private const int FERNITE_ALLOY = 16656;
    private const int FERNITE_ALLOY_FORMULA = 17960;
    private const int FERNITE_ALLOY_OUTPUT_PER_RUN = 100;
    private const int REACTION_ACTIVITY_ID = 11;

    #[Group('issue-112')]
    public function testMergingAManuallySplitGroupGivesBackTheRunsAndTheNeedOfTheStep(): void
    {
        $user = new User();
        $project = (new IndustryProject())->setUser($user);
        $step = (new IndustryProjectStep())
            ->setProductTypeId(self::FERNITE_ALLOY)
            ->setBlueprintTypeId(self::FERNITE_ALLOY_FORMULA)
            ->setActivityType('reaction')
            ->setRuns(10)
            ->setQuantity(990)
            ->setDepth(1)
            ->setMeLevel(0)
            ->setTeLevel(0);
        $project->addStep($step);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($project);
        $stepRepository = $this->createStub(IndustryProjectStepRepository::class);
        $stepRepository->method('find')->willReturn($step);
        $mapper = $this->createStub(IndustryResourceMapper::class);
        $mapper->method('stepToResource')->willReturn(new ProjectStepResource());
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $activityProductRepository = $this->createStub(IndustryActivityProductRepository::class);
        $activityProductRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?IndustryActivityProduct => ($criteria['typeId'] ?? null) === self::FERNITE_ALLOY_FORMULA && ($criteria['activityId'] ?? null) === self::REACTION_ACTIVITY_ID
                ? (new IndustryActivityProduct())
                    ->setTypeId(self::FERNITE_ALLOY_FORMULA)
                    ->setActivityId(self::REACTION_ACTIVITY_ID)
                    ->setProductTypeId(self::FERNITE_ALLOY)
                    ->setQuantity(self::FERNITE_ALLOY_OUTPUT_PER_RUN)
                : null,
        );
        $uriVariables = ['id' => Uuid::v4()->toRfc4122(), 'stepId' => Uuid::v4()->toRfc4122()];
        $splitInput = new SplitStepInput();
        $splitInput->numberOfJobs = 3;

        (new SplitStepProcessor($security, $projectRepository, $stepRepository, $activityProductRepository, $mapper, $entityManager, $this->splitGroupMaterialNeedsOnRecipes()))
            ->process($splitInput, new Post(), $uriVariables);
        (new MergeStepsProcessor($security, $projectRepository, $stepRepository, $mapper, $entityManager, $this->splitGroupMaterialNeedsOnRecipes()))
            ->process(null, new Post(), $uriVariables);

        self::assertSame(
            [[10, 990, 0, null, null]],
            array_map(
                static fn (IndustryProjectStep $remaining): array => [$remaining->getRuns(), $remaining->getQuantity(), $remaining->getSplitIndex(), $remaining->getSplitGroupId(), $remaining->getTotalGroupRuns()],
                array_values($project->getSteps()->toArray()),
            ),
        );
    }

    #[Group('issue-73')]
    public function testMergingASplitConsumerGivesItsMaterialStepTheMaterialsOfTheSingleJob(): void
    {
        $project = (new IndustryProject())->setUser(new User());
        $firstJob = $this->stepOf($project, self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 11, quantity: 11, splitGroupId: 'quantum-microprocessor', splitIndex: 0, totalGroupRuns: 32);
        $this->stepOf($project, self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 11, quantity: 11, splitGroupId: 'quantum-microprocessor', splitIndex: 1, totalGroupRuns: 32);
        $this->stepOf($project, self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 10, quantity: 10, splitGroupId: 'quantum-microprocessor', splitIndex: 2, totalGroupRuns: 32);
        $this->stepOf($project, self::TITANIUM_CARBIDE, 'reaction', runs: 1, quantity: 491);

        $this->merge($project, $firstJob);

        self::assertSame(
            [self::QUANTUM_MICROPROCESSOR => [[32, 32, 0, null]], self::TITANIUM_CARBIDE => [[1, 490, 0, null]]],
            [self::QUANTUM_MICROPROCESSOR => $this->jobsOfProduct($project, self::QUANTUM_MICROPROCESSOR), self::TITANIUM_CARBIDE => $this->jobsOfProduct($project, self::TITANIUM_CARBIDE)],
        );
    }

    #[Group('issue-73')]
    public function testMergingASplitConsumerLowersTheRunsOfAMaterialStepToItsNewNeed(): void
    {
        $project = (new IndustryProject())->setUser(new User());
        $firstJob = $this->stepOf($project, self::SCORPION, 'manufacturing', runs: 1, quantity: 1, splitGroupId: 'scorpion', splitIndex: 0, totalGroupRuns: 5);
        foreach ([1, 2, 3, 4] as $splitIndex) {
            $this->stepOf($project, self::SCORPION, 'manufacturing', runs: 1, quantity: 1, splitGroupId: 'scorpion', splitIndex: $splitIndex, totalGroupRuns: 5);
        }
        $this->stepOf($project, self::AUTO_INTEGRITY_PRESERVATION_SEAL, 'manufacturing', runs: 169, quantity: 505);
        $this->stepOf($project, self::LIFE_SUPPORT_BACKUP_UNIT, 'manufacturing', runs: 85, quantity: 255);

        $this->merge($project, $firstJob);

        self::assertSame(
            [self::AUTO_INTEGRITY_PRESERVATION_SEAL => [[168, 504, 0, null]], self::LIFE_SUPPORT_BACKUP_UNIT => [[84, 252, 0, null]]],
            [self::AUTO_INTEGRITY_PRESERVATION_SEAL => $this->jobsOfProduct($project, self::AUTO_INTEGRITY_PRESERVATION_SEAL), self::LIFE_SUPPORT_BACKUP_UNIT => $this->jobsOfProduct($project, self::LIFE_SUPPORT_BACKUP_UNIT)],
        );
    }

    private function stepOf(IndustryProject $project, int $productTypeId, string $activityType, int $runs, int $quantity, ?string $splitGroupId = null, int $splitIndex = 0, ?int $totalGroupRuns = null): IndustryProjectStep
    {
        $step = (new IndustryProjectStep())
            ->setProductTypeId($productTypeId)
            ->setBlueprintTypeId(self::blueprintOfRecipe($productTypeId))
            ->setActivityType($activityType)
            ->setRuns($runs)
            ->setQuantity($quantity)
            ->setDepth(1)
            ->setMeLevel($activityType === 'reaction' ? 0 : 10)
            ->setTeLevel($activityType === 'reaction' ? 0 : 20)
            ->setSplitGroupId($splitGroupId)
            ->setSplitIndex($splitIndex)
            ->setTotalGroupRuns($totalGroupRuns);
        $project->addStep($step);

        return $step;
    }

    #[Group('issue-200')]
    public function testStepIdThatIsNotAUuidUnderAnOwnedProjectIsNotFoundWithoutReadingTheSteps(): void
    {
        $user = new User();
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn((new IndustryProject())->setUser($user));
        $stepRepository = $this->createMock(IndustryProjectStepRepository::class);
        $stepRepository->expects($this->never())->method('find');
        $processor = new MergeStepsProcessor(
            $security,
            $projectRepository,
            $stepRepository,
            $this->createStub(IndustryResourceMapper::class),
            $this->createStub(EntityManagerInterface::class),
            $this->splitGroupMaterialNeedsOnRecipes(),
        );

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Step not found');

        $processor->process(null, new Post(), ['id' => '0192f0c4-7b1e-7a3c-9d2e-200000000001', 'stepId' => 'split-2']);
    }

    private function merge(IndustryProject $project, IndustryProjectStep $jobOfTheSplitGroup): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($project->getUser());
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($project);
        $stepRepository = $this->createStub(IndustryProjectStepRepository::class);
        $stepRepository->method('find')->willReturn($jobOfTheSplitGroup);
        $mapper = $this->createStub(IndustryResourceMapper::class);
        $mapper->method('stepToResource')->willReturn(new ProjectStepResource());

        (new MergeStepsProcessor($security, $projectRepository, $stepRepository, $mapper, $this->createStub(EntityManagerInterface::class), $this->splitGroupMaterialNeedsOnRecipes()))
            ->process(null, new Post(), ['id' => Uuid::v4()->toRfc4122(), 'stepId' => Uuid::v4()->toRfc4122()]);
    }

    private function jobsOfProduct(IndustryProject $project, int $productTypeId): array
    {
        $steps = array_filter($project->getSteps()->toArray(), static fn (IndustryProjectStep $step): bool => $step->getProductTypeId() === $productTypeId);
        usort($steps, static fn (IndustryProjectStep $a, IndustryProjectStep $b): int => $a->getSplitIndex() <=> $b->getSplitIndex());

        return array_map(
            static fn (IndustryProjectStep $step): array => [$step->getRuns(), $step->getQuantity(), $step->getSplitIndex(), $step->getTotalGroupRuns()],
            $steps,
        );
    }
}
