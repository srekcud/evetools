<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\StructureAccess;

use App\Entity\CharacterStructureAccess;
use App\Enum\StructureAccessSource;
use App\Enum\StructureAccessStatus;
use App\Service\Industry\Project\StructureAccess\StructureAccessRefreshReport;
use App\Service\Industry\Project\StructureAccess\StructureAccessRefresher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(StructureAccessRefresher::class)]
#[Group('issue-1299')]
final class StructureAccessRefresherTest extends TestCase
{
    use BuildsStructureAccessRefresher;

    public function testTwoMembersWithoutAnyProofAreProbedOnceEachAndRecordedGranted(): void
    {
        $ownerCharacter = $this->characterOf();
        $memberCharacter = $this->characterOf();
        $owner = $this->pilotOf($ownerCharacter);
        $project = $this->projectPlannedAt($owner, [self::KEEPSTAR_STRUCTURE_ID]);

        $report = $this->refresherFor($project, [$owner, $this->pilotOf($memberCharacter)])->refreshProject($project);

        self::assertEquals(new StructureAccessRefreshReport(2, 0, false, false), $report);
        self::assertCount(2, $this->requestedUrls);
        foreach ([$ownerCharacter, $memberCharacter] as $character) {
            $row = $this->rowAfter($character, self::KEEPSTAR_STRUCTURE_ID);
            self::assertSame([StructureAccessStatus::Granted, StructureAccessSource::Esi], [$row?->getStatus(), $row?->getSource()]);
            self::assertEquals(new \DateTimeImmutable(self::PROBE_NOW), $row?->getCheckedAt());
        }
    }

    #[DataProvider('storedProofsWithTheirAge')]
    public function testStoredProofIsProbedAgainOnlyOnceItIsStale(StructureAccessStatus $status, StructureAccessSource $source, string $age, int $expectedProbes): void
    {
        $character = $this->characterOf();
        $owner = $this->pilotOf($character);
        $project = $this->projectPlannedAt($owner, [self::KEEPSTAR_STRUCTURE_ID]);
        $this->holdRow($this->accessRowOf($character, self::KEEPSTAR_STRUCTURE_ID, $status, $source, (new \DateTimeImmutable(self::PROBE_NOW))->modify($age)->format(\DATE_ATOM)));

        $report = $this->refresherFor($project, [$owner])->refreshProject($project);

        self::assertCount($expectedProbes, $this->requestedUrls);
        self::assertSame([$expectedProbes, 1 - $expectedProbes], [$report->probed, $report->skipped]);
    }

    public static function storedProofsWithTheirAge(): iterable
    {
        yield 'denied 23 hours ago' => [StructureAccessStatus::Denied, StructureAccessSource::Esi, '-23 hours', 0];
        yield 'denied 25 hours ago' => [StructureAccessStatus::Denied, StructureAccessSource::Esi, '-25 hours', 1];
        yield 'granted by ESI 6 days ago' => [StructureAccessStatus::Granted, StructureAccessSource::Esi, '-6 days', 0];
        yield 'granted by ESI 8 days ago' => [StructureAccessStatus::Granted, StructureAccessSource::Esi, '-8 days', 1];
    }

    #[DataProvider('staleRowsWithTheirNewAnswer')]
    public function testNewAnswerReplacesTheStaleRow(StructureAccessStatus $storedStatus, string $age, int $httpCode, StructureAccessStatus $expected): void
    {
        $character = $this->characterOf();
        $owner = $this->pilotOf($character);
        $project = $this->projectPlannedAt($owner, [self::KEEPSTAR_STRUCTURE_ID]);
        $this->holdRow($this->accessRowOf($character, self::KEEPSTAR_STRUCTURE_ID, $storedStatus, StructureAccessSource::Esi, (new \DateTimeImmutable(self::PROBE_NOW))->modify($age)->format(\DATE_ATOM)));

        $this->refresherFor($project, [$owner], answerCode: $httpCode)->refreshProject($project);

        $row = $this->rowAfter($character, self::KEEPSTAR_STRUCTURE_ID);
        self::assertSame([$expected, StructureAccessSource::Esi], [$row?->getStatus(), $row?->getSource()]);
        self::assertEquals(new \DateTimeImmutable(self::PROBE_NOW), $row?->getCheckedAt());
    }

    public static function staleRowsWithTheirNewAnswer(): iterable
    {
        yield 'denied then readable' => [StructureAccessStatus::Denied, '-25 hours', 200, StructureAccessStatus::Granted];
        yield 'granted then forbidden' => [StructureAccessStatus::Granted, '-8 days', 403, StructureAccessStatus::Denied];
    }

