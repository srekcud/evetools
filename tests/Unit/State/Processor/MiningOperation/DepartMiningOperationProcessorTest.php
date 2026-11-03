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
use App\Service\MiningOperation\MiningPresenceResolver;
use App\State\Processor\MiningOperation\DepartMiningOperationProcessor;
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
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(DepartMiningOperationProcessor::class)]
#[Group('issue-263')]
#[AllowMockObjectsWithoutExpectations]
final class DepartMiningOperationProcessorTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;

    private const string DEPARTED_AT = '2026-11-05T21:05:00+00:00';

    private Security&Stub $security;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private \ArrayObject $dispatched;
    private MiningOperation $operation;
    private DepartMiningOperationProcessor $processor;

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
        $this->processor = new DepartMiningOperationProcessor(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            $this->lifecycleWith($this->entityManager, $this->dispatched, new MockClock(self::DEPARTED_AT)),
            new MiningOperationResourceMapper(new MiningPresenceResolver(new MockClock())),
            new MercurePublisherService($this->hub, new NullLogger()),
        );
    }

    public function testBrannLeavesEarlyAndStaysCountedAsParticipant(): void
    {
        $this->actAs($this->brann);
        $publishedUpdates = $this->capturePublishedUpdates($this->hub);
        $this->identifyingOnFlush($this->entityManager);

        $resource = $this->depart();

        self::assertSame(
            ['in_progress', self::DEPARTED_AT, 'accepted', 2, 4],
            [$resource->status, $resource->myDepartedAt?->format(\DateTimeInterface::ATOM), $resource->myStatus, $resource->participantsCount, $resource->charactersCount],
        );
        self::assertSame(['Brann Holt', 'Brann II'], array_keys($this->snapshotStatesOf($this->operation, MiningLedgerSnapshotKind::End)));
        self::assertCount(2, $this->dispatched);
        self::assertSame(
            $this->expectedMessages([$this->aria, $this->brann], $this->operation, 'member_departed', [
                'memberId' => $this->membershipOf($this->operation, $this->brann)->getId()->toRfc4122(),
            ]),
            $this->publishedMessages($publishedUpdates),
        );
    }

    public function testParticipantWhoAlreadyLeftCannotLeaveAgain(): void
    {
        $this->membershipOf($this->operation, $this->brann)->depart(new \DateTimeImmutable('2026-11-05T20:50:00+00:00'));
        $this->actAs($this->brann);
        $this->expectNothingChanged();

        $this->expectRefusal(BadRequestHttpException::class, 'You already left this operation');

        $this->depart();
    }

    public function testClosingOperationCannotBeLeftEarly(): void
    {
        $this->operation->end(new \DateTimeImmutable('2026-11-05T21:00:00+00:00'));
        $this->actAs($this->brann);
        $this->expectNothingChanged();

        $this->expectRefusal(BadRequestHttpException::class, 'Only an operation in progress can be left early');

        $this->depart();
    }

    public function testUserWithoutMembershipCannotLeaveEarly(): void
    {
        $this->actAs($this->dorn);
        $this->expectNothingChanged();

        $this->expectRefusal(NotFoundHttpException::class, 'Membership not found');

        $this->depart();
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function depart(): MiningOperationResource
    {
        return $this->processor->process(new EmptyInput(), new Post(), ['id' => $this->operation->getId()->toRfc4122()]);
    }

    private function expectNothingChanged(): void
    {
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');
    }

    private function expectRefusal(string $exceptionClass, string $message): void
    {
        $this->expectException($exceptionClass);
        $this->expectExceptionMessage($message);
    }
}
