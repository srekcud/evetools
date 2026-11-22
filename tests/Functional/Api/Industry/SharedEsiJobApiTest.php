<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\UnassignedJob;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Tests\Functional\Api\Industry\Project\BuildsIshtarGroupProject;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1250')]
final class SharedEsiJobApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int ISHTAR_BLUEPRINT_TYPE_ID = 12006;
    private const int SHARED_JOB_ID = 7_250_201;
    private const int SHARED_JOB_RUNS = 20;
    private const float SHARED_JOB_COST = 1_000_000.0;
    private const string UNASSIGNED_URI = '/api/industry/unassigned-jobs';

    private IndustryProject $secondIshtarBatch;
    private IndustryProjectBomLine $secondBatchIshtarLine;
    private IndustryProjectBomLine $secondBatchSpareIshtarLine;

    private const int RAM_BLUEPRINT_TYPE_ID = 11479;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->em->persist((new IndustryActivityProduct())->setTypeId(self::ISHTAR_BLUEPRINT_TYPE_ID)->setActivityId(1)->setProductTypeId(self::ISHTAR_TYPE_ID)->setQuantity(1));
        $this->em->persist((new IndustryActivityProduct())->setTypeId(self::RAM_BLUEPRINT_TYPE_ID)->setActivityId(1)->setProductTypeId(self::RAM_STARSHIP_TECH_TYPE_ID)->setQuantity(100));
        $this->secondIshtarBatch = $this->persistProject('Second Ishtar batch');
        $this->addMembership($this->secondIshtarBatch, $this->owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->secondBatchIshtarLine = $this->addJobLine($this->secondIshtarBatch, self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 2, 2, 4, 8, null);
        $this->secondBatchSpareIshtarLine = $this->addJobLine($this->secondIshtarBatch, self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 2, 2, 4, 8, null);
        $this->em->persist($this->sharedJob());
        $this->em->flush();
    }

    public function testTwoProjectsOfTheOwnerEachLinkHalfOfTheJobAtHalfOfItsCostAndAThirdLinkIsRefused(): void
    {
        $this->linkJob($this->ishtarBatch, $this->ishtarJobLine, 10);
        self::assertResponseStatusCodeSame(201);
        self::assertSame([self::SHARED_JOB_ID, 10], [$this->responseJson()['esiJobId'], $this->responseJson()['runs']]);

        $this->linkJob($this->secondIshtarBatch, $this->secondBatchIshtarLine, 10);
        self::assertResponseStatusCodeSame(201);
        self::assertSame([self::SHARED_JOB_ID, 10], [$this->responseJson()['esiJobId'], $this->responseJson()['runs']]);

        $this->linkJob($this->secondIshtarBatch, $this->secondBatchSpareIshtarLine, 1);
        self::assertResponseStatusCodeSame(400);
        self::assertSame([400, 'ESI job 7250201 is already linked to a BOM line'], $this->errorDetail());
        self::assertSame([[10, 500_000.0]], $this->storedSharesOf($this->ishtarJobLine));
        self::assertSame([[10, 500_000.0]], $this->storedSharesOf($this->secondBatchIshtarLine));
        self::assertSame([], $this->storedSharesOf($this->secondBatchSpareIshtarLine));
    }

    public function testTheAvailableJobsOfAProjectShowTheRunsAttributedInAnotherProjectAndTheRunsStillFree(): void
    {
        $this->linkJob($this->secondIshtarBatch, $this->secondBatchIshtarLine, 8);
        self::assertResponseStatusCodeSame(201);
        $this->linkJob($this->ishtarBatch, $this->ishtarJobLine, 5);
        self::assertResponseStatusCodeSame(201);

        $this->actAs($this->owner);
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/available-jobs');

        self::assertResponseStatusCodeSame(200);
        $jobs = $this->responseJson();
        self::assertCount(1, $jobs);
        self::assertSame(
            [self::SHARED_JOB_ID, self::SHARED_JOB_RUNS, 13, 7, $this->idOf($this->ishtarJobLine)],
            [$jobs[0]['esiJobId'], $jobs[0]['runs'], $jobs[0]['linkedRuns'], $jobs[0]['remainingRuns'], $jobs[0]['linkedToBomLineId']],
        );
    }

    public function testAssigningAnUnassignedJobWithRunsLinksOnlyThatShareAtItsCostAndTheJobLeavesTheList(): void
    {
        $this->em->persist((new UnassignedJob())->setJob($this->em->getRepository(CachedIndustryJob::class)->findOneBy(['jobId' => self::SHARED_JOB_ID]))->setCandidates([[
            'projectId' => $this->ishtarBatch->getId()->toRfc4122(),
            'lineId' => $this->idOf($this->ishtarJobLine),
            'score' => 4,
            'signals' => ['intent'],
            'oversized' => false,
        ]]));
        $this->em->flush();

        $this->actAs($this->owner);
        $this->request('POST', self::UNASSIGNED_URI.'/'.self::SHARED_JOB_ID.'/assign', ['lineId' => $this->idOf($this->ishtarJobLine), 'runs' => 8]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame([self::SHARED_JOB_ID, 8], [$this->responseJson()['esiJobId'], $this->responseJson()['runs']]);
        self::assertSame([[8, 400_000.0]], $this->storedSharesOf($this->ishtarJobLine));
        $this->actAs($this->owner);
        $this->request('GET', self::UNASSIGNED_URI);
        self::assertSame([], $this->responseJson());
    }

    public function testTheRunsOfALinkMustBePositiveAndCannotExceedTheRunsOfTheJobWhateverTheInteger(): void
    {
        $this->linkJob($this->ishtarBatch, $this->ishtarJobLine, 0);
        self::assertResponseStatusCodeSame(422);
        self::assertContains('runs', $this->violatedPaths());

        $this->linkJob($this->ishtarBatch, $this->ishtarJobLine, 2_147_483_648);
        self::assertResponseStatusCodeSame(400);

        $this->linkJob($this->ishtarBatch, $this->ishtarJobLine, self::SHARED_JOB_RUNS + 1);
        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->storedSharesOf($this->ishtarJobLine));
    }

    private function linkJob(IndustryProject $project, IndustryProjectBomLine $line, int $runs): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->projectUri($project).'/bom/'.$this->idOf($line).'/link-job', ['esiJobId' => self::SHARED_JOB_ID, 'runs' => $runs]);
    }

    private function sharedJob(): CachedIndustryJob
    {
        return (new CachedIndustryJob())
            ->setCharacter($this->owner->getMainCharacter())
            ->setJobId(self::SHARED_JOB_ID)
            ->setActivityId(1)
            ->setBlueprintTypeId(self::ISHTAR_BLUEPRINT_TYPE_ID)
            ->setProductTypeId(self::ISHTAR_TYPE_ID)
            ->setRuns(self::SHARED_JOB_RUNS)
            ->setCost(self::SHARED_JOB_COST)
            ->setStatus('active')
            ->setStartDate(new \DateTimeImmutable('+1 hour'))
            ->setEndDate(new \DateTimeImmutable('+3 days'));
    }

    private function storedSharesOf(IndustryProjectBomLine $line): array
    {
        $this->em->clear();
        $matches = $this->em->getRepository(IndustryProjectJobMatch::class)->findBy(['bomLine' => $line->getId()], ['esiJobId' => 'ASC']);

        return array_map(static fn (IndustryProjectJobMatch $match): array => [$match->getRuns(), $match->getCost()], $matches);
    }
}
