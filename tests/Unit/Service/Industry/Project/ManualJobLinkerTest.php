<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Enum\IndustryActivityType;
use App\Service\Industry\Project\ManualJobLinker;
use App\Tests\Unit\State\Processor\Industry\BuildsManualJobLinker;
use App\Tests\Unit\State\Processor\Industry\TracksJobsAndPurchasesOnIshtarBom;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ManualJobLinker::class)]
#[Group('issue-1248')]
#[AllowMockObjectsWithoutExpectations]
final class ManualJobLinkerTest extends TestCase
{
    use TracksJobsAndPurchasesOnIshtarBom;
    use BuildsManualJobLinker;

    private const int ISHTAR_JOB_ID = 7_248_101;
    private const int RAM_JOB_ID = 7_248_102;
    private const int INVENTION_JOB_ID = 7_248_103;
    private const int CANCELLED_JOB_ID = 7_248_104;
    private const int REVERTED_JOB_ID = 7_248_105;
    private const int LINKED_ELSEWHERE_JOB_ID = 7_248_106;
    private const int SHARED_JOB_ID = 7_250_101;
    private const int SHARED_JOB_RUNS = 20;
    private const float SHARED_JOB_COST = 1_000_000.0;
    private const float DEFAULT_JOB_COST = 1_250_000.5;

    protected function setUp(): void
    {
        $this->setUpIshtarProject();
        $this->givenShare(self::LINKED_ELSEWHERE_JOB_ID, $this->ramLine, 1);
    }

    public function testItLinksTheJobToTheLineAsAManualLinkAndRefreshesTheLine(): void
    {
        $job = $this->job(self::ISHTAR_JOB_ID, $this->ownerAltCharacter, self::ISHTAR_BLUEPRINT_TYPE_ID, 3);

        $match = $this->manualJobLinker()->link($job, $this->ishtarLine);

        self::assertSame([3, 0], [$this->ishtarLine->getMatchedRuns(), $this->ishtarLine->getFulfilledQuantity()]);
        self::assertSame([[$this->ishtarLine->getId()->toRfc4122(), 0, 3]], $this->refreshedLines);
        self::assertSame([$match], $this->ishtarLine->getJobMatches()->toArray());
        self::assertSame(
            [self::ISHTAR_JOB_ID, 3, 'Aria Alt', true, $this->ishtarLine],
            [$match->getEsiJobId(), $match->getRuns(), $match->getCharacterName(), $match->isManual(), $match->getBomLine()],
        );
    }

    public function testAJobOfAnotherActivityThanTheLineIsRefusedWithoutLink(): void
    {
        $job = $this->job(self::INVENTION_JOB_ID, $this->ownerMainCharacter, self::ISHTAR_BLUEPRINT_TYPE_ID, 1, IndustryActivityType::Invention->value);

        self::assertSame(
            '400 Job activity does not match BOM line activity',
            $this->outcomeOf(fn () => $this->manualJobLinker()->link($job, $this->ishtarLine)),
        );
        $this->assertNothingLinked();
    }

    public function testAJobOnAnotherBlueprintThanTheProducerOfTheLineIsRefusedWithoutLink(): void
    {
        $job = $this->job(self::RAM_JOB_ID, $this->ownerMainCharacter, self::RAM_BLUEPRINT_TYPE_ID, 4);

        self::assertSame(
            '400 Job blueprint does not match BOM line blueprint',
            $this->outcomeOf(fn () => $this->manualJobLinker()->link($job, $this->ishtarLine)),
        );
        $this->assertNothingLinked();
    }

    public function testACancelledOrRevertedJobIsRefusedWithoutLink(): void
    {
        $cancelled = $this->job(self::CANCELLED_JOB_ID, $this->ownerMainCharacter, self::ISHTAR_BLUEPRINT_TYPE_ID, 2, status: CachedIndustryJob::STATUS_CANCELLED);
        $reverted = $this->job(self::REVERTED_JOB_ID, $this->ownerMainCharacter, self::ISHTAR_BLUEPRINT_TYPE_ID, 2, status: CachedIndustryJob::STATUS_REVERTED);

        self::assertSame(
            ['400 ESI job 7248104 has no output', '400 ESI job 7248105 has no output'],
            [
                $this->outcomeOf(fn () => $this->manualJobLinker()->link($cancelled, $this->ishtarLine)),
                $this->outcomeOf(fn () => $this->manualJobLinker()->link($reverted, $this->ishtarLine)),
            ],
        );
        $this->assertNothingLinked();
    }

    public function testAJobAlreadyLinkedIsRefusedWithoutLink(): void
    {
        $job = $this->job(self::LINKED_ELSEWHERE_JOB_ID, $this->ownerMainCharacter, self::ISHTAR_BLUEPRINT_TYPE_ID, 1);

        self::assertSame(
            '400 ESI job 7248106 is already linked to a BOM line',
            $this->outcomeOf(fn () => $this->manualJobLinker()->link($job, $this->ishtarLine)),
        );
        $this->assertNothingLinked();
    }

    #[Group('issue-1250')]
    public function testItLinksOnlyTheRequestedRunsOfTheJobAtTheProRataCost(): void
    {
        $job = $this->sharedJob();

        $outcome = $this->outcomeOf(function () use ($job, &$match): void {
            $match = $this->manualJobLinker()->link($job, $this->ishtarLine, 10);
        });

        self::assertSame('granted', $outcome);
        self::assertSame([10, 500_000.0], [$match->getRuns(), $match->getCost()]);
        self::assertSame([[$this->ishtarLine->getId()->toRfc4122(), 0, 10]], $this->refreshedLines);
    }

