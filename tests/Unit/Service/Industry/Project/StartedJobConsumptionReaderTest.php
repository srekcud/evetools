<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\User;
use App\Industry\Application\PlannedProduction;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\Plan;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Repository\IndustryProjectBomLineRepository;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\ProjectJobMaterialNeedsLoader;
use App\Service\Industry\Project\StartedJobConsumptionReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(StartedJobConsumptionReader::class)]
#[Group('issue-1140')]
final class StartedJobConsumptionReaderTest extends TestCase
{
    use BuildsChainBomLines;

    private const int CADMIUM = 16_647;
    private const int SILICON = 16_636;
    private const int REACTION_PRODUCT = 1_140_101;
    private const int REACTION_RUNS = 12;
    private const int CADMIUM_NEED = 1_260;
    private const int SILICON_NEED = 840;

    public function testPlanIsNotLoadedWhenNoJobOfTheProjectIsStarted(): void
    {
        $project = $this->project();
        $line = $this->reactionLine();
        $line->addJobMatch($this->match(CachedIndustryJob::STATUS_CANCELLED, 12, 1_140_201));
        $unmatchedLine = $this->jobLine(self::REACTION_PRODUCT + 1, 'Other reaction', 'final', null, 5);
        $service = $this->createMock(IndustryProjectService::class);
        $service->expects(self::never())->method('planOf');

        $consumption = $this->readerOver([$line, $unmatchedLine], $service)->of($project);

        self::assertFalse($consumption->isUnknown());
        self::assertSame(0, $consumption->consumedOf(self::CADMIUM));
    }

    public function testConsumptionFollowsTheStartedRunsOnTheNeedsOfThePlan(): void
    {
        $project = $this->project();
        $line = $this->reactionLine();
        $line->addJobMatch($this->match(CachedIndustryJob::STATUS_ACTIVE, 9, 1_140_202));
        $service = $this->createMock(IndustryProjectService::class);
        $service->expects(self::once())->method('planOf')->with($project, $project->getOwner())->willReturn($this->planOfReaction());

        $consumption = $this->readerOver([$line], $service)->of($project);

        self::assertFalse($consumption->isUnknown());
        self::assertSame([945, 630], [$consumption->consumedOf(self::CADMIUM), $consumption->consumedOf(self::SILICON)]);
    }

    public function testConsumptionIsUnknownWhenThePlanOfTheOwnerCannotBeComputed(): void
    {
        $project = $this->project();
        $line = $this->reactionLine();
        $line->addJobMatch($this->match(CachedIndustryJob::STATUS_DELIVERED, 12, 1_140_203));
        $service = $this->createStub(IndustryProjectService::class);
        $service->method('planOf')->willThrowException(new \RuntimeException('No blueprint or reaction found for product type ID 1140101'));

        $consumption = $this->readerOver([$line], $service)->of($project);

        self::assertTrue($consumption->isUnknown());
    }

    private function readerOver(array $jobLines, IndustryProjectService $service): StartedJobConsumptionReader
    {
        $repository = $this->createStub(IndustryProjectBomLineRepository::class);
        $repository->method('findJobLinesWithMatchesByProject')->willReturn($jobLines);

        return new StartedJobConsumptionReader($repository, new ProjectJobMaterialNeedsLoader($service));
    }

    private function project(): IndustryProject
    {
        return (new IndustryProject())->setOwner(new User());
    }

    private function reactionLine(): IndustryProjectBomLine
    {
        return $this->jobLine(self::REACTION_PRODUCT, 'Reaction product', 'final', null, self::REACTION_RUNS, 'reaction', 0, 0);
    }

    private function planOfReaction(): PlannedProduction
    {
        $job = new Job(
            self::REACTION_PRODUCT,
            ActivityKind::Reaction,
            new Runs(self::REACTION_RUNS),
            new MaterialEfficiency(0),
            new TimeEfficiency(0),
            [self::CADMIUM => new Quantity(self::CADMIUM_NEED), self::SILICON => new Quantity(self::SILICON_NEED)],
        );

        return new PlannedProduction(new Plan([$job], [], []), []);
    }

    private function match(string $status, int $runs, int $esiJobId): IndustryProjectJobMatch
    {
        return (new IndustryProjectJobMatch())->setEsiJobId($esiJobId)->setStatus($status)->setRuns($runs);
    }
}
