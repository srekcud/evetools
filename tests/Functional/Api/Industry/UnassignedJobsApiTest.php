<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProjectActivity;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\UnassignedJob;
use App\Entity\User;
use App\Enum\ProjectActivityKind;
use App\Tests\Functional\Api\Industry\Project\BuildsIshtarGroupProject;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1248')]
final class UnassignedJobsApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int ISHTAR_BLUEPRINT_TYPE_ID = 12006;
    private const int RAM_BLUEPRINT_TYPE_ID = 11479;
    private const int OWNER_JOB_ID = 7_248_301;
    private const int MEMBER_JOB_ID = 7_248_302;
    private const int OUTSIDER_JOB_ID = 7_248_303;
    private const int FIRST_OUT_OF_INT4_JOB_ID = 2_147_483_648;
    private const string LIST_URI = '/api/industry/unassigned-jobs';

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->em->persist((new IndustryActivityProduct())->setTypeId(self::ISHTAR_BLUEPRINT_TYPE_ID)->setActivityId(1)->setProductTypeId(self::ISHTAR_TYPE_ID)->setQuantity(1));
        $this->em->persist((new IndustryActivityProduct())->setTypeId(self::RAM_BLUEPRINT_TYPE_ID)->setActivityId(1)->setProductTypeId(self::RAM_STARSHIP_TECH_TYPE_ID)->setQuantity(100));
        $this->givenUnassignedJobOf($this->owner, self::OWNER_JOB_ID, 2, '2026-11-02T08:00:00+00:00');
        $this->givenUnassignedJobOf($this->member, self::MEMBER_JOB_ID, 1, '2026-11-03T08:00:00+00:00');
        $this->givenUnassignedJobOf($this->outsider, self::OUTSIDER_JOB_ID, 2, '2026-11-04T08:00:00+00:00');
        $this->em->flush();
    }

    public function testTheThreeEndpointsRequireAuthentication(): void
    {
        $lineId = $this->idOf($this->ishtarJobLine);
        foreach ([
            ['GET', self::LIST_URI, null],
            ['POST', self::LIST_URI.'/'.self::OWNER_JOB_ID.'/assign', ['lineId' => $lineId]],
            ['POST', self::LIST_URI.'/'.self::OWNER_JOB_ID.'/dismiss', []],
        ] as [$method, $uri, $body]) {
            $this->request($method, $uri, $body);

            self::assertResponseStatusCodeSame(401, $method.' '.$uri);
        }
        self::assertSame([], $this->storedJobIdsOf($this->ishtarJobLine));
    }

    public function testTheListCarriesTheJobOfTheUserWithItsCandidateLinesAndExplicitNulls(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', self::LIST_URI);

        self::assertResponseStatusCodeSame(200);
        $jobs = $this->responseJson();
        self::assertCount(1, $jobs);
        $job = $jobs[0];
        self::assertSame(
            [
                self::OWNER_JOB_ID,
                'Aria Solberg',
                self::ISHTAR_BLUEPRINT_TYPE_ID,
                self::ISHTAR_TYPE_ID,
                2,
                1,
                '2026-11-02T08:00:00+00:00',
                '2026-11-04T08:00:00+00:00',
                'active',
                [[
                    'projectId' => $this->ishtarBatch->getId()->toRfc4122(),
                    'projectName' => 'Ishtar batch',
                    'lineId' => $this->idOf($this->ishtarJobLine),
                    'lineName' => 'Ishtar',
                    'remainingRuns' => 2,
                    'score' => 4,
                    'signals' => ['intent', 'place'],
                    'oversized' => false,
                ]],
            ],
            [
                $job['esiJobId'],
                $job['characterName'],
                $job['blueprintTypeId'],
                $job['productTypeId'],
                $job['runs'],
                $job['activityId'],
                $job['startDate'],
                $job['endDate'],
                $job['status'],
                $job['candidates'],
            ],
        );
        self::assertArrayHasKey('stationId', $job);
        self::assertNull($job['stationId']);
        self::assertArrayHasKey('stationName', $job);
        self::assertNull($job['stationName']);
    }

    public function testEachUserOnlyListsTheJobsOfHisOwnCharactersAndAnOutsiderListsNothing(): void
    {
        $listed = [];
        foreach ([$this->owner, $this->member, $this->outsider, $this->pendingMember] as $user) {
            $this->call($user, 'GET', self::LIST_URI);

            self::assertResponseStatusCodeSame(200);
            $listed[] = array_column($this->responseJson(), 'esiJobId');
        }

        self::assertSame([[self::OWNER_JOB_ID], [self::MEMBER_JOB_ID], [], []], $listed);
    }

    public function testOwnerAssignsTheJobToACandidateLineAsAManualLinkAndTheJobLeavesTheList(): void
    {
        $this->call($this->owner, 'POST', self::LIST_URI.'/'.self::OWNER_JOB_ID.'/assign', ['lineId' => $this->idOf($this->ishtarJobLine)]);

        self::assertResponseStatusCodeSame(201);
        $response = $this->responseJson();
        self::assertSame(
            [self::OWNER_JOB_ID, 2, true, $this->idOf($this->ishtarJobLine), 'Aria Solberg'],
            [$response['esiJobId'], $response['runs'], $response['manual'], $response['bomLineId'], $response['characterName']],
        );
        self::assertSame([self::OWNER_JOB_ID], $this->storedJobIdsOf($this->ishtarJobLine));
        self::assertSame([true], $this->storedManualFlagsOf($this->ishtarJobLine));
        self::assertSame([[self::OWNER_JOB_ID]], $this->recordedDetectedJobIdsOf());
        self::assertFalse($this->storedRowExists(self::OWNER_JOB_ID));
        $this->call($this->owner, 'GET', self::LIST_URI);
        self::assertSame([], $this->responseJson());
    }

    public function testAssigningTwiceAnswersNotFoundTheSecondTime(): void
    {
        $assignUri = self::LIST_URI.'/'.self::OWNER_JOB_ID.'/assign';
        $this->call($this->owner, 'POST', $assignUri, ['lineId' => $this->idOf($this->ishtarJobLine)]);
        self::assertResponseStatusCodeSame(201);

        $this->call($this->owner, 'POST', $assignUri, ['lineId' => $this->idOf($this->ishtarJobLine)]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([self::OWNER_JOB_ID], $this->storedJobIdsOf($this->ishtarJobLine));
    }

    public function testAssigningTheJobOfAnotherUserIsNotFoundAndLeavesItListed(): void
    {
        $this->call($this->member, 'POST', self::LIST_URI.'/'.self::OWNER_JOB_ID.'/assign', ['lineId' => $this->idOf($this->ishtarJobLine)]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->storedJobIdsOf($this->ishtarJobLine));
        self::assertTrue($this->storedRowExists(self::OWNER_JOB_ID));
        $this->call($this->owner, 'GET', self::LIST_URI);
        self::assertSame([self::OWNER_JOB_ID], array_column($this->responseJson(), 'esiJobId'));
    }

    public function testAssigningToALineThatIsNotACandidateIsRefusedAndLinksNothing(): void
    {
        $this->call($this->owner, 'POST', self::LIST_URI.'/'.self::OWNER_JOB_ID.'/assign', ['lineId' => $this->idOf($this->ramJobLine)]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->storedJobIdsOf($this->ramJobLine));
        self::assertTrue($this->storedRowExists(self::OWNER_JOB_ID));
    }

    public function testAssignRefusesABodyWithoutLineIdOrWithAMalformedOne(): void
    {
        foreach ([[], ['lineId' => ''], ['lineId' => self::NOT_A_UUID]] as $body) {
            $this->call($this->owner, 'POST', self::LIST_URI.'/'.self::OWNER_JOB_ID.'/assign', $body);

            self::assertResponseStatusCodeSame(422, json_encode($body));
            self::assertContains('lineId', $this->violatedPaths());
        }
        self::assertTrue($this->storedRowExists(self::OWNER_JOB_ID));
    }

    public function testDismissingAJobKeepsItsRowAndRemovesItFromTheListOfItsUserOnly(): void
    {
        $this->call($this->owner, 'POST', self::LIST_URI.'/'.self::OWNER_JOB_ID.'/dismiss', []);

        self::assertResponseStatusCodeSame(204);
        self::assertTrue($this->storedRowExists(self::OWNER_JOB_ID));
        self::assertTrue($this->storedRowIsDismissed(self::OWNER_JOB_ID));
        self::assertFalse($this->storedRowIsDismissed(self::MEMBER_JOB_ID));
        $this->call($this->owner, 'GET', self::LIST_URI);
        self::assertSame([], $this->responseJson());
        $this->call($this->member, 'GET', self::LIST_URI);
        self::assertSame([self::MEMBER_JOB_ID], array_column($this->responseJson(), 'esiJobId'));
    }

    public function testAssigningADismissedJobIsNotFoundAndDismissingTheJobOfAnotherUserIsNotFound(): void
    {
        $this->call($this->owner, 'POST', self::LIST_URI.'/'.self::OWNER_JOB_ID.'/dismiss', []);
        self::assertResponseStatusCodeSame(204);

        $this->call($this->owner, 'POST', self::LIST_URI.'/'.self::OWNER_JOB_ID.'/assign', ['lineId' => $this->idOf($this->ishtarJobLine)]);
        self::assertResponseStatusCodeSame(404);

        $this->call($this->owner, 'POST', self::LIST_URI.'/'.self::MEMBER_JOB_ID.'/dismiss', []);
        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->storedRowIsDismissed(self::MEMBER_JOB_ID));
        self::assertSame([], $this->storedJobIdsOf($this->ishtarJobLine));
    }

    public function testAnEsiJobIdBeyondTheIntegerRangeOfTheCacheIsNotFoundWhateverTheEndpoint(): void
    {
        $statuses = [];
        foreach ([
            ['/assign', ['lineId' => $this->idOf($this->ishtarJobLine)]],
            ['/dismiss', []],
        ] as [$suffix, $body]) {
            $this->call($this->owner, 'POST', self::LIST_URI.'/'.self::FIRST_OUT_OF_INT4_JOB_ID.$suffix, $body);
            $statuses[] = $this->client->getResponse()->getStatusCode();
        }

        self::assertSame([404, 404], $statuses);
    }

    private function call(User $user, string $method, string $uri, ?array $body = null): void
    {
        $this->actAs($user);
        $this->request($method, $uri, $body);
    }

    private function givenUnassignedJobOf(User $user, int $esiJobId, int $runs, string $startDate): void
    {
        $job = (new CachedIndustryJob())
            ->setCharacter($user->getMainCharacter())
            ->setJobId($esiJobId)
            ->setActivityId(1)
            ->setBlueprintTypeId(self::ISHTAR_BLUEPRINT_TYPE_ID)
            ->setProductTypeId(self::ISHTAR_TYPE_ID)
            ->setRuns($runs)
            ->setCost(1_482_350.25)
            ->setStatus('active')
            ->setStartDate(new \DateTimeImmutable($startDate))
            ->setEndDate((new \DateTimeImmutable($startDate))->modify('+2 days'));
        $this->em->persist($job);
        $this->em->persist((new UnassignedJob())->setJob($job)->setCandidates([[
            'projectId' => $this->ishtarBatch->getId()->toRfc4122(),
            'lineId' => $this->idOf($this->ishtarJobLine),
            'score' => 4,
            'signals' => ['intent', 'place'],
            'oversized' => false,
        ]]));
    }

    private function storedJobIdsOf(IndustryProjectBomLine $line): array
    {
        return array_map(static fn (IndustryProjectJobMatch $match): int => $match->getEsiJobId(), $this->storedMatchesOf($line));
    }

    private function storedManualFlagsOf(IndustryProjectBomLine $line): array
    {
        return array_map(static fn (IndustryProjectJobMatch $match): bool => $match->isManual(), $this->storedMatchesOf($line));
    }

    private function storedMatchesOf(IndustryProjectBomLine $line): array
    {
        $this->em->clear();

        return $this->em->getRepository(IndustryProjectJobMatch::class)->findBy(['bomLine' => $line->getId()], ['esiJobId' => 'ASC']);
    }

    private function storedRow(int $esiJobId): ?UnassignedJob
    {
        $this->em->clear();

        return $this->em->createQuery('SELECT u FROM '.UnassignedJob::class.' u JOIN u.job j WHERE j.jobId = :jobId')
            ->setParameter('jobId', $esiJobId)
            ->getOneOrNullResult();
    }

    private function storedRowExists(int $esiJobId): bool
    {
        return $this->storedRow($esiJobId) !== null;
    }

    private function storedRowIsDismissed(int $esiJobId): bool
    {
        return $this->storedRow($esiJobId)?->getDismissedAt() !== null;
    }

    private function recordedDetectedJobIdsOf(): array
    {
        $this->em->clear();
        $activities = $this->em->getRepository(IndustryProjectActivity::class)->findBy(['project' => $this->ishtarBatch->getId(), 'kind' => ProjectActivityKind::JobsDetected]);

        return array_map(static fn (IndustryProjectActivity $activity): array => $activity->getData()['jobIds'], $activities);
    }
}
