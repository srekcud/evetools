<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Delete;
use App\ApiResource\Industry\ProjectJobMatchResource;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Repository\IndustryProjectJobMatchRepository;
use App\Service\Industry\Project\DerivedContributionService;
use App\State\Processor\Industry\UnlinkJobProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(UnlinkJobProcessor::class)]
#[Group('issue-362')]
#[Group('issue-363')]
final class UnlinkJobProcessorTest extends TestCase
{
    private const int DELIVERED_JOB_ID = 7_362_305;
    private const int ACTIVE_JOB_ID = 7_362_306;
    private const string PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-362000000001';
    private const string DELIVERED_MATCH_ID = '0192f0c4-7b1e-7a3c-9d2e-362000000031';
    private const string ACTIVE_MATCH_ID = '0192f0c4-7b1e-7a3c-9d2e-362000000032';
    private const string UNKNOWN_MATCH_ID = '0192f0c4-7b1e-7a3c-9d2e-362000000099';

    private array $entityManagerCalls = [];

    private array $refreshedLines = [];

    public function testJobMatchDesignatedByTheResourceIsRemovedFromItsLineThenFlushedRatherThanTheJobMatchOfTheUriVariables(): void
    {
        $ishtarLine = $this->ishtarLine();
        $deliveredMatch = (new IndustryProjectJobMatch())->setEsiJobId(self::DELIVERED_JOB_ID)->setRuns(3)->setStatus('delivered')->setManual(true);
        $activeMatch = (new IndustryProjectJobMatch())->setEsiJobId(self::ACTIVE_JOB_ID)->setRuns(2)->setStatus('active');
        $ishtarLine->addJobMatch($deliveredMatch);
        $ishtarLine->addJobMatch($activeMatch);

        $this->processorFinding([self::DELIVERED_MATCH_ID => $deliveredMatch, self::ACTIVE_MATCH_ID => $activeMatch])
            ->process($this->jobMatchResource(self::DELIVERED_MATCH_ID), new Delete(), ['projectId' => self::PROJECT_ID, 'id' => self::ACTIVE_MATCH_ID]);

        self::assertSame([['remove', $deliveredMatch], ['flush']], $this->entityManagerCalls);
        self::assertSame(
            [[self::ACTIVE_JOB_ID], 2],
            [array_values(array_map(static fn (IndustryProjectJobMatch $match): int => $match->getEsiJobId(), $ishtarLine->getJobMatches()->toArray())), $ishtarLine->getMatchedRuns()],
        );
        self::assertSame([[$ishtarLine->getId()->toRfc4122(), 2]], $this->refreshedLines);
    }

    public function testResourceDesignatingNoKnownJobMatchIsNotFoundWithoutRemovingNorFlushing(): void
    {
        $ishtarLine = $this->ishtarLine();
        $deliveredMatch = (new IndustryProjectJobMatch())->setEsiJobId(self::DELIVERED_JOB_ID)->setRuns(3);
        $ishtarLine->addJobMatch($deliveredMatch);
        $processor = $this->processorFinding([self::DELIVERED_MATCH_ID => $deliveredMatch]);

        try {
            $processor->process($this->jobMatchResource(self::UNKNOWN_MATCH_ID), new Delete(), ['projectId' => self::PROJECT_ID, 'id' => self::DELIVERED_MATCH_ID]);
            self::fail('A resource designating no known job match must be answered as not found');
        } catch (NotFoundHttpException $notFound) {
            self::assertSame('Job match not found', $notFound->getMessage());
        }

        self::assertSame([], $this->entityManagerCalls);
        self::assertCount(1, $ishtarLine->getJobMatches());
        self::assertSame([], $this->refreshedLines);
    }

    private function ishtarLine(): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())
            ->setTypeId(12005)
            ->setTypeName('Ishtar')
            ->setRequiredQuantity(5)
            ->setIsJob(true)
            ->setJobGroup('final')
            ->setActivityType('manufacturing')
            ->setRuns(5);
    }

    private function jobMatchResource(string $matchId): ProjectJobMatchResource
    {
        $resource = new ProjectJobMatchResource();
        $resource->id = $matchId;

        return $resource;
    }

    private function processorFinding(array $matchesById): UnlinkJobProcessor
    {
        $matchRepository = $this->createStub(IndustryProjectJobMatchRepository::class);
        $matchRepository->method('find')->willReturnCallback(
            static fn (mixed $id): ?IndustryProjectJobMatch => $matchesById[(string) $id] ?? null,
        );
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('remove')->willReturnCallback(function (object $entity): void {
            $this->entityManagerCalls[] = ['remove', $entity];
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            $this->entityManagerCalls[] = ['flush'];
        });

        $derivedContributionService = $this->createStub(DerivedContributionService::class);
        $derivedContributionService->method('refreshLine')->willReturnCallback(function (IndustryProjectBomLine $line): void {
            $this->refreshedLines[] = [$line->getId()->toRfc4122(), $line->getMatchedRuns()];
        });

        return new UnlinkJobProcessor($entityManager, $matchRepository, $derivedContributionService);
    }
}
