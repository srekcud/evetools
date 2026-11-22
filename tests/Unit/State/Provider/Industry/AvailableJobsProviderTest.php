<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Industry\AvailableJobResource;
use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectParticipant;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectJobMatchRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryJobMatcher;
use App\State\Provider\Industry\AvailableJobsProvider;
use App\Tests\Unit\State\Processor\Industry\TracksJobsAndPurchasesOnIshtarBom;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(AvailableJobsProvider::class)]
#[Group('issue-362')]
#[AllowMockObjectsWithoutExpectations]
final class AvailableJobsProviderTest extends TestCase
{
    use TracksJobsAndPurchasesOnIshtarBom;

    private const int ISHTAR_JOB_ID = 7_362_601;
    private const int RAM_JOB_ID = 7_362_602;
    private const int CANCELLED_RAM_JOB_ID = 7_362_603;
    private const int UNTOUCHED_JOB_ID = 7_362_604;
    private const string NO_ACCESS = '403 You do not have access to this project';

    private array $cachedJobs = [];

    private array $jobLookups = [];

    private array $matchesOfOtherProjects = [];

    private array $linesOfAnotherActivity = [];

    protected function setUp(): void
    {
        $this->setUpIshtarProject();
    }

    public function testListsTheJobsOfTheOwnerOnTheProducersOfTheJobLinesWithTheLineTheyAreLinkedTo(): void
    {
        $this->job($this->ownerMainCharacter, self::ISHTAR_JOB_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::ISHTAR_TYPE_ID, 5, 1_482_350.25, '2026-11-02T08:00:00+00:00');
        $this->job($this->ownerAltCharacter, self::RAM_JOB_ID, self::RAM_BLUEPRINT_TYPE_ID, self::RAM_TYPE_ID, 4, 86_250.5, '2026-11-03T08:00:00+00:00');
        $ramMatch = (new IndustryProjectJobMatch())->setEsiJobId(self::RAM_JOB_ID)->setRuns(4)->setStatus('active');
        (new \ReflectionProperty(IndustryProjectJobMatch::class, 'id'))->setValue($ramMatch, Uuid::v4());
        $this->ramLine->addJobMatch($ramMatch);

        $jobs = $this->availableJobsFor($this->owner, $this->ishtarProject);

        self::assertSame(
            [
                [self::RAM_JOB_ID, self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 4, 86_250.5, 'Aria Alt', $this->ramLine->getId()->toRfc4122(), 'R.A.M.- Starship Tech', $ramMatch->getId()->toRfc4122()],
                [self::ISHTAR_JOB_ID, self::ISHTAR_TYPE_ID, 'Ishtar', 5, 1_482_350.25, 'Aria Solberg', null, null, null],
            ],
            array_map(static fn (AvailableJobResource $job): array => [
                $job->esiJobId,
                $job->productTypeId,
                $job->productTypeName,
                $job->runs,
                $job->cost,
                $job->characterName,
                $job->linkedToBomLineId,
                $job->linkedToBomLineName,
                $job->matchId,
            ], $jobs),
        );
        self::assertCount(1, $this->jobLookups);
        $blueprintTypeIds = $this->jobLookups[0][0];
        sort($blueprintTypeIds);
        self::assertSame(
            [[self::RAM_BLUEPRINT_TYPE_ID, self::ISHTAR_BLUEPRINT_TYPE_ID], [$this->ownerMainCharacter->getId(), $this->ownerAltCharacter->getId(), $this->adminCharacter->getId()], '2026-11-01T10:00:00+00:00'],
            [$blueprintTypeIds, $this->jobLookups[0][1], $this->jobLookups[0][2]?->format('c')],
        );
    }

    #[Group('issue-1250')]
    public function testASharedJobExposesTheRunsAttributedInAllProjectsAndTheRunsStillFree(): void
    {
        $this->job($this->ownerMainCharacter, self::ISHTAR_JOB_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::ISHTAR_TYPE_ID, 20, 1_000_000.0, '2026-11-02T08:00:00+00:00');
        $ownShare = (new IndustryProjectJobMatch())->setEsiJobId(self::ISHTAR_JOB_ID)->setRuns(5)->setStatus('active');
        (new \ReflectionProperty(IndustryProjectJobMatch::class, 'id'))->setValue($ownShare, Uuid::v4());
        $this->ishtarLine->addJobMatch($ownShare);
        $this->matchesOfOtherProjects[] = (new IndustryProjectJobMatch())->setEsiJobId(self::ISHTAR_JOB_ID)->setRuns(7)->setStatus('active');

        $jobs = $this->availableJobsFor($this->owner, $this->ishtarProject);

        self::assertSame(
            [[self::ISHTAR_JOB_ID, 20, 12, 8, $this->ishtarLine->getId()->toRfc4122(), $ownShare->getId()->toRfc4122()]],
            array_map(static fn (AvailableJobResource $job): array => [$job->esiJobId, $job->runs, $job->linkedRuns, $job->remainingRuns, $job->linkedToBomLineId, $job->matchId], $jobs),
        );
    }

