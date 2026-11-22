<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Industry\UnassignedJobResource;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Service\Industry\IndustryCalculationService;
use App\State\Provider\Industry\UnassignedJobsProvider;
use App\Tests\Unit\State\Processor\Industry\GivesUnassignedJobsOnIshtarProjects;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(UnassignedJobsProvider::class)]
#[Group('issue-1248')]
#[AllowMockObjectsWithoutExpectations]
final class UnassignedJobsProviderTest extends TestCase
{
    use GivesUnassignedJobsOnIshtarProjects;

    private const string JITA_STATION_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant';

    protected function setUp(): void
    {
        $this->setUpUnassignedJobsWorld();
    }

    public function testItListsTheUnassignedJobsOfTheUserNewestFirstWithTheirCandidatesBestFirst(): void
    {
        $this->matchRunsOn($this->ishtarLine, 7_248_901, 2);
        $this->matchRunsOn($this->rivalIshtarLine, 7_248_902, 3);
        $this->givenUnassignedJob(
            self::ISHTAR_JOB_ID,
            $this->ownerAltCharacter,
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            self::ISHTAR_TYPE_ID,
            5,
            '2026-11-02T08:00:00+00:00',
            [
                $this->candidateOf($this->ishtarProject, $this->ishtarLine, 4, ['intent', 'place'], false),
                $this->candidateOf($this->rivalProject, $this->rivalIshtarLine, 1, ['structure'], false),
            ],
            stationId: self::JITA_STATION_ID,
        );
        $this->givenUnassignedJob(
            self::RAM_JOB_ID,
            $this->ownerMainCharacter,
            self::RAM_BLUEPRINT_TYPE_ID,
            self::RAM_TYPE_ID,
            4,
            '2026-11-03T08:00:00+00:00',
            [$this->candidateOf($this->ishtarProject, $this->ramLine, 0, [], true)],
        );

        $jobs = $this->listFor($this->owner);

        self::assertSame(
            [
                [
                    'esiJobId' => self::RAM_JOB_ID,
                    'characterName' => 'Aria Solberg',
                    'blueprintTypeId' => self::RAM_BLUEPRINT_TYPE_ID,
                    'productTypeId' => self::RAM_TYPE_ID,
                    'productTypeName' => 'R.A.M.- Starship Tech',
                    'runs' => 4,
                    'activityId' => 1,
                    'stationId' => null,
                    'stationName' => null,
                    'startDate' => '2026-11-03T08:00:00+00:00',
                    'endDate' => '2026-11-05T08:00:00+00:00',
                    'status' => 'active',
                    'candidates' => [
                        [
                            'projectId' => $this->ishtarProject->getId()->toRfc4122(),
                            'projectName' => 'Ishtar batch',
                            'lineId' => $this->ramLine->getId()->toRfc4122(),
                            'lineName' => 'R.A.M.- Starship Tech',
                            'remainingRuns' => 4,
                            'score' => 0,
                            'signals' => [],
                            'oversized' => true,
                        ],
                    ],
                ],
                [
                    'esiJobId' => self::ISHTAR_JOB_ID,
                    'characterName' => 'Aria Alt',
                    'blueprintTypeId' => self::ISHTAR_BLUEPRINT_TYPE_ID,
                    'productTypeId' => self::ISHTAR_TYPE_ID,
                    'productTypeName' => 'Ishtar',
                    'runs' => 5,
                    'activityId' => 1,
                    'stationId' => self::JITA_STATION_ID,
                    'stationName' => self::JITA_STATION_NAME,
                    'startDate' => '2026-11-02T08:00:00+00:00',
                    'endDate' => '2026-11-04T08:00:00+00:00',
                    'status' => 'active',
                    'candidates' => [
                        [
                            'projectId' => $this->ishtarProject->getId()->toRfc4122(),
                            'projectName' => 'Ishtar batch',
                            'lineId' => $this->ishtarLine->getId()->toRfc4122(),
                            'lineName' => 'Ishtar',
                            'remainingRuns' => 3,
                            'score' => 4,
                            'signals' => ['intent', 'place'],
                            'oversized' => false,
                        ],
                        [
                            'projectId' => $this->rivalProject->getId()->toRfc4122(),
                            'projectName' => 'Ishtar rival batch',
                            'lineId' => $this->rivalIshtarLine->getId()->toRfc4122(),
                            'lineName' => 'Ishtar',
                            'remainingRuns' => 7,
                            'score' => 1,
                            'signals' => ['structure'],
                            'oversized' => false,
                        ],
                    ],
                ],
            ],
            array_map(static fn (UnassignedJobResource $job): array => get_object_vars($job), $jobs),
        );
    }

