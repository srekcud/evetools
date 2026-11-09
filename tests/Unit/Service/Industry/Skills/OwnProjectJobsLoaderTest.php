<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Skills;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\Service\Industry\Skills\OwnProjectJobs;
use App\Service\Industry\Skills\OwnProjectJobsLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(OwnProjectJobsLoader::class)]
#[Group('issue-359')]
final class OwnProjectJobsLoaderTest extends TestCase
{
    private const string PILOT = 'Pilot Mara';
    private const string OTHER_PILOT = 'Pilot Other';

    private User $user;
    private Character $pilot;
    private array $cachedJobsById = [];
    private array $projects = [];
    private array $requestedJobIds = [];
    private array $requestedPeriods = [];

    protected function setUp(): void
    {
        $this->user = new User();
        $this->pilot = (new Character())->setName(self::PILOT)->setEveCharacterId(2_112_000_401);
        $this->user->addCharacter($this->pilot);
    }

    public function testKeepsTheCachedJobsLinkedToTheCharacterOfTheProjectsOfThePeriod(): void
    {
        $this->cachedJobsById = [101 => $this->job(101), 102 => $this->job(102)];
        $this->projects = [$this->project([[101, self::PILOT], [102, self::PILOT]])];

        $loaded = $this->load();

        $this->assertSame([[101, 102]], [array_map(static fn (CachedIndustryJob $job): int => $job->getJobId(), $loaded->jobs)]);
        $this->assertTrue($loaded->complete);
    }

    public function testIgnoresTheJobsLinkedToAnotherCharacter(): void
    {
        $this->cachedJobsById = [101 => $this->job(101), 201 => $this->job(201)];
        $this->projects = [$this->project([[101, self::PILOT], [201, self::OTHER_PILOT]])];

        $loaded = $this->load();

        $this->assertSame([101], $this->requestedJobIds);
        $this->assertCount(1, $loaded->jobs);
    }

    public function testIgnoresALinkWithoutCharacterName(): void
    {
        $this->cachedJobsById = [101 => $this->job(101)];
        $this->projects = [$this->project([[101, null]])];

        $loaded = $this->load();

        $this->assertSame([[], true], [$loaded->jobs, $loaded->complete]);
    }

    public function testCountsAJobLinkedTwiceOnce(): void
    {
        $this->cachedJobsById = [101 => $this->job(101)];
        $this->projects = [$this->project([[101, self::PILOT]]), $this->project([[101, self::PILOT]])];

        $loaded = $this->load();

        $this->assertSame([101], $this->requestedJobIds);
        $this->assertCount(1, $loaded->jobs);
    }

    public function testIsIncompleteWhenALinkedJobIsNotInTheCache(): void
    {
        $this->cachedJobsById = [101 => $this->job(101)];
        $this->projects = [$this->project([[101, self::PILOT], [102, self::PILOT]])];

        $loaded = $this->load();

        $this->assertFalse($loaded->complete);
        $this->assertSame([101], array_map(static fn (CachedIndustryJob $job): int => $job->getJobId(), $loaded->jobs));
    }

    public function testASingleLinkedJobMissingFromTheCacheIsEnoughToBeIncomplete(): void
    {
        $this->projects = [$this->project([[101, self::PILOT]])];

        $loaded = $this->load();

        $this->assertSame([[], false], [$loaded->jobs, $loaded->complete]);
    }

    public function testAsksForTheProjectsCompletedInTheGivenPeriod(): void
    {
        $this->load();

        $this->assertSame([[$this->user, '2026-10-10', '2026-11-09']], $this->requestedPeriods);
    }

    private function job(int $jobId): CachedIndustryJob
    {
        return (new CachedIndustryJob())->setJobId($jobId);
    }

    private function project(array $links): IndustryProjectParticipant
    {
        $project = new IndustryProject();
        $bomLine = new IndustryProjectBomLine();
        $project->addBomItem($bomLine);
        foreach ($links as [$esiJobId, $characterName]) {
            $bomLine->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId($esiJobId)->setCharacterName($characterName));
        }

        return (new IndustryProjectParticipant())->setProject($project)->setUser($this->user);
    }

    private function load(): OwnProjectJobs
    {
        $participants = $this->createStub(IndustryProjectParticipantRepository::class);
        $participants->method('findAcceptedOnProjectsCompletedBetween')->willReturnCallback(
            function (User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array {
                $this->requestedPeriods[] = [$user, $from->format('Y-m-d'), $to->format('Y-m-d')];

                return $this->projects;
            },
        );

        $cachedJobs = $this->createStub(CachedIndustryJobRepository::class);
        $cachedJobs->method('findByJobIds')->willReturnCallback(function (array $jobIds): array {
            $this->requestedJobIds = $jobIds;

            return array_intersect_key($this->cachedJobsById, array_flip($jobIds));
        });

        return (new OwnProjectJobsLoader($participants, $cachedJobs))->loadFor(
            $this->user,
            $this->pilot,
            new \DateTimeImmutable('2026-10-10'),
            new \DateTimeImmutable('2026-11-09'),
        );
    }
}
