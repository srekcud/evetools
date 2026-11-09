<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectActivity;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Enum\ProjectActivityKind;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

#[Group('issue-321')]
final class ProjectActivityApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int ACTIVITY_PAGE_SIZE = 100;
    private const int OVERFLOWING_ACTIVITY_COUNT = 120;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
    }

    public function testAcceptedMemberReadsTheEventsFromTheMostRecentToTheOldestAndTheRunningJobs(): void
    {
        $this->addActivity($this->ishtarBatch, ProjectActivityKind::MemberJoined, '2026-11-09T18:00:00+00:00', ['memberId' => $this->idOf($this->memberMembership), 'characterName' => 'Brann Holt']);
        $this->addActivity($this->ishtarBatch, ProjectActivityKind::SaleRecorded, '2026-11-09T19:00:00+00:00', ['typeName' => 'Ishtar', 'quantity' => 2]);
        $this->addActivity($this->ishtarBatch, ProjectActivityKind::JobsDetected, '2026-11-09T20:00:00+00:00', ['jobCount' => 2, 'runs' => 20]);
        $this->addActivity($this->hecateBatch, ProjectActivityKind::SaleRecorded, '2026-11-09T21:00:00+00:00', ['typeName' => 'Hecate', 'quantity' => 1]);
        $this->addRunningJobLink($this->ishtarJobLine, 7_362_501, 10, 'active', 1_000_000.0, '2026-11-09T18:00:00+00:00', '2026-11-10T02:00:00+00:00');
        $this->addRunningJobLink($this->ishtarJobLine, 7_362_502, 10, 'active', 2_000_000.0, '2026-11-09T17:30:00+00:00', '2026-11-10T03:15:00+00:00');
        $this->addRunningJobLink($this->ishtarJobLine, 7_362_503, 4, 'delivered', 400_000.0, '2026-11-08T10:00:00+00:00', '2026-11-08T14:00:00+00:00');
        $this->em->flush();

        $this->actAs($this->member);
        $this->request('GET', $this->activityUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $resource = $this->responseJson();
        self::assertArrayHasKey('events', $resource);
        self::assertArrayHasKey('runningJobs', $resource);
        self::assertSame(['jobs_detected', 'sale_recorded', 'member_joined'], array_column($resource['events'], 'kind'));
        $eventKeys = array_keys($resource['events'][0]);
        sort($eventKeys);
        self::assertSame(['data', 'id', 'kind', 'occurredAt'], $eventKeys);
        self::assertSame(
            (new \DateTimeImmutable('2026-11-09T20:00:00+00:00'))->getTimestamp(),
            (new \DateTimeImmutable($resource['events'][0]['occurredAt']))->getTimestamp(),
        );
        self::assertSame(['jobCount' => 2, 'runs' => 20], $resource['events'][0]['data']);
        self::assertCount(1, $resource['runningJobs']);
        $group = $resource['runningJobs'][0];
        self::assertSame(['Aria Solberg', self::ISHTAR_TYPE_ID, 'Ishtar', 2, 20, 3_000_000.0], [$group['characterName'], $group['typeId'], $group['typeName'], $group['jobCount'], $group['runs'], $group['cost']]);
        self::assertSame(
            [(new \DateTimeImmutable('2026-11-09T17:30:00+00:00'))->getTimestamp(), (new \DateTimeImmutable('2026-11-10T03:15:00+00:00'))->getTimestamp()],
            [(new \DateTimeImmutable($group['startedAt']))->getTimestamp(), (new \DateTimeImmutable($group['endsAt']))->getTimestamp()],
        );
    }

    public function testProjectWithoutActivityAnswersEmptyEventsAndRunningJobs(): void
    {
        $this->actAs($this->owner);
        $this->request('GET', $this->activityUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $resource = $this->responseJson();
        self::assertSame([], $resource['events']);
        self::assertSame([], $resource['runningJobs']);
    }

    public function testPilotWhoDoesNotTakePartInTheProjectIsRefusedItsActivity(): void
    {
        $this->actAs($this->outsider);
        $this->request('GET', $this->activityUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testParticipantStillPendingIsRefusedTheActivity(): void
    {
        $this->actAs($this->pendingMember);
        $this->request('GET', $this->activityUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testActivityOfAnUnknownProjectIsNotFound(): void
    {
        $this->actAs($this->owner);
        $this->request('GET', $this->activityUri(Uuid::v7()->toRfc4122()));

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
    }

    public function testOnlyTheHundredMostRecentEventsAreReturnedOutOfOneHundredAndTwenty(): void
    {
        $firstMinute = new \DateTimeImmutable('2026-11-09T00:00:00+00:00');
        for ($sequence = 0; $sequence < self::OVERFLOWING_ACTIVITY_COUNT; ++$sequence) {
            $this->addActivity(
                $this->ishtarBatch,
                ProjectActivityKind::SaleUpdated,
                $firstMinute->modify(sprintf('+%d minutes', $sequence))->format(\DateTimeInterface::ATOM),
                ['sequence' => $sequence],
            );
        }
        $this->em->flush();

        $this->actAs($this->owner);
        $this->request('GET', $this->activityUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $sequences = array_map(static fn (array $event): int => $event['data']['sequence'], $this->responseJson()['events']);
        self::assertCount(self::ACTIVITY_PAGE_SIZE, $sequences);
        self::assertSame(self::OVERFLOWING_ACTIVITY_COUNT - 1, $sequences[0]);
        self::assertSame(self::OVERFLOWING_ACTIVITY_COUNT - self::ACTIVITY_PAGE_SIZE, $sequences[self::ACTIVITY_PAGE_SIZE - 1]);
    }

    private function addActivity(IndustryProject $project, ProjectActivityKind $kind, string $occurredAt, array $data): IndustryProjectActivity
    {
        $activity = (new IndustryProjectActivity())
            ->setProject($project)
            ->setKind($kind)
            ->setOccurredAt(new \DateTimeImmutable($occurredAt))
            ->setData($data);
        $this->em->persist($activity);

        return $activity;
    }

    private function addRunningJobLink(IndustryProjectBomLine $line, int $esiJobId, int $runs, string $status, float $cost, string $startDate, string $endDate): void
    {
        $match = (new IndustryProjectJobMatch())
            ->setEsiJobId($esiJobId)
            ->setRuns($runs)
            ->setStatus($status)
            ->setCost($cost)
            ->setStartDate(new \DateTimeImmutable($startDate))
            ->setEndDate(new \DateTimeImmutable($endDate))
            ->setCharacterName('Aria Solberg');
        $line->addJobMatch($match);
        $this->em->persist($match);
    }

    private function activityUri(string $projectId): string
    {
        return '/api/industry/projects/'.$projectId.'/activity';
    }
}
