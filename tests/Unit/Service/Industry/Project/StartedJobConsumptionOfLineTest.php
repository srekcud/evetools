<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Service\Industry\Project\StartedJobConsumption;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(StartedJobConsumption::class)]
#[Group('issue-1294')]
final class StartedJobConsumptionOfLineTest extends TestCase
{
    use BuildsChainBomLines;

    private const int CADMIUM = 16_647;
    private const int ZYDRINE = 39;
    private const int PHOTONIC_UNIT = 16_680;
    private const int LINE_PRODUCT = 1_294_010;

    private int $nextEsiJobId = 1_294_000;

    public function testStartedRunsOfALineConsumeTheShareOfEachNeedRoundedDown(): void
    {
        $line = $this->lineWithMatches(10, [[CachedIndustryJob::STATUS_ACTIVE, 3]]);

        $consumed = StartedJobConsumption::ofLine($line, [self::CADMIUM => 1_000, self::ZYDRINE => 250, self::PHOTONIC_UNIT => 7]);

        self::assertSame([self::CADMIUM => 300, self::ZYDRINE => 75, self::PHOTONIC_UNIT => 2], $consumed);
    }

    public function testStartedRunsBeyondTheRunsOfTheLineConsumeAtMostTheWholeNeed(): void
    {
        $line = $this->lineWithMatches(10, [[CachedIndustryJob::STATUS_ACTIVE, 7], [CachedIndustryJob::STATUS_DELIVERED, 6]]);

        $consumed = StartedJobConsumption::ofLine($line, [self::CADMIUM => 1_000, self::ZYDRINE => 250]);

        self::assertSame([self::CADMIUM => 1_000, self::ZYDRINE => 250], $consumed);
    }

    public function testCancelledAndRevertedMatchesAreNotStartedRuns(): void
    {
        $line = $this->lineWithMatches(10, [
            [CachedIndustryJob::STATUS_CANCELLED, 5],
            [CachedIndustryJob::STATUS_REVERTED, 2],
            [CachedIndustryJob::STATUS_PAUSED, 3],
        ]);

        $consumed = StartedJobConsumption::ofLine($line, [self::CADMIUM => 1_000]);

        self::assertSame([self::CADMIUM => 300], $consumed);
    }

    public function testALineOfZeroRunsConsumesNothingEvenWithAMatch(): void
    {
        $line = $this->lineWithMatches(0, [[CachedIndustryJob::STATUS_ACTIVE, 2]]);

        self::assertSame([], StartedJobConsumption::ofLine($line, [self::CADMIUM => 1_000]));
    }

    public function testStartedRunsWithoutKnownNeedsAreUnknownNotZero(): void
    {
        $line = $this->lineWithMatches(10, [[CachedIndustryJob::STATUS_ACTIVE, 4]]);

        self::assertNull(StartedJobConsumption::ofLine($line, null));
    }

    public function testNoStartedRunConsumesNothingWhetherTheNeedsAreKnownOrNot(): void
    {
        $lineWithoutMatch = $this->lineWithMatches(10, []);
        $lineWithCancelledMatch = $this->lineWithMatches(10, [[CachedIndustryJob::STATUS_CANCELLED, 10]]);

        self::assertSame([], StartedJobConsumption::ofLine($lineWithoutMatch, null));
        self::assertSame([], StartedJobConsumption::ofLine($lineWithCancelledMatch, [self::CADMIUM => 1_000]));
    }

    public function testOfLineGivesTheQuantitiesOfOfForTheSameLine(): void
    {
        $line = $this->lineWithMatches(10, [[CachedIndustryJob::STATUS_ACTIVE, 4]]);
        $needs = [self::CADMIUM => 1_000, self::ZYDRINE => 250];

        $ofLine = StartedJobConsumption::ofLine($line, $needs);
        $of = StartedJobConsumption::of([$line], [self::lineId($line) => $needs]);

        self::assertSame([self::CADMIUM => 400, self::ZYDRINE => 100], $ofLine);
        self::assertSame($ofLine, $of->quantitiesByTypeId());
    }

    private function lineWithMatches(int $lineRuns, array $matches): IndustryProjectBomLine
    {
        $line = $this->jobLine(self::LINE_PRODUCT, 'Product '.self::LINE_PRODUCT, 'component', null, $lineRuns);
        foreach ($matches as [$status, $runs]) {
            $line->addJobMatch(
                (new IndustryProjectJobMatch())
                    ->setEsiJobId(++$this->nextEsiJobId)
                    ->setStatus($status)
                    ->setRuns($runs),
            );
        }

        return $line;
    }
}
