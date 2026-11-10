<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningSplitMode;
use App\Service\Mercure\MercurePublisherService;
use App\Tests\Integration\MiningOperation\BuildsHaulerPlanOperation;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[Group('issue-312')]
final class MiningHaulerPlanApiTest extends WebTestCase
{
    use BuildsHaulerPlanOperation;
    use ClockSensitiveTrait;

    private const float DELTA = 0.000001;
    private const string EMPTIED_EVENT = 'fleet_hangar_emptied';
    private const string NOT_RUNNING_MESSAGE = 'The operation is not running';
    private const string FORBIDDEN_TO_EMPTY_MESSAGE = 'Only the fleet storage recipient, the operation owner or an admin can declare the hangar emptied';
    private const string NO_ACCESS_MESSAGE = 'You do not have access to this operation';
    private const string EACH_FOR_THEMSELVES_MESSAGE = 'An each-for-themselves operation has no hauler plan';
    private const array CALCULATED_FIELDS = ['inflowM3PerMinute', 'fillM3', 'fillRatio', 'fullInMinutes', 'isFull', 'transportM3PerMinute', 'toMoveM3', 'trips', 'siteEtaAt', 'capacityM3', 'holdCapacityM3', 'share'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private MiningOperation $operation;
    private string $operationId;
    private array $publishedUpdates = [];

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::HAULER_PLAN_NOW));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seedHaulerPlanSde();
    }

    public function testParticipantReadsTheKnownPlan(): void
    {
        $this->givenRunningOperation();

        $plan = $this->read($this->cyra);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [$this->operationId, 'known', 'Orca', 'Brann Holt', true, '2026-11-10T21:10:00+00:00', false, 1, [self::ZEOLITES], ['Aria Solberg', 'Cyra Lune']],
            [
                $plan['operationId'],
                $plan['state'],
                $plan['boostShipName'],
                $plan['haulerName'],
                $plan['originIsStart'],
                $plan['siteEtaAt'],
                $plan['isFull'],
                $plan['trips'],
                $plan['rawTypeIds'],
                $this->sorted($plan['contributorNames']),
            ],
        );
        self::assertArrayHasKey('unknownReason', $plan);
        self::assertNull($plan['unknownReason']);
        self::assertArrayHasKey('emptiedAt', $plan);
        self::assertNull($plan['emptiedAt']);
        self::assertEqualsWithDelta(
            [40_000.0, 150_000.0, 0.5, 50.0, 500.0, 0.0125, 790.0, 32.675, 2_287.25],
            [$plan['capacityM3'], $plan['holdCapacityM3'], $plan['share'], $plan['inflowM3PerMinute'], $plan['fillM3'], $plan['fillRatio'], $plan['fullInMinutes'], $plan['transportM3PerMinute'], $plan['toMoveM3']],
            self::DELTA,
        );
    }

    public function testUnknownPlanCarriesItsReasonAndNullCalculatedFields(): void
    {
        $this->givenRunningOperation(status: MiningOperationStatus::Closing);

        $plan = $this->read($this->aria);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['unknown', 'operation_not_running', 'Orca', 'Brann Holt'], [$plan['state'], $plan['unknownReason'], $plan['boostShipName'], $plan['haulerName']]);
        foreach (self::CALCULATED_FIELDS as $field) {
            self::assertArrayHasKey($field, $plan, $field);
            self::assertNull($plan[$field], $field);
        }
    }

    public function testNonParticipantCannotReadThePlan(): void
    {
        $this->givenRunningOperation();

        $this->read($this->dorn);

        self::assertSame([403, self::NO_ACCESS_MESSAGE], $this->errorDetail());
    }

    public function testEachForThemselvesOperationHasNoHaulerPlan(): void
    {
        $this->givenRunningOperation(splitMode: MiningSplitMode::EachForThemselves);

        $this->read($this->aria);

        self::assertSame([404, self::EACH_FOR_THEMSELVES_MESSAGE], $this->errorDetail());
    }

    public function testUnknownOperationIdentifierIsNotFound(): void
    {
        $this->givenRunningOperation();

        $this->as($this->aria);
        $this->client->request('GET', '/api/mining-operations/not-an-operation/hauler-plan', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertSame([404, 'Mining operation not found'], $this->errorDetail());
    }

    public function testAnonymousVisitorCannotReadThePlan(): void
    {
        $this->givenRunningOperation();

        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->request('GET', sprintf('/api/mining-operations/%s/hauler-plan', $this->operationId), server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testRecipientDeclaresTheHangarEmptiedAndEveryParticipantIsTold(): void
    {
        $this->givenRunningOperation();

        $plan = $this->declareEmptied($this->brann);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['2026-11-10T20:10:00+00:00', false], [$plan['emptiedAt'], $plan['originIsStart']]);
        self::assertEqualsWithDelta([0.0, 0.0, 800.0], [$plan['fillM3'], $plan['fillRatio'], $plan['fullInMinutes']], self::DELTA);
        self::assertSame('2026-11-10T20:10:00+00:00', $this->storedEmptiedAt());
        self::assertSame(
            [
                '/user/'.$this->aria->getId().'/mining-operations' => [self::EMPTIED_EVENT, $this->operationId],
                '/user/'.$this->brann->getId().'/mining-operations' => [self::EMPTIED_EVENT, $this->operationId],
                '/user/'.$this->cyra->getId().'/mining-operations' => [self::EMPTIED_EVENT, $this->operationId],
            ],
            $this->publishedActionsByTopic(),
        );
    }

    public function testOwnerDeclaresTheHangarEmptied(): void
    {
        $this->givenRunningOperation();

        $this->declareEmptied($this->aria);

        self::assertSame([200, '2026-11-10T20:10:00+00:00'], [$this->client->getResponse()->getStatusCode(), $this->storedEmptiedAt()]);
    }

    public function testAcceptedAdminDeclaresTheHangarEmptied(): void
    {
        $this->givenRunningOperation();
        $elia = $this->persistMiningPilot($this->em, 'Elia Varr', self::C1);
        $this->addMiningMembership($this->operation, $elia, MiningOperationMemberRole::Admin, MiningOperationMemberStatus::Accepted, []);
        $this->em->flush();

        $this->declareEmptied($elia);

        self::assertSame([200, '2026-11-10T20:10:00+00:00'], [$this->client->getResponse()->getStatusCode(), $this->storedEmptiedAt()]);
    }

    public function testSimpleParticipantCannotDeclareTheHangarEmptied(): void
    {
        $this->givenRunningOperation();

        $this->declareEmptied($this->cyra);

        self::assertSame([403, self::FORBIDDEN_TO_EMPTY_MESSAGE, null, []], [...$this->errorDetail(), $this->storedEmptiedAt(), $this->publishedActionsByTopic()]);
    }

    public function testNonParticipantCannotDeclareTheHangarEmptied(): void
    {
        $this->givenRunningOperation();

        $this->declareEmptied($this->dorn);

        self::assertSame([403, self::NO_ACCESS_MESSAGE, null], [...$this->errorDetail(), $this->storedEmptiedAt()]);
    }

    public function testHangarCannotBeDeclaredEmptiedOnAnOperationThatIsNotRunning(): void
    {
        $this->givenRunningOperation(status: MiningOperationStatus::Closing);

        $this->declareEmptied($this->brann);

        self::assertSame([400, self::NOT_RUNNING_MESSAGE, null, []], [...$this->errorDetail(), $this->storedEmptiedAt(), $this->publishedActionsByTopic()]);
    }

    public function testEachForThemselvesOperationHasNoHangarToEmpty(): void
    {
        $this->givenRunningOperation(splitMode: MiningSplitMode::EachForThemselves);

        $this->declareEmptied($this->aria);

        self::assertSame([404, self::EACH_FOR_THEMSELVES_MESSAGE], $this->errorDetail());
    }

    private function givenRunningOperation(MiningOperationStatus $status = MiningOperationStatus::InProgress, MiningSplitMode $splitMode = MiningSplitMode::ValueProrata): void
    {
        $this->operation = $this->persistHaulerPlanOperation(status: $status);
        $this->operation->setSplitMode($splitMode);
        $this->em->flush();
        $this->operationId = (string) $this->operation->getId();
    }

    private function read(User $user): array
    {
        $this->as($user);
        $this->client->request('GET', sprintf('/api/mining-operations/%s/hauler-plan', $this->operationId), server: ['HTTP_ACCEPT' => 'application/json']);

        return $this->responseJson();
    }

    private function declareEmptied(User $user): array
    {
        $this->as($user);
        $this->client->request('POST', sprintf('/api/mining-operations/%s/hauler-plan/emptied', $this->operationId), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}');

        return $this->responseJson();
    }

    private function as(User $user): void
    {
        $userId = (string) $user->getId();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->disableReboot();
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'urn:uuid:published';
        });
        self::getContainer()->set(MercurePublisherService::class, new MercurePublisherService($hub, new NullLogger()));
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($this->em->find(User::class, $userId), 'api');
    }

    private function storedEmptiedAt(): ?string
    {
        $this->em->clear();
        $operation = $this->em->find(MiningOperation::class, $this->operationId);
        \assert($operation instanceof MiningOperation);

        return $operation->getFleetHangarEmptiedAt()?->format(\DateTimeInterface::ATOM);
    }

    private function publishedActionsByTopic(): array
    {
        $actions = [];
        foreach ($this->publishedUpdates as $update) {
            $payload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
            $actions[$update->getTopics()[0]] = [$payload['action'], $payload['operationId']];
        }
        ksort($actions);

        return $actions;
    }

    private function responseJson(): array
    {
        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? [] : json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    }

    private function errorDetail(): array
    {
        $error = $this->responseJson();

        return [$error['status'] ?? $this->client->getResponse()->getStatusCode(), $error['detail'] ?? null];
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        sort($names);

        return $names;
    }
}
