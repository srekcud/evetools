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
use App\State\Processor\Industry\MergeStepsProcessor;
use App\State\Processor\Industry\SplitStepProcessor;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

#[CoversClass(MergeStepsProcessor::class)]
#[CoversClass(SplitStepProcessor::class)]
final class MergeStepsProcessorTest extends TestCase
{
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

        (new SplitStepProcessor($security, $projectRepository, $stepRepository, $activityProductRepository, $mapper, $entityManager))
            ->process($splitInput, new Post(), $uriVariables);
        (new MergeStepsProcessor($security, $projectRepository, $stepRepository, $mapper, $entityManager))
            ->process(null, new Post(), $uriVariables);

        self::assertSame(
            [[10, 990, 0, null, null]],
            array_map(
                static fn (IndustryProjectStep $remaining): array => [$remaining->getRuns(), $remaining->getQuantity(), $remaining->getSplitIndex(), $remaining->getSplitGroupId(), $remaining->getTotalGroupRuns()],
                array_values($project->getSteps()->toArray()),
            ),
        );
    }
}
