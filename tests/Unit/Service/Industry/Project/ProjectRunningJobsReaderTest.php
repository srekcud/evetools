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
use App\Service\Industry\Project\ProjectRunningJobsReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectRunningJobsReader::class)]
#[Group('issue-321')]
final class ProjectRunningJobsReaderTest extends TestCase
{
    private const int ISHTAR_TYPE_ID = 12005;
    private const int RAM_TYPE_ID = 11478;
    private const string OWNER_PILOT = 'Aria Solberg';
    private const string MEMBER_PILOT = 'Brann Holt';
    private const string MEMBER_ALT = 'Holt Industries';

    private User $owner;
    private User $member;
    private IndustryProject $project;
    private IndustryProjectBomLine $ishtarLine;
    private IndustryProjectBomLine $ramLine;

    protected function setUp(): void
    {
        $this->owner = $this->userWithMainCharacter(self::OWNER_PILOT, 2_112_321_201);
        $this->member = $this->userWithMainCharacter(self::MEMBER_PILOT, 2_112_321_202);
        $this->member->addCharacter((new Character())->setName(self::MEMBER_ALT)->setEveCharacterId(2_112_321_203));

        $this->project = (new IndustryProject())->setOwner($this->owner)->setName('Ishtar batch');
        $this->project->addMember(
            (new IndustryProjectParticipant())->setUser($this->member)->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Accepted),
        );
        $this->ishtarLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar');
        $this->ramLine = $this->jobLine(self::RAM_TYPE_ID, 'R.A.M.- Starship Tech');
    }

    public function testOnlyTheActiveJobLinksFormTheGroupAndTheDeliveredOneIsLeftOut(): void
    {
        $this->link($this->ishtarLine, 7_362_501, 10, 'active', 1_000_000.0, '2026-11-09T18:00:00+00:00', '2026-11-10T02:00:00+00:00', self::OWNER_PILOT);
        $this->link($this->ishtarLine, 7_362_502, 10, 'active', 2_000_000.0, '2026-11-09T17:30:00+00:00', '2026-11-10T03:15:00+00:00', self::OWNER_PILOT);
        $this->link($this->ishtarLine, 7_362_503, 4, 'delivered', 400_000.0, '2026-11-08T10:00:00+00:00', '2026-11-08T14:00:00+00:00', self::OWNER_PILOT);

        self::assertSame(
            [[
                'characterName' => self::OWNER_PILOT,
                'cost' => 3_000_000.0,
                'endsAt' => '2026-11-10T03:15:00+00:00',
                'jobCount' => 2,
                'runs' => 20,
                'startedAt' => '2026-11-09T17:30:00+00:00',
                'typeId' => self::ISHTAR_TYPE_ID,
                'typeName' => 'Ishtar',
            ]],
            $this->runningJobsSeenBy($this->owner),
        );
    }

    public function testPausedReadyCancelledAndRevertedJobLinksAreNotRunning(): void
    {
        $this->link($this->ishtarLine, 7_362_511, 5, 'paused', 500_000.0, '2026-11-09T18:00:00+00:00', '2026-11-10T02:00:00+00:00', self::OWNER_PILOT);
        $this->link($this->ishtarLine, 7_362_512, 6, 'ready', 600_000.0, '2026-11-09T18:00:00+00:00', '2026-11-10T02:00:00+00:00', self::OWNER_PILOT);
        $this->link($this->ramLine, 7_362_513, 7, 'cancelled', 700_000.0, '2026-11-09T18:00:00+00:00', '2026-11-10T02:00:00+00:00', self::OWNER_PILOT);
        $this->link($this->ramLine, 7_362_514, 8, 'reverted', 800_000.0, '2026-11-09T18:00:00+00:00', '2026-11-10T02:00:00+00:00', self::OWNER_PILOT);

        self::assertSame([], $this->runningJobsSeenBy($this->owner));
    }

    public function testAnUnknownJobCostMakesTheCostOfTheGroupUnknownAndNeverZero(): void
    {
        $this->link($this->ishtarLine, 7_362_501, 10, 'active', 1_000_000.0, '2026-11-09T18:00:00+00:00', '2026-11-10T02:00:00+00:00', self::OWNER_PILOT);
        $this->link($this->ishtarLine, 7_362_502, 10, 'active', null, '2026-11-09T18:00:00+00:00', '2026-11-10T02:00:00+00:00', self::OWNER_PILOT);

        $groups = $this->runningJobsSeenBy($this->owner);

        self::assertCount(1, $groups);
        self::assertArrayHasKey('cost', $groups[0]);
        self::assertNull($groups[0]['cost']);
        self::assertSame(20, $groups[0]['runs']);
    }

    public function testAnUnknownStartLeavesTheStartOfTheGroupUnknown(): void
    {
        $this->link($this->ramLine, 7_362_503, 3, 'active', 86_250.5, null, '2026-11-09T23:40:00+00:00', self::OWNER_PILOT);
        $this->link($this->ramLine, 7_362_504, 4, 'active', 115_000.0, '2026-11-09T19:00:00+00:00', '2026-11-09T22:10:00+00:00', self::OWNER_PILOT);

        $groups = $this->runningJobsSeenBy($this->owner);

        self::assertCount(1, $groups);
        self::assertArrayHasKey('startedAt', $groups[0]);
        self::assertNull($groups[0]['startedAt']);
        self::assertSame('2026-11-09T23:40:00+00:00', $groups[0]['endsAt']);
    }

    public function testGroupsAreSplitByInstallerAndBomLineAndSortedByTheEarliestEndFirst(): void
    {
        $this->link($this->ishtarLine, 7_362_501, 5, 'active', 1_482_350.25, '2026-11-09T18:00:00+00:00', '2026-11-10T02:00:00+00:00', self::OWNER_PILOT);
        $this->link($this->ramLine, 7_362_503, 3, 'active', 86_250.5, '2026-11-09T19:00:00+00:00', '2026-11-09T23:40:00+00:00', self::OWNER_PILOT);
        $this->link($this->ramLine, 7_362_504, 4, 'active', 115_000.0, '2026-11-09T20:00:00+00:00', '2026-11-10T01:00:00+00:00', self::MEMBER_PILOT);

        $groups = $this->runningJobsSeenBy($this->owner);

        self::assertSame(
            [
                [self::OWNER_PILOT, self::RAM_TYPE_ID, 3, '2026-11-09T23:40:00+00:00'],
                [self::MEMBER_PILOT, self::RAM_TYPE_ID, 4, '2026-11-10T01:00:00+00:00'],
                [self::OWNER_PILOT, self::ISHTAR_TYPE_ID, 5, '2026-11-10T02:00:00+00:00'],
            ],
            array_map(static fn (array $group): array => [$group['characterName'], $group['typeId'], $group['runs'], $group['endsAt']], $groups),
        );
    }

    public function testTheInstallerAltIsShownByItsNameToItsOwnerAndByTheMainPilotToTheOthers(): void
    {
        $this->link($this->ramLine, 7_362_503, 3, 'active', 86_250.5, '2026-11-09T19:00:00+00:00', '2026-11-09T23:40:00+00:00', self::MEMBER_ALT);

        self::assertSame(
            [[self::MEMBER_ALT], [self::MEMBER_PILOT]],
            [
                array_column($this->runningJobsSeenBy($this->member), 'characterName'),
                array_column($this->runningJobsSeenBy($this->owner), 'characterName'),
            ],
        );
    }

    public function testAProjectWithoutJobLinkHasNoRunningJob(): void
    {
        self::assertSame([], $this->runningJobsSeenBy($this->owner));
    }

    private function runningJobsSeenBy(User $viewer): array
    {
        $groups = (new ProjectRunningJobsReader())->of($this->project, $viewer);

        return array_map(static function (array $group): array {
            ksort($group);

            return $group;
        }, $groups);
    }

    private function userWithMainCharacter(string $name, int $eveCharacterId): User
    {
        $character = (new Character())->setName($name)->setEveCharacterId($eveCharacterId);
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
}