    #[Group('issue-1250')]
    public function testAnotherProjectLinksTheOtherRunsOfTheSameJobAtTheOtherHalfOfTheCost(): void
    {
        $this->givenShare(self::SHARED_JOB_ID, $this->otherProjectLine(), 10, 500_000.0);
        $job = $this->sharedJob();

        $outcome = $this->outcomeOf(function () use ($job, &$match): void {
            $match = $this->manualJobLinker()->link($job, $this->ishtarLine, 10);
        });

        self::assertSame('granted', $outcome);
        self::assertSame([self::SHARED_JOB_ID, 10, 500_000.0], [$match->getEsiJobId(), $match->getRuns(), $match->getCost()]);
    }

    #[Group('issue-1250')]
    public function testWithoutRunsItLinksAllTheFreeRunsOfTheJob(): void
    {
        $this->givenShare(self::SHARED_JOB_ID, $this->otherProjectLine(), 8, 400_000.0);
        $job = $this->sharedJob();

        $outcome = $this->outcomeOf(function () use ($job, &$match): void {
            $match = $this->manualJobLinker()->link($job, $this->ishtarLine);
        });

        self::assertSame('granted', $outcome);
        self::assertSame([12, 600_000.0], [$match->getRuns(), $match->getCost()]);
        self::assertSame([[$this->ishtarLine->getId()->toRfc4122(), 0, 12]], $this->refreshedLines);
    }

    #[Group('issue-1250')]
    public function testMoreRunsThanTheJobHasIsRefusedWithoutLink(): void
    {
        $job = $this->sharedJob();

        self::assertStringStartsWith('400 ', $this->outcomeOf(fn () => $this->manualJobLinker()->link($job, $this->ishtarLine, 21)));
        $this->assertNothingLinked();
    }

    #[Group('issue-1250')]
    public function testMoreRunsThanTheFreeRunsLeftByAnotherShareIsRefusedWithoutLink(): void
    {
        $this->givenShare(self::SHARED_JOB_ID, $this->otherProjectLine(), 10, 500_000.0);
        $job = $this->sharedJob();

        self::assertStringStartsWith('400 ', $this->outcomeOf(fn () => $this->manualJobLinker()->link($job, $this->ishtarLine, 11)));
        $this->assertNothingLinked();
    }

    #[Group('issue-1250')]
    public function testAJobWhoseRunsAreAllSharedIsRefusedAsAlreadyLinked(): void
    {
        $this->givenShare(self::SHARED_JOB_ID, $this->otherProjectLine(), 12, 600_000.0);
        $this->givenShare(self::SHARED_JOB_ID, $this->ramLine, 8, 400_000.0);
        $job = $this->sharedJob();

        self::assertSame(
            '400 ESI job 7250101 is already linked to a BOM line',
            $this->outcomeOf(fn () => $this->manualJobLinker()->link($job, $this->ishtarLine, 1)),
        );
        $this->assertNothingLinked();
    }

    #[Group('issue-1250')]
    public function testALineCannotCarryTwoSharesOfTheSameJob(): void
    {
        $existing = $this->givenShare(self::SHARED_JOB_ID, $this->ishtarLine, 5, 250_000.0);
        $job = $this->sharedJob();

        self::assertStringStartsWith('400 ', $this->outcomeOf(fn () => $this->manualJobLinker()->link($job, $this->ishtarLine, 5)));
        self::assertSame([$existing], $this->ishtarLine->getJobMatches()->toArray());
        self::assertSame([], $this->refreshedLines);
    }

    #[Group('issue-1250')]
    public function testTheShareOfAJobWithoutKnownCostHasNoCost(): void
    {
        $job = $this->sharedJob(cost: null);

        $outcome = $this->outcomeOf(function () use ($job, &$match): void {
            $match = $this->manualJobLinker()->link($job, $this->ishtarLine, 5);
        });

        self::assertSame('granted', $outcome);
        self::assertSame(5, $match->getRuns());
        self::assertNull($match->getCost());
    }

    private function givenShare(int $esiJobId, IndustryProjectBomLine $line, int $runs, ?float $cost = null): IndustryProjectJobMatch
    {
        $match = (new IndustryProjectJobMatch())->setEsiJobId($esiJobId)->setRuns($runs)->setCost($cost);
        $line->addJobMatch($match);
        $this->storedJobMatches[] = $match;

        return $match;
    }

    private function otherProjectLine(): IndustryProjectBomLine
    {
        return $this->jobLine($this->projectOfTheOwner('Second Ishtar batch'), self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 5);
    }

    private function sharedJob(?float $cost = self::SHARED_JOB_COST): CachedIndustryJob
    {
        return $this->job(self::SHARED_JOB_ID, $this->ownerMainCharacter, self::ISHTAR_BLUEPRINT_TYPE_ID, self::SHARED_JOB_RUNS, cost: $cost);
    }

    private function assertNothingLinked(): void
    {
        self::assertCount(0, $this->ishtarLine->getJobMatches());
        self::assertSame([], $this->refreshedLines);
    }

    private function job(int $jobId, Character $installer, int $blueprintTypeId, int $runs, int $activityId = 1, string $status = 'active', ?float $cost = self::DEFAULT_JOB_COST): CachedIndustryJob
    {
        return (new CachedIndustryJob())
            ->setJobId($jobId)
            ->setCharacter($installer)
            ->setActivityId($activityId)
            ->setBlueprintTypeId($blueprintTypeId)
            ->setRuns($runs)
            ->setStatus($status)
            ->setCost($cost)
            ->setStartDate(new \DateTimeImmutable('2026-11-02T08:00:00+00:00'))
            ->setEndDate(new \DateTimeImmutable('2026-11-04T08:00:00+00:00'));
    }
}
