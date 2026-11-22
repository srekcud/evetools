<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ProjectStatus;
use App\Service\Mercure\MercurePublisherService;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[Group('issue-1315')]
final class ProjectSurplusStorageApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const float TRITANIUM_SURPLUS_VALUE = 2_125.0;
    private const string STORED_AT = '2026-11-22T09:30:00+00:00';
    private const string SURPLUS_STORED = 'surplus_stored';
    private const int ONE_MINUTE = 60;

    private array $publishedUpdates = [];

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->addContribution($this->ownerMembership, $this->tritaniumLine, ContributionType::Material, 12_500, 53_125.0, ContributionStatus::Approved);
        $this->ishtarBatch->setStatus(ProjectStatus::Selling);
        $this->em->flush();

        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'urn:uuid:published';
        });
        self::getContainer()->set(MercurePublisherService::class, new MercurePublisherService($hub, new NullLogger()));
    }

    public function testOwnerStoresTheSurplusOfASellingProjectAndTheResponseIsTheSurplusReadBack(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->storageUri($this->ishtarBatch));

        self::assertResponseStatusCodeSame(200);
        $surplus = $this->responseJson();
        self::assertSame($this->ishtarBatch->getId()->toRfc4122(), $surplus['id']);
        self::assertTrue($surplus['stored']);
        self::assertSame('Aria Solberg', $surplus['storedBy']);
        self::assertEqualsWithDelta(time(), (new \DateTimeImmutable($surplus['storedAt']))->getTimestamp(), self::ONE_MINUTE);
        self::assertSame(self::TRITANIUM_SURPLUS_VALUE, $surplus['totalValue']);
        self::assertSame([self::TRITANIUM_TYPE_ID], array_column($surplus['lines'], 'typeId'));

        $this->em->clear();
        $stored = $this->em->find(IndustryProject::class, $this->ishtarBatch->getId());
        self::assertNotNull($stored->getSurplusStoredAt());
        self::assertSame($this->owner->getId()->toRfc4122(), $stored->getSurplusStoredBy()?->getId()->toRfc4122());
    }

    public function testStoringPublishesASurplusStoredEventOnTheProjectTopic(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->storageUri($this->ishtarBatch));
        self::assertResponseStatusCodeSame(200);

        $projectId = $this->ishtarBatch->getId()->toRfc4122();
        $events = array_values(array_filter(array_map(
            static fn (Update $update): array => ['topic' => $update->getTopics()[0], 'payload' => json_decode($update->getData(), true)],
            $this->publishedUpdates,
        ), static fn (array $event): bool => self::SURPLUS_STORED === $event['payload']['action']));

        self::assertCount(1, $events);
        self::assertSame('/group-project/'.$projectId.'/events', $events[0]['topic']);
        self::assertSame($projectId, $events[0]['payload']['projectId']);
    }

    public function testAdminOfTheProjectStoresTheSurplusOfACompletedProject(): void
    {
        $this->memberMembership->setRole(ParticipantRole::Admin);
        $this->ishtarBatch->setStatus(ProjectStatus::Completed);
        $this->em->flush();

        $this->actAs($this->member);
        $this->request('POST', $this->storageUri($this->ishtarBatch));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Brann Holt', true], [$this->responseJson()['storedBy'], $this->responseJson()['stored']]);
    }

    public function testSimpleMemberIsRefusedAndNothingIsStoredNorPublished(): void
    {
        $this->actAs($this->member);
        $this->request('POST', $this->storageUri($this->ishtarBatch));

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNull($this->em->find(IndustryProject::class, $this->ishtarBatch->getId())->getSurplusStoredAt());
        self::assertSame([], $this->publishedUpdates);
    }

    public function testPilotWhoDoesNotTakePartInTheProjectIsRefusedLikeEveryOtherAccess(): void
    {
        $this->actAs($this->outsider);
        $this->request('POST', $this->storageUri($this->ishtarBatch));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testStoringTheSurplusOfAnUnknownProjectIsNotFound(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', '/api/industry/projects/'.Uuid::v7()->toRfc4122().'/surplus-storage');

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
    }

    public function testProjectStillInProgressOrOnlyPublishedCannotStoreItsSurplus(): void
    {
        foreach ([ProjectStatus::InProgress, ProjectStatus::Published] as $status) {
            $this->ishtarBatch->setStatus($status);
            $this->em->flush();

            $this->actAs($this->owner);
            $this->request('POST', $this->storageUri($this->ishtarBatch));

            self::assertResponseStatusCodeSame(409, $status->value);
        }
        $this->em->clear();
        self::assertNull($this->em->find(IndustryProject::class, $this->ishtarBatch->getId())->getSurplusStoredAt());
        self::assertSame([], $this->publishedUpdates);
    }

    public function testProjectWithoutAnySurplusLineCannotStoreIt(): void
    {
        $this->hecateBatch->setStatus(ProjectStatus::Selling);
        $this->em->flush();

        $this->actAs($this->owner);
        $this->request('POST', $this->storageUri($this->hecateBatch));

        self::assertResponseStatusCodeSame(409);
        $this->em->clear();
        self::assertNull($this->em->find(IndustryProject::class, $this->hecateBatch->getId())->getSurplusStoredAt());
        self::assertSame([], $this->publishedUpdates);
    }

    public function testSurplusAlreadyStoredCannotBeStoredAgainAndKeepsItsFirstDeclaration(): void
    {
        $this->ishtarBatch->markSurplusStored($this->owner, new \DateTimeImmutable(self::STORED_AT));
        $this->em->flush();

        $this->actAs($this->owner);
        $this->request('POST', $this->storageUri($this->ishtarBatch));

        self::assertResponseStatusCodeSame(409);
        $this->em->clear();
        self::assertSame(self::STORED_AT, $this->em->find(IndustryProject::class, $this->ishtarBatch->getId())->getSurplusStoredAt()?->format(\DATE_ATOM));
        self::assertSame([], $this->publishedUpdates);
    }

    public function testSurplusReadBeforeAnyStorageIsNotStoredWithNullKeys(): void
    {
        $this->actAs($this->member);
        $this->request('GET', $this->surplusUri($this->ishtarBatch));

        self::assertResponseStatusCodeSame(200);
        $surplus = $this->responseJson();
        self::assertFalse($surplus['stored']);
        self::assertArrayHasKey('storedAt', $surplus);
        self::assertNull($surplus['storedAt']);
        self::assertArrayHasKey('storedBy', $surplus);
        self::assertNull($surplus['storedBy']);
        self::assertSame(self::TRITANIUM_SURPLUS_VALUE, $surplus['totalValue']);
    }

    public function testSurplusReadAfterStorageGivesWhenAndByWhom(): void
    {
        $this->ishtarBatch->markSurplusStored($this->owner, new \DateTimeImmutable(self::STORED_AT));
        $this->em->flush();

        $this->actAs($this->member);
        $this->request('GET', $this->surplusUri($this->ishtarBatch));

        self::assertResponseStatusCodeSame(200);
        $surplus = $this->responseJson();
        self::assertSame([true, self::STORED_AT, 'Aria Solberg'], [$surplus['stored'], $surplus['storedAt'], $surplus['storedBy']]);
        self::assertSame(self::TRITANIUM_SURPLUS_VALUE, $surplus['totalValue']);
    }

    public function testSurplusStoredByADeletedAccountKeepsItsDateWithoutAName(): void
    {
        $storer = $this->persistPilot('Fenn Dray', self::OWNER_CORPORATION_ID);
        $this->em->flush();
        $this->ishtarBatch->markSurplusStored($storer, new \DateTimeImmutable(self::STORED_AT));
        $this->em->flush();
        $this->em->remove($storer);
        $this->em->flush();
        $this->em->clear();

        $this->actAs($this->member);
        $this->request('GET', $this->surplusUri($this->ishtarBatch));

        self::assertResponseStatusCodeSame(200);
        $surplus = $this->responseJson();
        self::assertTrue($surplus['stored']);
        self::assertSame(self::STORED_AT, $surplus['storedAt']);
        self::assertArrayHasKey('storedBy', $surplus);
        self::assertNull($surplus['storedBy']);
    }

    public function testDistributionCountsTheStoredSurplusValueAndNothingBeforeItIsStored(): void
    {
        $this->actAs($this->member);
        $this->request('GET', $this->distributionUri($this->ishtarBatch));
        self::assertResponseStatusCodeSame(200);
        $before = $this->responseJson();
        self::assertFalse($before['surplusStored']);
        self::assertArrayHasKey('storedSurplusValue', $before);
        self::assertNull($before['storedSurplusValue']);

        $this->ishtarBatch->markSurplusStored($this->owner, new \DateTimeImmutable(self::STORED_AT));
        $this->em->flush();

        $this->actAs($this->member);
        $this->request('GET', $this->distributionUri($this->ishtarBatch));
        self::assertResponseStatusCodeSame(200);
        $after = $this->responseJson();
        self::assertTrue($after['surplusStored']);
        self::assertSame(self::TRITANIUM_SURPLUS_VALUE, $after['storedSurplusValue']);
        self::assertSame($before['marginPercent'], $after['marginPercent']);
        self::assertSame($before['totalProjectCost'], $after['totalProjectCost']);
    }

    public function testStoredSurplusWithAnUnpricedMaterialIsStoredWithAnExplicitNullValueNeverZero(): void
    {
        $this->addContribution($this->ownerMembership, $this->morphiteLine, ContributionType::Material, 160, 2_960_000.0, ContributionStatus::Approved)->setEstimatedValue(null);
        $this->ishtarBatch->markSurplusStored($this->owner, new \DateTimeImmutable(self::STORED_AT));
        $this->em->flush();

        $this->actAs($this->member);
        $this->request('GET', $this->distributionUri($this->ishtarBatch));

        self::assertResponseStatusCodeSame(200);
        $distribution = $this->responseJson();
        self::assertTrue($distribution['surplusStored']);
        self::assertArrayHasKey('storedSurplusValue', $distribution);
        self::assertNull($distribution['storedSurplusValue']);
    }

    private function storageUri(IndustryProject $project): string
    {
        return $this->projectUri($project).'/surplus-storage';
    }

    private function surplusUri(IndustryProject $project): string
    {
        return $this->projectUri($project).'/surplus';
    }

    private function distributionUri(IndustryProject $project): string
    {
        return $this->projectUri($project).'/distribution';
    }
}
