<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Service\Industry\Project\ActionQueueEntry;
use App\Service\Industry\Project\ActionQueueSorter;
use App\Service\Industry\Project\CockpitNextAction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActionQueueSorter::class)]
#[Group('issue-335')]
final class ActionQueueSorterTest extends TestCase
{
    public function testBlockedProjectComesFirstThenTheMoneyToCollectThenTheStartOfAProject(): void
    {
        $queue = (new ActionQueueSorter())->sort([
            $this->entry('project-c', 'Cutlass batch', 'start_project'),
            $this->entry('project-b', 'Sabre batch', 'record_sale'),
            $this->entry('project-a', 'Ishtar batch', 'source_material', true),
        ]);

        self::assertSame(['project-a', 'project-b', 'project-c'], $this->projectIdsOf($queue));
    }

    public function testRecordSaleThenCompleteProjectThenStartProject(): void
    {
        $queue = (new ActionQueueSorter())->sort([
            $this->entry('project-e', 'Hecate batch', 'start_project'),
            $this->entry('project-d', 'Gila batch', 'complete_project'),
            $this->entry('project-b', 'Sabre batch', 'record_sale'),
        ]);

        self::assertSame(['project-b', 'project-d', 'project-e'], $this->projectIdsOf($queue));
    }

    public function testEveryCodeIsRankedFromTheMostUrgentToTheLeastUrgent(): void
    {
        $queue = (new ActionQueueSorter())->sort([
            $this->entry('start-project', 'Alpha', 'start_project'),
            $this->entry('review-contributions', 'Alpha', 'review_contributions'),
            $this->entry('start-selling', 'Alpha', 'start_selling'),
            $this->entry('source-material', 'Alpha', 'source_material'),
            $this->entry('launch-job', 'Alpha', 'launch_job'),
            $this->entry('complete-project', 'Alpha', 'complete_project'),
            $this->entry('record-sale', 'Alpha', 'record_sale'),
        ]);

        self::assertSame(
            ['record-sale', 'complete-project', 'launch-job', 'source-material', 'start-selling', 'review-contributions', 'start-project'],
            $this->projectIdsOf($queue),
        );
    }

    public function testBlockedProjectStaysAheadOfAMoreUrgentCodeOfARunningProject(): void
    {
        $queue = (new ActionQueueSorter())->sort([
            $this->entry('selling', 'Sabre batch', 'record_sale'),
            $this->entry('stalled', 'Ishtar batch', 'source_material', true),
        ]);

        self::assertSame(['stalled', 'selling'], $this->projectIdsOf($queue));
    }

    public function testTwoLaunchJobProjectsAreOrderedByIncreasingProjectName(): void
    {
        $queue = (new ActionQueueSorter())->sort([
            $this->entry('project-bravo', 'Bravo', 'launch_job'),
            $this->entry('project-alpha', 'Alpha', 'launch_job'),
        ]);

        self::assertSame(['project-alpha', 'project-bravo'], $this->projectIdsOf($queue));
    }

    public function testProjectWithoutNameComesAfterTheNamedProjectOfTheSameCode(): void
    {
        $queue = (new ActionQueueSorter())->sort([
            $this->entry('project-unnamed', null, 'launch_job'),
            $this->entry('project-zulu', 'Zulu', 'launch_job'),
        ]);

        self::assertSame(['project-zulu', 'project-unnamed'], $this->projectIdsOf($queue));
    }

    public function testProjectsWithTheSameNameAndCodeAreOrderedByProjectId(): void
    {
        $queue = (new ActionQueueSorter())->sort([
            $this->entry('0b7f', 'Alpha', 'launch_job'),
            $this->entry('0a3c', 'Alpha', 'launch_job'),
            $this->entry('0f90', null, 'launch_job'),
            $this->entry('0d11', null, 'launch_job'),
        ]);

        self::assertSame(['0a3c', '0b7f', '0d11', '0f90'], $this->projectIdsOf($queue));
    }

    public function testUnknownCodeIsRefusedInsteadOfGettingADefaultRank(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('mystery_action');

        (new ActionQueueSorter())->sort([
            $this->entry('project-a', 'Alpha', 'launch_job'),
            $this->entry('project-b', 'Bravo', 'mystery_action'),
        ]);
    }

    public function testEmptyQueueStaysEmpty(): void
    {
        self::assertSame([], (new ActionQueueSorter())->sort([]));
    }

    private function entry(string $projectId, ?string $projectName, string $code, bool $blocked = false): ActionQueueEntry
    {
        return new ActionQueueEntry(
            $projectId,
            $projectName,
            new CockpitNextAction($code, $blocked, null, null, null, null, null, null),
        );
    }

    private function projectIdsOf(array $queue): array
    {
        return array_map(static fn (ActionQueueEntry $entry): string => $entry->projectId, $queue);
    }
}