    public function testItKeepsTheStoredOrderOfTheCandidatesWhateverTheirScore(): void
    {
        $this->givenUnassignedJob(
            self::ISHTAR_JOB_ID,
            $this->ownerMainCharacter,
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            self::ISHTAR_TYPE_ID,
            5,
            '2026-11-02T08:00:00+00:00',
            [
                $this->candidateOf($this->rivalProject, $this->rivalIshtarLine, 1, [], false),
                $this->candidateOf($this->ishtarProject, $this->ishtarLine, 4, [], false),
            ],
        );

        $jobs = $this->listFor($this->owner);

        self::assertSame(
            [self::ISHTAR_JOB_ID => [$this->rivalIshtarLine->getId()->toRfc4122(), $this->ishtarLine->getId()->toRfc4122()]],
            $this->candidateLineIdsByJob($jobs),
        );
    }

    public function testACandidateOfAProjectWhereTheUserIsNeitherOwnerNorAcceptedParticipantIsDroppedAndAJobWithNoneLeftIsNotListed(): void
    {
        $pendingRequest = (new IndustryProjectParticipant())->setUser($this->owner)->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Pending);
        $this->strangersProject->addMember($pendingRequest);
        $this->participants[] = $pendingRequest;
        $this->givenUnassignedJob(
            self::ISHTAR_JOB_ID,
            $this->ownerMainCharacter,
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            self::ISHTAR_TYPE_ID,
            5,
            '2026-11-02T08:00:00+00:00',
            [
                $this->candidateOf($this->strangersProject, $this->strangersIshtarLine, 3),
                $this->candidateOf($this->ishtarProject, $this->ishtarLine, 2),
            ],
        );
        $this->givenUnassignedJob(
            self::ISHTAR_BIG_JOB_ID,
            $this->ownerMainCharacter,
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            self::ISHTAR_TYPE_ID,
            9,
            '2026-11-01T08:00:00+00:00',
            [$this->candidateOf($this->strangersProject, $this->strangersIshtarLine, 3, [], true)],
        );

        $jobs = $this->listFor($this->owner);

