<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\EmptyInput;
use App\ApiResource\MiningOperation\MiningOperationResource;
use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MiningLedgerSnapshotKind;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningPresenceResolver;
use App\State\Processor\MiningOperation\EndMiningOperationProcessor;
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
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(EndMiningOperationProcessor::class)]
#[Group('issue-263')]
#[AllowMockObjectsWithoutExpectations]
final class EndMiningOperationProcessorTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;

    private Security&Stub $security;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private \ArrayObject $dispatched;
    private MiningOperation $operation;
    private EndMiningOperationProcessor $processor;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->operation = $this->startedSpodumainOperation();
        foreach (['Aria Solberg', 'Aria II', 'Brann Holt', 'Brann II'] as $characterName) {
            $this->takenStartSnapshot($characterName, [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        }
        $this->security = $this->createStub(Security::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);
        $this->dispatched = new \ArrayObject();
        $this->processor = new EndMiningOperationProcessor(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            new MiningOperationAccess(),
            $this->lifecycleWith($this->entityManager, $this->dispatched, new MockClock(self::ENDED_AT)),
            new MiningOperationResourceMapper(new MiningPresenceResolver(new MockClock())),
            new MercurePublisherService($this->hub, new NullLogger()),
        );
    }

    public function testOwnerEndsTheOperationWhichWaitsForTheEndSnapshotsOfItsCharacters(): void
    {
        $this->actAs($this->aria);
        $publishedUpdates = $this->capturePublishedUpdates($this->hub);
        $this->identifyingOnFlush($this->entityManager);

        $resource = $this->end();

        self::assertSame(
            ['closing', self::STARTED_AT, self::ENDED_AT, self::S1_NAME],
            [$resource->status, $resource->startedAt?->format(\DateTimeInterface::ATOM), $resource->endedAt?->format(\DateTimeInterface::ATOM), $resource->solarSystemName],
        );
        self::assertSame([4, 4], [count($this->snapshotStatesOf($this->operation, MiningLedgerSnapshotKind::End)), count($this->dispatched)]);
        self::assertSame(
            $this->expectedMessages([$this->aria, $this->brann], $this->operation, 'operation_ended', []),
            $this->publishedMessages($publishedUpdates),
        );
    }

    public function testPlannedOperationCannotBeEnded(): void
    {
        $plannedOperation = $this->operationOwnedBy($this->aria, self::OCHRE_BELT_LABEL, self::C1, '3a6c9e2b5d');
        $this->actAs($this->aria);
        $this->expectNothingChanged();

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Only an operation in progress can be ended');

        $this->processor->process(new EmptyInput(), new Post(), ['id' => $plannedOperation->getId()->toRfc4122()]);
    }

    public function testPlainMemberCannotEndTheOperation(): void
    {
        $this->actAs($this->brann);
        $this->expectNothingChanged();

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Only the operation owner or an admin can perform this action');

        $this->end();
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function end(): MiningOperationResource
    {
        return $this->processor->process(new EmptyInput(), new Post(), ['id' => $this->operation->getId()->toRfc4122()]);
    }

    private function expectNothingChanged(): void
    {
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');
    }
}
