<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\EmptyInput;
use App\ApiResource\MiningOperation\MiningOperationResource;
use App\Entity\MiningOperation;
use App\Entity\User;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningPresenceRecorder;
use App\Service\MiningOperation\MiningPresenceResolver;
use App\State\Processor\MiningOperation\RecordMiningPresenceProcessor;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use App\Tests\Unit\Service\MiningOperation\BuildsLedgerSnapshots;
use App\Tests\Unit\Service\MiningOperation\BuildsSpodumainOperation;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(RecordMiningPresenceProcessor::class)]
#[Group('issue-307')]
#[AllowMockObjectsWithoutExpectations]
final class RecordMiningPresenceProcessorTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;

    private const string NOW = '2026-11-05T20:00:00+00:00';

    private Security&Stub $security;
    private HubInterface&MockObject $hub;
    private MiningOperation $operation;
    private RecordMiningPresenceProcessor $processor;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->operation = $this->startedSpodumainOperation();
        $this->security = $this->createStub(Security::class);
        $this->hub = $this->createMock(HubInterface::class);
        $clock = new MockClock(self::NOW);
        $resolver = new MiningPresenceResolver($clock);
        $this->processor = new RecordMiningPresenceProcessor(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            new MiningPresenceRecorder($resolver, $clock, $this->createMock(EntityManagerInterface::class)),
            new MiningOperationResourceMapper($resolver),
            new MercurePublisherService($this->hub, new NullLogger()),
        );
    }

    public function testFirstHeartbeatOfBrannPublishesPresenceChangedToTheParticipants(): void
    {
        $this->actAs($this->brann);
        $publishedUpdates = $this->capturePublishedUpdates($this->hub);

        $resource = $this->beat();

        self::assertSame(
            ['in_progress', $this->expectedMessages([$this->aria, $this->brann], $this->operation, 'presence_changed', [
                'memberId' => $this->membershipOf($this->operation, $this->brann)->getId()->toRfc4122(),
            ])],
            [$resource->status, $this->publishedMessages($publishedUpdates)],
        );
    }

    public function testHeartbeatThatKeepsBrannOnlinePublishesNothing(): void
    {
        $this->lastSeenAt($this->membershipOf($this->operation, $this->brann), '2026-11-05T19:59:30+00:00');
        $this->actAs($this->brann);
        $this->hub->expects($this->never())->method('publish');

        $resource = $this->beat();

        self::assertSame('in_progress', $resource->status);
    }

    public function testHeartbeatOfBrannReturningFromAfkPublishesPresenceChanged(): void
    {
        $this->lastSeenAt($this->membershipOf($this->operation, $this->brann), '2026-11-05T19:55:00+00:00');
        $this->actAs($this->brann);
        $publishedUpdates = $this->capturePublishedUpdates($this->hub);

        $this->beat();

        self::assertSame(
            $this->expectedMessages([$this->aria, $this->brann], $this->operation, 'presence_changed', [
                'memberId' => $this->membershipOf($this->operation, $this->brann)->getId()->toRfc4122(),
            ]),
            $this->publishedMessages($publishedUpdates),
        );
    }

    public function testUserWithoutMembershipPublishesNothing(): void
    {
        $this->actAs($this->dorn);
        $this->hub->expects($this->never())->method('publish');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Membership not found');

        $this->beat();
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function beat(): MiningOperationResource
    {
        return $this->processor->process(new EmptyInput(), new Post(), ['id' => $this->operation->getId()->toRfc4122()]);
    }
}
