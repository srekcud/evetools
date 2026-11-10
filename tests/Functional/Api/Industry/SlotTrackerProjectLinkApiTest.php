<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\User;
use App\Tests\Functional\Api\Industry\Project\BuildsIshtarGroupProject;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-539')]
final class SlotTrackerProjectLinkApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int OWNER_JOB_ID = 7_539_001;
    private const int OUTSIDER_JOB_ID = 7_539_002;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->installJob($this->owner, self::OWNER_JOB_ID);
        $this->installJob($this->outsider, self::OUTSIDER_JOB_ID);
        $this->ishtarJobLine->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(self::OWNER_JOB_ID)->setRuns(self::ISHTAR_RUNS));
        $this->ishtarJobLine->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(self::OUTSIDER_JOB_ID)->setRuns(self::ISHTAR_RUNS));
        $this->em->flush();
    }

    public function testTheOwnerSeesTheProjectOfAMatchedJobOnTheCharacterJobAndTheTimeline(): void
    {
        $this->actAs($this->owner);
        $this->client->request('GET', '/api/industry/slots', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        $expectedProject = ['id' => $this->ishtarBatch->getId()->toRfc4122(), 'name' => 'Ishtar batch'];
        self::assertSame([self::OWNER_JOB_ID], array_column($body['characters'][0]['jobs'], 'jobId'));
        self::assertSame($expectedProject, $body['characters'][0]['jobs'][0]['project']);
        self::assertSame([self::OWNER_JOB_ID], array_column($body['timeline'], 'jobId'));
        self::assertSame($expectedProject, $body['timeline'][0]['project']);
    }

    public function testAnOutsiderGetsANullProjectForAJobMatchedToAProjectHeCannotSee(): void
    {
        $this->actAs($this->outsider);
        $this->client->request('GET', '/api/industry/slots', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        self::assertSame([self::OUTSIDER_JOB_ID], array_column($body['characters'][0]['jobs'], 'jobId'));
        self::assertArrayHasKey('project', $body['characters'][0]['jobs'][0]);
        self::assertNull($body['characters'][0]['jobs'][0]['project']);
        self::assertArrayHasKey('project', $body['timeline'][0]);
        self::assertNull($body['timeline'][0]['project']);
        self::assertStringNotContainsString($this->ishtarBatch->getId()->toRfc4122(), (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Ishtar batch', (string) $this->client->getResponse()->getContent());
    }

    private function installJob(User $pilot, int $esiJobId): void
    {
        $this->em->persist(
            (new CachedIndustryJob())
                ->setCharacter($pilot->getMainCharacter())
                ->setJobId($esiJobId)
                ->setActivityId(1)
                ->setBlueprintTypeId(self::ISHTAR_TYPE_ID)
                ->setProductTypeId(self::ISHTAR_TYPE_ID)
                ->setRuns(self::ISHTAR_RUNS)
                ->setCost(1_000_000.0)
                ->setStatus('active')
                ->setStartDate(new \DateTimeImmutable('-1 hour'))
                ->setEndDate(new \DateTimeImmutable('+30 days'))
                ->setCachedAt(new \DateTimeImmutable()),
        );
    }
}
