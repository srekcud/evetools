<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Post;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\MiningOperation\LeaveMiningOperationProcessor;
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
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(LeaveMiningOperationProcessor::class)]
#[Group('issue-262')]
#[AllowMockObjectsWithoutExpectations]
final class LeaveMiningOperationProcessorTest extends TestCase
{
    use BuildsSpodumainOperation;

    private Security&Stub $security;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private LeaveMiningOperationProcessor $processor;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->security = $this->createStub(Security::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);
        $this->processor = new LeaveMiningOperationProcessor(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            $this->entityManager,
            new MercurePublisherService($this->hub, new NullLogger()),
        );
    }

    public function testAcceptedMemberLeavesAPlannedOperationAndHisCharactersAreFreed(): void
    {
        $operation = $this->spodumainOperation();
        $brannMembership = $this->addMembership($operation, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt', 'Brann II']);
        $this->actAs($this->brann);
        $publishedUpdates = $this->capturePublishedUpdates($this->hub);
        $this->entityManager->expects($this->once())->method('flush');

        $this->leave($operation);

        self::assertSame(MiningOperationMemberStatus::Removed, $brannMembership->getStatus());
        self::assertCount(0, $brannMembership->getCharacters());
        self::assertSame(
            $this->expectedMessages([$this->aria, $this->brann], $operation, 'member_removed', [
                'memberId' => $brannMembership->getId()->toRfc4122(),
            ]),
            $this->publishedMessages($publishedUpdates),
        );
    }

    public function testOwnerCannotLeaveHerOperation(): void
    {
        $operation = $this->spodumainOperation();
        $this->actAs($this->aria);
        $this->expectNothingChanged();

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('The owner cannot leave the operation');

        $this->leave($operation);
    }

    public function testAcceptedMemberCannotLeaveAnOperationInProgress(): void
    {
        $operation = $this->spodumainOperation(MiningOperationStatus::InProgress);
        $brannMembership = $this->addMembership($operation, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt']);
        $this->actAs($this->brann);
        $this->expectNothingChanged();

        try {
            $this->leave($operation);
            self::fail('Leaving an operation in progress must be refused');
        } catch (BadRequestHttpException $refusal) {
            self::assertSame('Only a planned operation can be left', $refusal->getMessage());
        }

        self::assertSame(MiningOperationMemberStatus::Accepted, $brannMembership->getStatus());
        self::assertCount(1, $brannMembership->getCharacters());
    }

    public function testPendingRequestIsWithdrawnFromAnOperationInProgress(): void
    {
        $operation = $this->spodumainOperation(MiningOperationStatus::InProgress);
        $cyraMembership = $this->addMembership($operation, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Cyra Lune']);
        $this->actAs($this->cyra);

        $this->leave($operation);

        self::assertSame(MiningOperationMemberStatus::Removed, $cyraMembership->getStatus());
        self::assertCount(0, $cyraMembership->getCharacters());
    }

    public function testPilotWithoutMembershipHasNoMembershipToLeave(): void
    {
        $operation = $this->spodumainOperation();
        $this->actAs($this->dorn);
        $this->expectNothingChanged();

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Membership not found');

        $this->leave($operation);
    }

    public function testRemovedMemberHasNoMembershipToLeave(): void
    {
        $operation = $this->spodumainOperation();
        $this->addMembership($operation, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Removed, []);
        $this->actAs($this->brann);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Membership not found');

        $this->leave($operation);
    }

    #[Group('issue-265')]
    public function testPendingRequestCannotBeWithdrawnFromACompletedOperation(): void
    {
        $operation = $this->spodumainOperation(MiningOperationStatus::Completed);
        $cyraMembership = $this->addMembership($operation, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Cyra Lune']);
        $this->actAs($this->cyra);
        $this->expectNothingChanged();

        try {
            $this->leave($operation);
            self::fail('The withdrawal was not refused');
        } catch (BadRequestHttpException $refusal) {
            self::assertSame(
                ['This operation is archived', MiningOperationMemberStatus::Pending],
                [$refusal->getMessage(), $cyraMembership->getStatus()],
            );
        }
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function leave(MiningOperation $operation): void
    {
        $this->processor->process(null, new Post(), ['id' => $operation->getId()->toRfc4122()]);
    }

    private function expectNothingChanged(): void
    {
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');
    }
}