    #[Group('issue-1250')]
    public function testAJobWithoutShareHasAllItsRunsRemainingAndAJobSharedEntirelyElsewhereHasNone(): void
    {
        $this->job($this->ownerMainCharacter, self::ISHTAR_JOB_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::ISHTAR_TYPE_ID, 6, 300_000.0, '2026-11-02T08:00:00+00:00');
        $this->job($this->ownerAltCharacter, self::RAM_JOB_ID, self::RAM_BLUEPRINT_TYPE_ID, self::RAM_TYPE_ID, 4, 86_250.5, '2026-11-03T08:00:00+00:00');
        $this->matchesOfOtherProjects[] = (new IndustryProjectJobMatch())->setEsiJobId(self::RAM_JOB_ID)->setRuns(3)->setStatus('active');
        $this->matchesOfOtherProjects[] = (new IndustryProjectJobMatch())->setEsiJobId(self::RAM_JOB_ID)->setRuns(1)->setStatus('active');

        $jobs = $this->availableJobsFor($this->owner, $this->ishtarProject);

        self::assertSame(
            [[self::RAM_JOB_ID, 4, 4, 0, null], [self::ISHTAR_JOB_ID, 6, 0, 6, null]],
            array_map(static fn (AvailableJobResource $job): array => [$job->esiJobId, $job->runs, $job->linkedRuns, $job->remainingRuns, $job->linkedToBomLineId], $jobs),
        );
    }

    #[Group('issue-1199')]
    public function testEachJobListsOnlyTheLinesOfTheProjectItsBlueprintProduces(): void
    {
        $this->job($this->ownerMainCharacter, self::ISHTAR_JOB_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::ISHTAR_TYPE_ID, 5, 1_482_350.25, '2026-11-02T08:00:00+00:00');
        $this->job($this->ownerAltCharacter, self::RAM_JOB_ID, self::RAM_BLUEPRINT_TYPE_ID, self::RAM_TYPE_ID, 4, 86_250.5, '2026-11-03T08:00:00+00:00');

        $jobs = $this->availableJobsFor($this->owner, $this->ishtarProject);

        self::assertSame(
            [[self::RAM_JOB_ID, [$this->ramLine->getId()->toRfc4122()]], [self::ISHTAR_JOB_ID, [$this->ishtarLine->getId()->toRfc4122()]]],
            array_map(static fn (AvailableJobResource $job): array => [$job->esiJobId, $job->linkableBomLineIds], $jobs),
        );
    }

    #[Group('issue-1199')]
    public function testALineWhoseActivityTheJobDoesNotRunIsNotLinkable(): void
    {
        $this->job($this->ownerMainCharacter, self::ISHTAR_JOB_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::ISHTAR_TYPE_ID, 5, 1_482_350.25, '2026-11-02T08:00:00+00:00');
        $this->linesOfAnotherActivity[] = $this->ishtarLine;

        $jobs = $this->availableJobsFor($this->owner, $this->ishtarProject);

        self::assertSame([[]], array_map(static fn (AvailableJobResource $job): array => $job->linkableBomLineIds, $jobs));
    }

