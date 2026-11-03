<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Delete;
use App\ApiResource\Industry\ProjectStepResource;
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(UnlinkJobProcessor::class)]
class UnlinkJobProcessorTest extends TestCase
{
    private const int MANUFACTURING_BLUEPRINT_TYPE_ID = 1001;
    private const int DELIVERED_JOB_ID = 305;
    private const int ACTIVE_JOB_ID = 306;
    private const int SPEC_STEP_RUNS = 20;
    private const string DELIVERED_MATCH_ID = '0192f0c4-7b1e-7a3c-9d2e-206000000031';
    private const string ACTIVE_MATCH_ID = '0192f0c4-7b1e-7a3c-9d2e-206000000032';
    private const string UNKNOWN_MATCH_ID = '0192f0c4-7b1e-7a3c-9d2e-206000000099';

    private array $entityManagerCalls = [];

    #[Group('issue-187')]
    public function testUnlinkingJobLinkedByHandLeavesStepInManualJobMatchMode(): void
    {
        $stepS = $this->manualStep();
        $linkedMatch = $this->jobMatch(self::DELIVERED_JOB_ID, runs: 10, status: 'delivered', cost: 1_250_000.75);
        $stepS->addJobMatch($linkedMatch);

        $this->processorFinding([self::DELIVERED_MATCH_ID => $linkedMatch])
            ->process($this->jobMatchResource(self::DELIVERED_MATCH_ID), new Delete(), ['id' => self::DELIVERED_MATCH_ID]);

        self::assertCount(0, $stepS->getJobMatches());
        self::assertSame('manual', $stepS->getJobMatchMode());
        self::assertSame(self::SPEC_STEP_RUNS, $stepS->getRuns());
        self::assertSame([['remove', $linkedMatch], ['flush']], $this->entityManagerCalls);
    }

    #[Group('issue-206')]
    public function testJobMatchDesignatedByTheResourceIdIsUnlinkedThenFlushedRatherThanTheJobMatchOfTheUriVariables(): void
    {
        $stepS = $this->manualStep();
        $deliveredMatch = $this->jobMatch(self::DELIVERED_JOB_ID, runs: 10, status: 'delivered', cost: 1_250_000.75);
        $activeMatch = $this->jobMatch(self::ACTIVE_JOB_ID, runs: 7, status: 'active', cost: 880_400.5);
        $stepS->addJobMatch($deliveredMatch);
        $stepS->addJobMatch($activeMatch);

        $this->processorFinding([self::DELIVERED_MATCH_ID => $deliveredMatch, self::ACTIVE_MATCH_ID => $activeMatch])
            ->process($this->jobMatchResource(self::DELIVERED_MATCH_ID), new Delete(), ['id' => self::ACTIVE_MATCH_ID]);

        self::assertSame([['remove', $deliveredMatch], ['flush']], $this->entityManagerCalls);
        self::assertSame([self::ACTIVE_JOB_ID], array_map(static fn (IndustryStepJobMatch $match): int => $match->getEsiJobId(), array_values($stepS->getJobMatches()->toArray())));
    }

    #[Group('issue-206')]
    public function testResourceDesignatingNoKnownJobMatchIsNotFoundWithoutRemovingNorFlushing(): void
    {
        $stepS = $this->manualStep();
        $deliveredMatch = $this->jobMatch(self::DELIVERED_JOB_ID, runs: 10, status: 'delivered', cost: 1_250_000.75);
        $stepS->addJobMatch($deliveredMatch);
        $processor = $this->processorFinding([self::DELIVERED_MATCH_ID => $deliveredMatch]);

        try {
            $processor->process($this->jobMatchResource(self::UNKNOWN_MATCH_ID), new Delete(), ['id' => self::DELIVERED_MATCH_ID]);
            self::fail('A resource designating no known job match must be answered as not found');
        } catch (NotFoundHttpException $notFound) {
            self::assertSame('Job match not found', $notFound->getMessage());
        }

        self::assertSame([], $this->entityManagerCalls);
        self::assertCount(1, $stepS->getJobMatches());
    }

    private function manualStep(): IndustryProjectStep
    {
        $step = (new IndustryProjectStep())
            ->setBlueprintTypeId(self::MANUFACTURING_BLUEPRINT_TYPE_ID)
            ->setActivityType('manufacturing')
            ->setRuns(self::SPEC_STEP_RUNS)
            ->setQuantity(self::SPEC_STEP_RUNS)
            ->setJobMatchMode('manual');
        (new IndustryProject())->setUser(new User())->addStep($step);

        return $step;
    }

    private function jobMatch(int $esiJobId, int $runs, string $status, float $cost): IndustryStepJobMatch
    {
        return (new IndustryStepJobMatch())
            ->setEsiJobId($esiJobId)
            ->setRuns($runs)
            ->setStatus($status)
            ->setCost($cost);
    }

    private function jobMatchResource(string $matchId): ProjectStepResource
    {
        $resource = new ProjectStepResource();
        $resource->id = $matchId;

        return $resource;
    }

    private function processorFinding(array $matchesById): UnlinkJobProcessor
    {
        $matchRepository = $this->createStub(IndustryStepJobMatchRepository::class);
        $matchRepository->method('find')->willReturnCallback(
            static fn (mixed $id): ?IndustryStepJobMatch => $matchesById[(string) $id] ?? null,
        );
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('remove')->willReturnCallback(function (object $entity): void {
            $this->entityManagerCalls[] = ['remove', $entity];
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            $this->entityManagerCalls[] = ['flush'];
        });

        return new UnlinkJobProcessor($entityManager, $matchRepository);
    }
}