        self::assertSame([self::ISHTAR_JOB_ID => [$this->ishtarLine->getId()->toRfc4122()]], $this->candidateLineIdsByJob($jobs));
    }

    public function testACandidateOfACompletedOrDraftProjectIsDroppedAndAJobWithNoneLeftIsNotListed(): void
    {
        $this->givenUnassignedJob(
            self::ISHTAR_JOB_ID,
            $this->ownerMainCharacter,
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            self::ISHTAR_TYPE_ID,
            5,
            '2026-11-02T08:00:00+00:00',
            [
                $this->candidateOf($this->completedProject, $this->completedIshtarLine, 5),
                $this->candidateOf($this->draftProject, $this->draftIshtarLine, 4),
                $this->candidateOf($this->ishtarProject, $this->ishtarLine, 1),
            ],
        );
        $this->givenUnassignedJob(
            self::ISHTAR_BIG_JOB_ID,
            $this->ownerMainCharacter,
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            self::ISHTAR_TYPE_ID,
            9,
            '2026-11-01T08:00:00+00:00',
            [
                $this->candidateOf($this->completedProject, $this->completedIshtarLine, 5),
                $this->candidateOf($this->draftProject, $this->draftIshtarLine, 4),
            ],
        );

        $jobs = $this->listFor($this->owner);

        self::assertSame([self::ISHTAR_JOB_ID => [$this->ishtarLine->getId()->toRfc4122()]], $this->candidateLineIdsByJob($jobs));
    }

    public function testACandidateLineThatDoesNotExistAnymoreOrIsNotAJobLineIsDropped(): void
    {
        $missingLineCandidate = $this->candidateOf($this->ishtarProject, $this->ishtarLine, 3);
        $missingLineCandidate['lineId'] = Uuid::v7()->toRfc4122();
        $this->givenUnassignedJob(
            self::ISHTAR_JOB_ID,
            $this->ownerMainCharacter,
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            self::ISHTAR_TYPE_ID,
            5,
            '2026-11-02T08:00:00+00:00',
            [
                $missingLineCandidate,
                $this->candidateOf($this->ishtarProject, $this->tritaniumLine, 2),
                $this->candidateOf($this->ishtarProject, $this->ishtarLine, 1),
            ],
        );
        $this->givenUnassignedJob(
            self::ISHTAR_BIG_JOB_ID,
            $this->ownerMainCharacter,
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            self::ISHTAR_TYPE_ID,
            9,
            '2026-11-01T08:00:00+00:00',
            [$missingLineCandidate, $this->candidateOf($this->ishtarProject, $this->tritaniumLine, 2)],
        );

        $jobs = $this->listFor($this->owner);

        self::assertSame([self::ISHTAR_JOB_ID => [$this->ishtarLine->getId()->toRfc4122()]], $this->candidateLineIdsByJob($jobs));
    }

    public function testTheRemainingRunsOfACandidateNeverGoBelowZero(): void
    {
        $this->matchRunsOn($this->ishtarLine, 7_248_901, 3);
        $this->matchRunsOn($this->ishtarLine, 7_248_902, 4);
        $this->givenUnassignedJob(
            self::ISHTAR_JOB_ID,
            $this->ownerMainCharacter,
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            self::ISHTAR_TYPE_ID,
            5,
            '2026-11-02T08:00:00+00:00',
            [$this->candidateOf($this->ishtarProject, $this->ishtarLine, 0, [], true)],
        );

        $jobs = $this->listFor($this->owner);

        self::assertSame([self::ISHTAR_JOB_ID => [0]], $this->remainingRunsByJob($jobs));
    }

    public function testAJobThatEndedWithoutOutputIsNotListedEvenWhenItsRowIsStillStored(): void
    {
        $this->givenUnassignedJob(
            self::ISHTAR_JOB_ID,
            $this->ownerMainCharacter,
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            self::ISHTAR_TYPE_ID,
            5,
            '2026-11-02T08:00:00+00:00',
            [$this->candidateOf($this->ishtarProject, $this->ishtarLine, 1)],
            'cancelled',
        );
        $this->givenUnassignedJob(
            self::ISHTAR_BIG_JOB_ID,
            $this->ownerMainCharacter,
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            self::ISHTAR_TYPE_ID,
            2,
            '2026-11-01T08:00:00+00:00',
            [$this->candidateOf($this->ishtarProject, $this->ishtarLine, 1)],
            'reverted',
        );
        $this->givenUnassignedJob(
            self::RAM_JOB_ID,
            $this->ownerMainCharacter,
            self::RAM_BLUEPRINT_TYPE_ID,
            self::RAM_TYPE_ID,
            4,
            '2026-11-03T08:00:00+00:00',
            [$this->candidateOf($this->ishtarProject, $this->ramLine, 0, [], true)],
        );

        self::assertSame([self::RAM_JOB_ID], array_column($this->listFor($this->owner), 'esiJobId'));
    }

    public function testItNeverMixesTheRowsOfTwoUsers(): void
    {
        $this->givenUnassignedJob(
            self::ISHTAR_JOB_ID,
            $this->ownerMainCharacter,
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            self::ISHTAR_TYPE_ID,
            5,
            '2026-11-02T08:00:00+00:00',
            [$this->candidateOf($this->rivalProject, $this->rivalIshtarLine, 1)],
        );
        $this->givenUnassignedJob(
            self::ISHTAR_JOB_OF_RIVAL_ID,
            $this->rivalCharacter,
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            self::ISHTAR_TYPE_ID,
            3,
            '2026-11-03T08:00:00+00:00',
            [$this->candidateOf($this->rivalProject, $this->rivalIshtarLine, 1)],
        );

        self::assertSame(
            [[self::ISHTAR_JOB_ID], [self::ISHTAR_JOB_OF_RIVAL_ID]],
            [
                array_column($this->listFor($this->owner), 'esiJobId'),
                array_column($this->listFor($this->rival), 'esiJobId'),
            ],
        );
    }

    private function candidateLineIdsByJob(array $jobs): array
    {
        return array_combine(
            array_map(static fn (UnassignedJobResource $job): int => $job->esiJobId, $jobs),
            array_map(static fn (UnassignedJobResource $job): array => array_column($job->candidates, 'lineId'), $jobs),
        );
    }

    private function remainingRunsByJob(array $jobs): array
    {
        return array_combine(
            array_map(static fn (UnassignedJobResource $job): int => $job->esiJobId, $jobs),
            array_map(static fn (UnassignedJobResource $job): array => array_column($job->candidates, 'remainingRuns'), $jobs),
        );
    }

    private function listFor(User $user): array
    {
        $calculationService = $this->createStub(IndustryCalculationService::class);
        $calculationService->method('resolveTypeName')->willReturnCallback(
            static fn (int $typeId): ?string => [self::ISHTAR_TYPE_ID => 'Ishtar', self::RAM_TYPE_ID => 'R.A.M.- Starship Tech'][$typeId] ?? null,
        );
        $calculationService->method('resolveFacilityName')->willReturnCallback(
            static fn (int $stationId): ?string => $stationId === self::JITA_STATION_ID ? self::JITA_STATION_NAME : null,
        );

        $provider = new UnassignedJobsProvider(
            $this->securityOf($user),
            $this->unassignedJobRepository(),
            $this->unassignedJobCandidates(),
            $calculationService,
        );

        return $provider->provide(new GetCollection());
    }
}