    #[Group('issue-1199')]
    public function testAJobIsLinkedToAnotherProjectOnlyWhenRunsOfOtherProjectsAreAttributedToIt(): void
    {
        $this->job($this->ownerMainCharacter, self::ISHTAR_JOB_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::ISHTAR_TYPE_ID, 20, 1_000_000.0, '2026-11-02T08:00:00+00:00');
        $this->job($this->ownerAltCharacter, self::RAM_JOB_ID, self::RAM_BLUEPRINT_TYPE_ID, self::RAM_TYPE_ID, 4, 86_250.5, '2026-11-03T08:00:00+00:00');
        $this->job($this->adminCharacter, self::UNTOUCHED_JOB_ID, self::RAM_BLUEPRINT_TYPE_ID, self::RAM_TYPE_ID, 4, 86_250.5, '2026-11-04T08:00:00+00:00');
        $ownShare = (new IndustryProjectJobMatch())->setEsiJobId(self::ISHTAR_JOB_ID)->setRuns(5)->setStatus('active');
        (new \ReflectionProperty(IndustryProjectJobMatch::class, 'id'))->setValue($ownShare, Uuid::v4());
        $this->ishtarLine->addJobMatch($ownShare);
        $this->matchesOfOtherProjects[] = (new IndustryProjectJobMatch())->setEsiJobId(self::ISHTAR_JOB_ID)->setRuns(7)->setStatus('active');
        $this->matchesOfOtherProjects[] = (new IndustryProjectJobMatch())->setEsiJobId(self::RAM_JOB_ID)->setRuns(4)->setStatus('active');

        $jobs = $this->availableJobsFor($this->owner, $this->ishtarProject);

        self::assertSame(
            [[self::UNTOUCHED_JOB_ID, false], [self::RAM_JOB_ID, true], [self::ISHTAR_JOB_ID, true]],
            array_map(static fn (AvailableJobResource $job): array => [$job->esiJobId, $job->linkedToAnotherProject], $jobs),
        );
    }

    #[Group('issue-1199')]
    public function testAJobLinkedOnlyInThisProjectIsNotLinkedToAnotherProject(): void
    {
        $this->job($this->ownerMainCharacter, self::ISHTAR_JOB_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::ISHTAR_TYPE_ID, 5, 1_000_000.0, '2026-11-02T08:00:00+00:00');
        $ownShare = (new IndustryProjectJobMatch())->setEsiJobId(self::ISHTAR_JOB_ID)->setRuns(5)->setStatus('active');
        (new \ReflectionProperty(IndustryProjectJobMatch::class, 'id'))->setValue($ownShare, Uuid::v4());
        $this->ishtarLine->addJobMatch($ownShare);

        $jobs = $this->availableJobsFor($this->owner, $this->ishtarProject);

        self::assertSame([false], array_map(static fn (AvailableJobResource $job): bool => $job->linkedToAnotherProject, $jobs));
    }

    public function testJobsWithoutOutputAreLeftOut(): void
    {
        $this->job($this->ownerMainCharacter, self::RAM_JOB_ID, self::RAM_BLUEPRINT_TYPE_ID, self::RAM_TYPE_ID, 4, 86_250.5, '2026-11-03T08:00:00+00:00');
        $this->job($this->ownerMainCharacter, self::CANCELLED_RAM_JOB_ID, self::RAM_BLUEPRINT_TYPE_ID, self::RAM_TYPE_ID, 4, 86_250.5, '2026-11-02T08:00:00+00:00', 'cancelled');

        $jobs = $this->availableJobsFor($this->owner, $this->ishtarProject);

        self::assertSame([self::RAM_JOB_ID], array_map(static fn (AvailableJobResource $job): int => $job->esiJobId, $jobs));
    }

    public function testOwnerListsTheJobsOfEveryAcceptedParticipantAndNotOfAPendingOne(): void
    {
        $pending = $this->userWithPendingRequest('Cyra Lune');

        $this->availableJobsFor($this->owner, $this->ishtarProject);

        self::assertCount(1, $this->jobLookups);
        self::assertEqualsCanonicalizing(
            [$this->ownerMainCharacter->getId(), $this->ownerAltCharacter->getId(), $this->adminCharacter->getId()],
            $this->jobLookups[0][1],
        );
        self::assertNotContains($pending->getCharacters()->first()->getId(), $this->jobLookups[0][1]);
    }

    public function testAcceptedAdminWhoIsNotTheOwnerListsOnlyTheJobsOfHisOwnCharacters(): void
    {
        $this->job($this->adminCharacter, self::ISHTAR_JOB_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::ISHTAR_TYPE_ID, 2, 500_000.0, '2026-11-02T08:00:00+00:00');

        $jobs = $this->availableJobsFor($this->admin, $this->ishtarProject);

        self::assertSame([self::ISHTAR_JOB_ID], array_map(static fn (AvailableJobResource $job): int => $job->esiJobId, $jobs));
        self::assertSame([[$this->adminCharacter->getId()]], array_column($this->jobLookups, 1));
    }