    public function testUnknownProbeWritesNothingAndLeavesTheStaleRowAsItWas(): void
    {
        $withRow = $this->characterOf();
        $withoutRow = $this->characterOf();
        $owner = $this->pilotOf($withRow);
        $project = $this->projectPlannedAt($owner, [self::KEEPSTAR_STRUCTURE_ID]);
        $staleRow = $this->accessRowOf($withRow, self::KEEPSTAR_STRUCTURE_ID, StructureAccessStatus::Denied, StructureAccessSource::Esi, (new \DateTimeImmutable(self::PROBE_NOW))->modify('-25 hours')->format(\DATE_ATOM));
        $this->holdRow($staleRow);

        $this->refresherFor($project, [$owner, $this->pilotOf($withoutRow)], answerCode: 404)->refreshProject($project);

        self::assertCount(2, $this->requestedUrls);
        self::assertSame([], $this->persistedRows);
        self::assertSame(StructureAccessStatus::Denied, $staleRow->getStatus());
        self::assertEquals((new \DateTimeImmutable(self::PROBE_NOW))->modify('-25 hours'), $staleRow->getCheckedAt());
        self::assertNull($this->rowAfter($withoutRow, self::KEEPSTAR_STRUCTURE_ID));
    }

    public function testCharacterWithAJobInTheStructureIsRecordedGrantedByJobWithoutProbe(): void
    {
        $withJob = $this->characterOf();
        $withoutJob = $this->characterOf();
        $owner = $this->pilotOf($withJob);
        $project = $this->projectPlannedAt($owner, [self::KEEPSTAR_STRUCTURE_ID]);
        $jobs = [$this->proofKeyOf($withJob, self::KEEPSTAR_STRUCTURE_ID) => new \DateTimeImmutable('2026-11-20T10:00:00+00:00')];

        $report = $this->refresherFor($project, [$owner, $this->pilotOf($withoutJob)], jobEndDates: $jobs)->refreshProject($project);

        self::assertCount(1, $this->requestedUrls);
        self::assertSame(1, $report->probed);
        $row = $this->rowAfter($withJob, self::KEEPSTAR_STRUCTURE_ID);
        self::assertSame([StructureAccessStatus::Granted, StructureAccessSource::Job], [$row?->getStatus(), $row?->getSource()]);
    }

    public function testNoMoreThanFortyProbesAreMadeInOnePass(): void
    {
        $owner = $this->pilotOf($this->characterOf());
        $members = [$owner];
        for ($index = 0; $index < 8; ++$index) {
            $members[] = $this->pilotOf($this->characterOf());
        }
        $project = $this->projectPlannedAt($owner, [self::KEEPSTAR_STRUCTURE_ID, self::KEEPSTAR_STRUCTURE_ID + 1, self::KEEPSTAR_STRUCTURE_ID + 2, self::KEEPSTAR_STRUCTURE_ID + 3, self::KEEPSTAR_STRUCTURE_ID + 4]);

        $report = $this->refresherFor($project, $members)->refreshProject($project);

        self::assertCount(20, $this->requestedUrls);
        self::assertSame([20, true, false], [$report->probed, $report->capped, $report->stoppedForBudget]);
        self::assertCount(20, $this->persistedRows);
    }

    #[DataProvider('exhaustedBudgetLevels')]
    public function testNoProbeIsMadeOnceTheErrorBudgetIsNotOk(string $level): void
    {
        $owner = $this->pilotOf($this->characterOf());
        $project = $this->projectPlannedAt($owner, [self::KEEPSTAR_STRUCTURE_ID]);

        $report = $this->refresherFor($project, [$owner], budgetLevel: $level)->refreshProject($project);

        self::assertSame([], $this->requestedUrls);
        self::assertSame([0, true, false], [$report->probed, $report->stoppedForBudget, $report->capped]);
        self::assertSame([], $this->persistedRows);
    }

    public function testAnErrorBudgetWithoutFreshObservationDoesNotStopTheProbes(): void
    {
        $owner = $this->pilotOf($this->characterOf());
        $project = $this->projectPlannedAt($owner, [self::KEEPSTAR_STRUCTURE_ID]);

        $report = $this->refresherFor($project, [$owner], budgetLevel: 'unknown')->refreshProject($project);

        self::assertCount(1, $this->requestedUrls);
        self::assertSame([1, false, false], [$report->probed, $report->stoppedForBudget, $report->capped]);
    }

    public static function exhaustedBudgetLevels(): iterable
    {
        yield 'low' => ['low'];
        yield 'critical' => ['critical'];
    }

    public function testNpcStationOfThePlanIsNeverProbedNorRecorded(): void
    {
        $character = $this->characterOf();
        $owner = $this->pilotOf($character);
        $project = $this->projectPlannedAt($owner, [self::KEEPSTAR_STRUCTURE_ID, self::JITA_FORTIZAR_STATION_ID]);

        $this->refresherFor($project, [$owner])->refreshProject($project);

        self::assertCount(1, $this->requestedUrls);
        self::assertSame([self::KEEPSTAR_STRUCTURE_ID], array_map(static fn (CharacterStructureAccess $row): int => $row->getStructureId(), $this->persistedRows));
        self::assertNull($this->rowAfter($character, self::JITA_FORTIZAR_STATION_ID));
    }
}
