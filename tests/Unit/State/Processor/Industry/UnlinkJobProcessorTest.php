<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Delete;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepJobMatch;
use App\Entity\User;
use App\Repository\IndustryStepJobMatchRepository;
use App\State\Processor\Industry\UnlinkJobProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(UnlinkJobProcessor::class)]
class UnlinkJobProcessorTest extends TestCase
{
    private const int MANUFACTURING_BLUEPRINT_TYPE_ID = 1001;
    private const int DELIVERED_JOB_ID = 305;
    private const int SPEC_STEP_RUNS = 20;

    #[Group('issue-187')]
    public function testUnlinkingJobLinkedByHandLeavesStepInManualJobMatchMode(): void
    {
        $user = new User();
        $project = (new IndustryProject())->setUser($user);
        $stepS = (new IndustryProjectStep())
            ->setBlueprintTypeId(self::MANUFACTURING_BLUEPRINT_TYPE_ID)
            ->setActivityType('manufacturing')
            ->setRuns(self::SPEC_STEP_RUNS)
            ->setQuantity(self::SPEC_STEP_RUNS)
            ->setJobMatchMode('manual');
        $project->addStep($stepS);
        $linkedMatch = (new IndustryStepJobMatch())
            ->setEsiJobId(self::DELIVERED_JOB_ID)
            ->setRuns(10)
            ->setStatus('delivered')
            ->setCost(1_250_000.75);
        $stepS->addJobMatch($linkedMatch);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $matchRepository = $this->createStub(IndustryStepJobMatchRepository::class);
        $matchRepository->method('find')->willReturn($linkedMatch);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('remove')->with($linkedMatch);
        $entityManager->expects($this->once())->method('flush');

        (new UnlinkJobProcessor($security, $matchRepository, $entityManager))
            ->process(null, new Delete(), ['id' => Uuid::v4()->toRfc4122()]);

        self::assertCount(0, $stepS->getJobMatches());
        self::assertSame('manual', $stepS->getJobMatchMode());
        self::assertSame(self::SPEC_STEP_RUNS, $stepS->getRuns());
    }

    #[Group('issue-200')]
    public function testJobMatchIdThatIsNotAUuidIsNotFoundWithoutReadingTheJobMatchesNorRemovingAnything(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());
        $matchRepository = $this->createMock(IndustryStepJobMatchRepository::class);
        $matchRepository->expects($this->never())->method('find');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');
        $entityManager->expects($this->never())->method('flush');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Job match not found');

        (new UnlinkJobProcessor($security, $matchRepository, $entityManager))
            ->process(null, new Delete(), ['id' => 'm-1']);
    }
}
