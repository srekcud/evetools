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
use App\Repository\Sde\IndustryActivityProductRepository;
use App\State\Processor\Industry\SplitStepProcessor;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SplitStepProcessor::class)]
final class SplitStepProcessorTest extends TestCase
{
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

    private function split(IndustryProjectStep $step, int $numberOfJobs, ?EntityManagerInterface $entityManager = null): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->user);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($this->project);
        $stepRepository = $this->createStub(IndustryProjectStepRepository::class);
        $stepRepository->method('find')->willReturn($step);
        $mapper = $this->createStub(IndustryResourceMapper::class);
        $mapper->method('stepToResource')->willReturn(new ProjectStepResource());

        $input = new SplitStepInput();
        $input->numberOfJobs = $numberOfJobs;

        $processor = new SplitStepProcessor(
            $security,
            $projectRepository,
            $stepRepository,
            $this->activityProductRepositoryOfTheSde(),
            $mapper,
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
        );
        $processor->process($input, new Post(), ['id' => Uuid::v4()->toRfc4122(), 'stepId' => Uuid::v4()->toRfc4122()]);
    }

    private function activityProductRepositoryOfTheSde(): IndustryActivityProductRepository
    {
        $products = [
            self::OXYGEN_FUEL_BLOCK_BLUEPRINT.'-'.self::MANUFACTURING_ACTIVITY_ID => $this->activityProduct(self::OXYGEN_FUEL_BLOCK_BLUEPRINT, self::MANUFACTURING_ACTIVITY_ID, self::OXYGEN_FUEL_BLOCK, self::OXYGEN_FUEL_BLOCK_OUTPUT_PER_RUN),
            self::NOMAD_BLUEPRINT.'-'.self::MANUFACTURING_ACTIVITY_ID => $this->activityProduct(self::NOMAD_BLUEPRINT, self::MANUFACTURING_ACTIVITY_ID, self::NOMAD, self::NOMAD_OUTPUT_PER_RUN_READ_AS_IF_MANUFACTURED),
            self::FERNITE_ALLOY_FORMULA.'-'.self::REACTION_ACTIVITY_ID => $this->activityProduct(self::FERNITE_ALLOY_FORMULA, self::REACTION_ACTIVITY_ID, self::FERNITE_ALLOY, self::FERNITE_ALLOY_OUTPUT_PER_RUN),
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
}