    public function testUserOutsideTheProjectOrWithAPendingRequestIsDenied(): void
    {
        $stranger = new User();
        $this->characterOf($stranger, 'Dax Orlan');
        $pending = $this->userWithPendingRequest('Cyra Lune');

        self::assertSame(
            [self::NO_ACCESS, self::NO_ACCESS, []],
            [
                $this->outcomeOf(fn () => $this->availableJobsFor($stranger, $this->ishtarProject)),
                $this->outcomeOf(fn () => $this->availableJobsFor($pending, $this->ishtarProject)),
                $this->jobLookups,
            ],
        );
    }

    private function userWithPendingRequest(string $characterName): User
    {
        $pending = new User();
        $this->characterOf($pending, $characterName);
        $request = (new IndustryProjectParticipant())->setUser($pending)->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Pending);
        $this->ishtarProject->addMember($request);
        $this->participants[] = $request;

        return $pending;
    }

    private function availableJobsFor(User $user, IndustryProject $project): array
    {
        $jobRepository = $this->createStub(CachedIndustryJobRepository::class);
        $jobRepository->method('findByBlueprintsAndCharacters')->willReturnCallback(
            function (array $blueprintTypeIds, array $characterIds, ?\DateTimeImmutable $startedAfter = null): array {
                $this->jobLookups[] = [array_values($blueprintTypeIds), $characterIds, $startedAfter];

                return $this->cachedJobs;
            },
        );
        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        $productRepository->method('producerOf')->willReturnCallback(
            static fn (int $productTypeId): IndustryActivityProduct => (new IndustryActivityProduct())
                ->setTypeId($productTypeId === self::ISHTAR_TYPE_ID ? self::ISHTAR_BLUEPRINT_TYPE_ID : self::RAM_BLUEPRINT_TYPE_ID)
                ->setActivityId(IndustryActivityType::Manufacturing->value)
                ->setProductTypeId($productTypeId)
                ->setQuantity(1),
        );
        $calculationService = $this->createStub(IndustryCalculationService::class);
        $calculationService->method('resolveTypeName')->willReturnCallback(
            static fn (int $typeId): ?string => [self::ISHTAR_TYPE_ID => 'Ishtar', self::RAM_TYPE_ID => 'R.A.M.- Starship Tech'][$typeId] ?? null,
        );

        $jobMatcher = $this->createStub(IndustryJobMatcher::class);
        $jobMatcher->method('isJobOfBomLineActivity')->willReturnCallback(
            fn (CachedIndustryJob $job, IndustryProjectBomLine $line): bool => !\in_array($line, $this->linesOfAnotherActivity, true),
        );

        return (new AvailableJobsProvider(
            $this->securityOf($user),
            $this->projectRepository(),
            $jobRepository,
            $productRepository,
            $calculationService,
            $this->accessChecker(),
            $this->jobMatchRepository(),
            $jobMatcher,
        ))->provide(new GetCollection(), ['projectId' => $project->getId()->toRfc4122()]);
    }

    private function jobMatchRepository(): IndustryProjectJobMatchRepository
    {
        $repository = $this->createStub(IndustryProjectJobMatchRepository::class);
        $repository->method('findByEsiJobIds')->willReturnCallback(function (array $esiJobIds): array {
            $matches = $this->matchesOfOtherProjects;
            foreach ($this->ishtarProject->getBomItems() as $line) {
                foreach ($line->getJobMatches() as $match) {
                    $matches[] = $match;
                }
            }

            return array_values(array_filter(
                $matches,
                static fn (IndustryProjectJobMatch $match): bool => \in_array($match->getEsiJobId(), $esiJobIds, true),
            ));
        });

        return $repository;
    }

    private function job(Character $installer, int $jobId, int $blueprintTypeId, int $productTypeId, int $runs, float $cost, string $startDate, string $status = 'active'): void
    {
        $start = new \DateTimeImmutable($startDate);
        $this->cachedJobs[] = (new CachedIndustryJob())
            ->setCharacter($installer)
            ->setJobId($jobId)
            ->setActivityId(1)
            ->setBlueprintTypeId($blueprintTypeId)
            ->setProductTypeId($productTypeId)
            ->setRuns($runs)
            ->setCost($cost)
            ->setStatus($status)
            ->setStartDate($start)
            ->setEndDate($start->modify('+2 days'));
    }
}
