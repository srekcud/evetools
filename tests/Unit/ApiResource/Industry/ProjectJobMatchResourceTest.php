<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Industry;

use App\ApiResource\Industry\ProjectJobMatchResource;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectJobMatchResource::class)]
#[Group('issue-1246')]
final class ProjectJobMatchResourceTest extends TestCase
{
    public function testTheMatchSignalsOfAnAutomaticMatchAreExposedInTheirStoredOrder(): void
    {
        $match = $this->matchOnALine()->setMatchSignals(['intent', 'structure', 'exact_fit']);

        $resource = ProjectJobMatchResource::fromEntity($match);

        self::assertSame(['intent', 'structure', 'exact_fit'], $resource->matchSignals);
    }

    public function testAnEmptyListOfSignalsIsExposedAsAnEmptyList(): void
    {
        $resource = ProjectJobMatchResource::fromEntity($this->matchOnALine()->setMatchSignals([]));

        self::assertSame([], $resource->matchSignals);
    }

    public function testTheMatchSignalsOfAManualMatchAreExposedAsNull(): void
    {
        $resource = ProjectJobMatchResource::fromEntity($this->matchOnALine()->setManual(true));

        self::assertArrayHasKey('matchSignals', get_object_vars($resource));
        self::assertNull($resource->matchSignals);
    }

    #[Group('issue-1316')]
    public function testTheSuccessfulRunsOfTheMatchAreExposedAsTheyAre(): void
    {
        $withResult = ProjectJobMatchResource::fromEntity($this->matchOnALine()->setSuccessfulRuns(21));
        $withZeroResult = ProjectJobMatchResource::fromEntity($this->matchOnALine()->setSuccessfulRuns(0));

        self::assertSame(21, $withResult->successfulRuns);
        self::assertSame(0, $withZeroResult->successfulRuns);
    }

    #[Group('issue-1316')]
    public function testTheSuccessfulRunsOfAMatchWithoutResultAreExposedAsNull(): void
    {
        $resource = ProjectJobMatchResource::fromEntity($this->matchOnALine());

        self::assertArrayHasKey('successfulRuns', get_object_vars($resource));
        self::assertNull($resource->successfulRuns);
    }

    #[Group('issue-1326')]
    public function testTheStartDateOfTheMatchIsExposedInTheIsoFormat(): void
    {
        $match = $this->matchOnALine()->setStartDate(new \DateTimeImmutable('2026-11-22T10:00:00Z'));

        $resource = ProjectJobMatchResource::fromEntity($match);

        self::assertSame('2026-11-22T10:00:00+00:00', $resource->startDate);
    }

    #[Group('issue-1326')]
    public function testTheStartDateOfAMatchWithoutOneIsExposedAsNull(): void
    {
        $resource = ProjectJobMatchResource::fromEntity($this->matchOnALine());

        self::assertArrayHasKey('startDate', get_object_vars($resource));
        self::assertNull($resource->startDate);
    }

    private function matchOnALine(): IndustryProjectJobMatch
    {
        $line = (new IndustryProjectBomLine())->setTypeId(12005)->setTypeName('Ishtar')->setRequiredQuantity(5)->setIsJob(true)->setJobGroup('final')->setActivityType('manufacturing')->setRuns(5);
        $match = (new IndustryProjectJobMatch())->setEsiJobId(7_246_901)->setRuns(3)->setStatus('active')->setCost(1_482_350.25);
        $line->addJobMatch($match);

        return $match;
    }
}
