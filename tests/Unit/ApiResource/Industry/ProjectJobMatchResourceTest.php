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

    private function matchOnALine(): IndustryProjectJobMatch
    {
        $line = (new IndustryProjectBomLine())->setTypeId(12005)->setTypeName('Ishtar')->setRequiredQuantity(5)->setIsJob(true)->setJobGroup('final')->setActivityType('manufacturing')->setRuns(5);
        $match = (new IndustryProjectJobMatch())->setEsiJobId(7_246_901)->setRuns(3)->setStatus('active')->setCost(1_482_350.25);
        $line->addJobMatch($match);

        return $match;
    }
}
