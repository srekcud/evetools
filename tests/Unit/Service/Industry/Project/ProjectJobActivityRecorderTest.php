<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectActivityKind;
use App\Repository\IndustryProjectActivityRepository;
use App\Service\Industry\Project\ProjectJobActivityRecorder;
use App\Service\Mercure\MercurePublisherService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[CoversClass(ProjectJobActivityRecorder::class)]
#[Group('issue-321')]
final class ProjectJobActivityRecorderTest extends TestCase
{
    private const int ISHTAR_TYPE_ID = 12005;
    private const int RAM_TYPE_ID = 11478;
    private const string OWNER_PILOT = 'Aria Solberg';
    private const string MEMBER_PILOT = 'Brann Holt';
    private const string MEMBER_ALT = 'Holt Industries';

    private IndustryProject $project;
    private IndustryProjectBomLine $ishtarLine;
    private IndustryProjectBomLine $ramLine;
    private array $recordedDetectedJobIds = [];
    private array $recordedFinishedJobIds = [];
    private array $published = [];
    private ProjectJobActivityRecorder $recorder;

    protected function setUp(): void
    {
        $owner = $this->userWithMainCharacter(self::OWNER_PILOT);
        $member = $this->userWithMainCharacter(self::MEMBER_PILOT);
        $member->addCharacter((new Character())->setName(self::MEMBER_ALT)->setEveCharacterId(2_112_321_002));

        $this->project = (new IndustryProject())->setOwner($owner)->setName('Ishtar batch');
        $this->project->addMember(
            (new IndustryProjectParticipant())->setUser($member)->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Accepted),
        );
        $this->ishtarLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar');
        $this->ramLine = $this->jobLine(self::RAM_TYPE_ID, 'R.A.M.- Starship Tech');

        $activityRepository = $this->createStub(IndustryProjectActivityRepository::class);
        $activityRepository->method('recordedJobIdsOf')->willReturnCallback(
            fn (IndustryProject $project, ProjectActivityKind $kind): array => match ($kind) {
                ProjectActivityKind::JobsDetected => $this->recordedDetectedJobIds,
                ProjectActivityKind::JobsFinished => $this->recordedFinishedJobIds,
                default => [],
            },
        );

        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $payload = json_decode($update->getData(), true);
            $this->published[] = [$payload['projectId'], $payload['action'], $this->canonical($payload['data'])];

