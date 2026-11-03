<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\EmptyInput;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationCharacter;
use App\Entity\User;
use App\Enum\MiningLedgerSnapshotKind;
use App\Enum\MiningLedgerSnapshotStatus;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Service\MiningOperation\MiningOperationAccess;
use App\State\Processor\MiningOperation\RetryMiningLedgerSnapshotProcessor;
use App\Tests\Unit\Service\MiningOperation\BuildsLedgerSnapshots;
use App\Tests\Unit\Service\MiningOperation\BuildsSpodumainOperation;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RetryMiningLedgerSnapshotProcessor::class)]
#[Group('issue-263')]
#[AllowMockObjectsWithoutExpectations]
final class RetryMiningLedgerSnapshotProcessorTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;

    private const string RETRIED_AT = '2026-11-05T20:40:00+00:00';

    private Security&Stub $security;
    private EntityManagerInterface&MockObject $entityManager;
    private \ArrayObject $dispatched;
    private MiningOperation $operation;
    private RetryMiningLedgerSnapshotProcessor $processor;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->operation = $this->startedSpodumainOperation();
        $this->addMembership($this->operation, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Cyra Lune']);
        $this->identifyEnrolments();
        $this->takenStartSnapshot('Aria Solberg', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        $this->failedStartSnapshot('Brann II', MiningLedgerSnapshotStatus::ReconnectRequired);
        $this->security = $this->createStub(Security::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->dispatched = new \ArrayObject();
        $this->processor = new RetryMiningLedgerSnapshotProcessor(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            $this->operationCharacterRepositoryReadingOperations(),
            new MiningOperationAccess(),
            $this->lifecycleWith($this->entityManager, $this->dispatched, new MockClock(self::RETRIED_AT)),
        );
    }

    public function testCharacterOwnerRetriesTheStartSnapshotOfItsReconnectedCharacter(): void
    {
        $this->actAs($this->brann);
        $this->identifyingOnFlush($this->entityManager);

        $this->retry($this->enrolmentOf('Brann II'));

        self::assertSame(
            ['kind' => 'start', 'status' => 'waiting', 'referenceAt' => self::RETRIED_AT, 'readyAt' => self::RETRIED_AT],
            $this->snapshotStateOf($this->enrolmentOf('Brann II')->snapshotOf(MiningLedgerSnapshotKind::Start)),
        );
        self::assertCount(1, $this->dispatched);
    }

    public function testOperationAdministratorRetriesTheSnapshotOfAnotherPilot(): void
    {
        $this->actAs($this->aria);
        $this->identifyingOnFlush($this->entityManager);

        $this->retry($this->enrolmentOf('Brann II'));

        self::assertSame('waiting', $this->enrolmentOf('Brann II')->snapshotOf(MiningLedgerSnapshotKind::Start)?->getStatus()->value);
    }

    public function testPilotWhoNeitherOwnsTheCharacterNorAdministersCannotRetry(): void
    {
        $this->actAs($this->cyra);
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectRefusal(AccessDeniedHttpException::class, 'Only the character owner or an admin can retry its snapshot');

        $this->retry($this->enrolmentOf('Brann II'));
    }

    public function testTakenSnapshotCannotBeRetried(): void
    {
        $this->actAs($this->aria);
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectRefusal(BadRequestHttpException::class, 'This snapshot cannot be retried');

        $this->retry($this->enrolmentOf('Aria Solberg'));
    }

    public function testCharacterOfAnotherOperationIsNotFound(): void
    {
        $iceBelt = $this->iceBeltOperation();
        $this->addMembership($iceBelt, $this->dorn, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Dorn Achar']);
        $this->identifyEnrolments();
        $this->actAs($this->aria);

        $this->expectRefusal(NotFoundHttpException::class, 'Character not found');

        $this->retry($this->enrolmentOf('Dorn Achar', $iceBelt));
    }

    public function testUnknownCharacterIsNotFound(): void
    {
        $this->actAs($this->aria);

        $this->expectRefusal(NotFoundHttpException::class, 'Character not found');

        $this->processor->process(new EmptyInput(), new Post(), [
            'operationId' => $this->operation->getId()->toRfc4122(),
            'id' => Uuid::v4()->toRfc4122(),
        ]);
    }

    public function testCharacterIdThatIsNotAUuidIsNotFound(): void
    {
        $this->actAs($this->aria);

        $this->expectRefusal(NotFoundHttpException::class, 'Character not found');

        $this->processor->process(new EmptyInput(), new Post(), [
            'operationId' => $this->operation->getId()->toRfc4122(),
            'id' => self::NOT_A_UUID,
        ]);
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function retry(MiningOperationCharacter $enrolment): void
    {
        $this->processor->process(new EmptyInput(), new Post(), [
            'operationId' => $this->operation->getId()->toRfc4122(),
            'id' => $enrolment->getId()->toRfc4122(),
        ]);
    }

    private function expectRefusal(string $exceptionClass, string $message): void
    {
        $this->expectException($exceptionClass);
        $this->expectExceptionMessage($message);
    }
}
