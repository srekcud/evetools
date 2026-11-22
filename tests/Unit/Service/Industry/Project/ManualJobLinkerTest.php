<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
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

    protected function setUp(): void
    {
        $this->setUpIshtarProject();
        $this->storedJobMatches[] = (new IndustryProjectJobMatch())->setEsiJobId(self::LINKED_ELSEWHERE_JOB_ID)->setRuns(1);
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

    private function assertNothingLinked(): void
    {
        self::assertCount(0, $this->ishtarLine->getJobMatches());
        self::assertSame([], $this->refreshedLines);
    }

    private function job(int $jobId, Character $installer, int $blueprintTypeId, int $runs, int $activityId = 1, string $status = 'active'): CachedIndustryJob
    {
        return (new CachedIndustryJob())
            ->setJobId($jobId)
            ->setCharacter($installer)
            ->setActivityId($activityId)
            ->setBlueprintTypeId($blueprintTypeId)
            ->setRuns($runs)
            ->setStatus($status)
            ->setCost(1_250_000.5)
            ->setStartDate(new \DateTimeImmutable('2026-11-02T08:00:00+00:00'))
            ->setEndDate(new \DateTimeImmutable('2026-11-04T08:00:00+00:00'));
    }
}