            return 'update-id';
        });

        $this->recorder = new ProjectJobActivityRecorder($activityRepository, new MercurePublisherService($hub, new NullLogger()));
    }

    public function testTwoActiveJobsOfTheSamePilotAndLineAreDetectedAsOneGroupWithSummedRunsAndCost(): void
    {
        $this->link($this->ishtarLine, 7_362_501, 10, 'active', 1_000_000.0, '2026-11-09T18:00:00+00:00', '2026-11-10T02:00:00+00:00', self::OWNER_PILOT);
        $this->link($this->ishtarLine, 7_362_502, 10, 'active', 2_000_000.0, '2026-11-09T17:30:00+00:00', '2026-11-10T03:15:00+00:00', self::OWNER_PILOT);

        $this->recorder->recordJobsOf($this->project);

        self::assertSame(
            [[
                $this->project->getId()->toRfc4122(),
                'jobs_detected',
                [
                    'characterName' => self::OWNER_PILOT,
                    'cost' => 3_000_000.0,
                    'endsAt' => '2026-11-10T03:15:00+00:00',
                    'jobCount' => 2,
                    'jobIds' => [7_362_501, 7_362_502],
                    'runs' => 20,
                    'startedAt' => '2026-11-09T17:30:00+00:00',
                    'typeId' => self::ISHTAR_TYPE_ID,
                    'typeName' => 'Ishtar',
                ],
            ]],
            $this->published,
        );
    }

    public function testOneUnknownJobCostMakesTheCostOfTheGroupUnknownAndNeverZero(): void
    {
        $this->link($this->ishtarLine, 7_362_501, 10, 'active', 1_000_000.0, '2026-11-09T18:00:00+00:00', '2026-11-10T02:00:00+00:00', self::OWNER_PILOT);
        $this->link($this->ishtarLine, 7_362_502, 10, 'active', null, '2026-11-09T18:00:00+00:00', '2026-11-10T02:00:00+00:00', self::OWNER_PILOT);

        $this->recorder->recordJobsOf($this->project);

        self::assertCount(1, $this->published);
        self::assertArrayHasKey('cost', $this->published[0][2]);
        self::assertNull($this->published[0][2]['cost']);
        self::assertSame(20, $this->published[0][2]['runs']);
    }

    public function testJobsOfTwoBomLinesOfTheSamePilotAreDetectedInTwoGroups(): void
    {
        $this->link($this->ishtarLine, 7_362_501, 5, 'active', 1_482_350.25, '2026-11-09T18:00:00+00:00', '2026-11-10T02:00:00+00:00', self::OWNER_PILOT);
        $this->link($this->ramLine, 7_362_503, 3, 'active', 86_250.5, '2026-11-09T19:00:00+00:00', '2026-11-09T23:40:00+00:00', self::OWNER_PILOT);

        $this->recorder->recordJobsOf($this->project);

        self::assertSame(['jobs_detected', 'jobs_detected'], array_column($this->published, 1));
        $groups = array_column($this->published, 2);
        usort($groups, static fn (array $a, array $b): int => $a['typeId'] <=> $b['typeId']);
        self::assertSame(
            [[self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 1, 3, 86_250.5, [7_362_503]], [self::ISHTAR_TYPE_ID, 'Ishtar', 1, 5, 1_482_350.25, [7_362_501]]],
            array_map(static fn (array $group): array => [$group['typeId'], $group['typeName'], $group['jobCount'], $group['runs'], $group['cost'], $group['jobIds']], $groups),
        );
    }

    public function testTheNameOfTheGroupIsTheMainPilotOfTheInstallerAltAndNotTheNameOfTheCharacter(): void
    {
        $this->link($this->ramLine, 7_362_503, 3, 'active', 86_250.5, '2026-11-09T19:00:00+00:00', '2026-11-09T23:40:00+00:00', self::MEMBER_ALT);

        $this->recorder->recordJobsOf($this->project);

        self::assertCount(1, $this->published);
        self::assertSame(self::MEMBER_PILOT, $this->published[0][2]['characterName']);
    }

    public function testAJobWithoutKnownStartLeavesTheStartOfItsGroupUnknown(): void
    {
        $this->link($this->ishtarLine, 7_362_501, 10, 'active', 1_000_000.0, '2026-11-09T18:00:00+00:00', '2026-11-10T02:00:00+00:00', self::OWNER_PILOT);
        $this->link($this->ishtarLine, 7_362_502, 10, 'active', 2_000_000.0, null, '2026-11-10T03:15:00+00:00', self::OWNER_PILOT);

        $this->recorder->recordJobsOf($this->project);

        self::assertCount(1, $this->published);
        self::assertArrayHasKey('startedAt', $this->published[0][2]);
        self::assertNull($this->published[0][2]['startedAt']);
        self::assertSame('2026-11-10T03:15:00+00:00', $this->published[0][2]['endsAt']);
    }

    public function testJobsAlreadyInTheTraceAreNotDetectedAgainOnTheNextSync(): void
    {
        $this->link($this->ishtarLine, 7_362_501, 10, 'active', 1_000_000.0, '2026-11-09T18:00:00+00:00', '2026-11-10T02:00:00+00:00', self::OWNER_PILOT);
        $this->link($this->ishtarLine, 7_362_502, 10, 'active', 2_000_000.0, '2026-11-09T17:30:00+00:00', '2026-11-10T03:15:00+00:00', self::OWNER_PILOT);
        $this->recordedDetectedJobIds = [7_362_501, 7_362_502];

        $this->recorder->recordJobsOf($this->project);

        self::assertSame([], $this->published);
    }

    public function testAJobThatIsDeliveredAfterItWasDetectedIsFinishedOnceAndNotDetectedAgain(): void
    {
        $this->link($this->ishtarLine, 7_362_501, 10, 'active', 1_000_000.0, '2026-11-09T18:00:00+00:00', '2026-11-10T02:00:00+00:00', self::OWNER_PILOT);
        $this->link($this->ishtarLine, 7_362_502, 10, 'delivered', 2_000_000.0, '2026-11-09T17:30:00+00:00', '2026-11-10T03:15:00+00:00', self::OWNER_PILOT);
        $this->recordedDetectedJobIds = [7_362_501, 7_362_502];

        $this->recorder->recordJobsOf($this->project);

        self::assertSame(
            [[
                $this->project->getId()->toRfc4122(),
                'jobs_finished',
                [
                    'characterName' => self::OWNER_PILOT,
                    'endedAt' => '2026-11-10T03:15:00+00:00',
                    'jobCount' => 1,
                    'jobIds' => [7_362_502],
                    'runs' => 10,
                    'typeId' => self::ISHTAR_TYPE_ID,
                    'typeName' => 'Ishtar',
                ],
            ]],
            $this->published,
        );
    }

    public function testAJobThatIsReadyIsFinishedAndAJobAlreadyFinishedInTheTraceIsNot(): void
    {
        $this->link($this->ramLine, 7_362_503, 3, 'ready', 86_250.5, '2026-11-09T19:00:00+00:00', '2026-11-09T23:40:00+00:00', self::OWNER_PILOT);
        $this->link($this->ramLine, 7_362_504, 4, 'delivered', 115_000.0, '2026-11-09T12:00:00+00:00', '2026-11-09T17:00:00+00:00', self::OWNER_PILOT);
        $this->recordedDetectedJobIds = [7_362_503, 7_362_504];
        $this->recordedFinishedJobIds = [7_362_504];

        $this->recorder->recordJobsOf($this->project);

        self::assertCount(1, $this->published);
        self::assertSame('jobs_finished', $this->published[0][1]);
        self::assertSame([7_362_503], $this->published[0][2]['jobIds']);
        self::assertSame(3, $this->published[0][2]['runs']);
    }

    public function testJobsSeenForTheFirstTimeAlreadyDeliveredAreDetectedThenFinishedWithTheLatestEnd(): void
    {
        $this->link($this->ramLine, 7_362_503, 3, 'delivered', 86_250.5, '2026-11-09T12:00:00+00:00', '2026-11-09T17:00:00+00:00', self::OWNER_PILOT);
        $this->link($this->ramLine, 7_362_504, 4, 'delivered', 115_000.0, '2026-11-09T13:00:00+00:00', '2026-11-09T18:30:00+00:00', self::OWNER_PILOT);

        $this->recorder->recordJobsOf($this->project);

        self::assertSame(['jobs_detected', 'jobs_finished'], array_column($this->published, 1));
        self::assertSame([2, 7, 201_250.5], [$this->published[0][2]['jobCount'], $this->published[0][2]['runs'], $this->published[0][2]['cost']]);
        self::assertSame([2, 7, '2026-11-09T18:30:00+00:00'], [$this->published[1][2]['jobCount'], $this->published[1][2]['runs'], $this->published[1][2]['endedAt']]);
    }

    public function testACancelledJobNeverSeenIsDetectedWithoutAnyFinish(): void
    {
        $this->link($this->ishtarLine, 7_362_505, 6, 'cancelled', 900_000.0, '2026-11-09T18:00:00+00:00', '2026-11-10T02:00:00+00:00', self::OWNER_PILOT);

        $this->recorder->recordJobsOf($this->project);

        self::assertSame(['jobs_detected'], array_column($this->published, 1));
        self::assertSame([7_362_505], $this->published[0][2]['jobIds']);
    }

    public function testAProjectWithoutJobLinkPublishesNothing(): void
    {
        $this->recorder->recordJobsOf($this->project);

        self::assertSame([], $this->published);
    }

    private function userWithMainCharacter(string $name): User
    {
        static $nextEveCharacterId = 2_112_321_100;
        $character = (new Character())->setName($name)->setEveCharacterId(++$nextEveCharacterId);
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);

        return $user;
    }

    private function jobLine(int $typeId, string $typeName): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity(10)
            ->setIsJob(true)
            ->setJobGroup('final')
            ->setActivityType('manufacturing')
            ->setRuns(10);
        $this->project->addBomItem($line);

        return $line;
    }

    private function link(IndustryProjectBomLine $line, int $esiJobId, int $runs, string $status, ?float $cost, ?string $startDate, string $endDate, string $installer): void
    {
        $line->addJobMatch(
            (new IndustryProjectJobMatch())
                ->setEsiJobId($esiJobId)
                ->setRuns($runs)
                ->setStatus($status)
                ->setCost($cost)
                ->setStartDate($startDate === null ? null : new \DateTimeImmutable($startDate))
                ->setEndDate(new \DateTimeImmutable($endDate))
                ->setCharacterName($installer),
        );
    }

    private function canonical(array $data): array
    {
        ksort($data);
        if (isset($data['jobIds'])) {
            sort($data['jobIds']);
        }

        return $data;
    }
}
