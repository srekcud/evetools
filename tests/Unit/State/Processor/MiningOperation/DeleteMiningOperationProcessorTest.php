<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Delete;
use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\MiningOperation\DeleteMiningOperationProcessor;
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
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(DeleteMiningOperationProcessor::class)]
#[Group('issue-262')]
#[AllowMockObjectsWithoutExpectations]
final class DeleteMiningOperationProcessorTest extends TestCase
{
    use BuildsSpodumainOperation;

    private Security&Stub $security;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private DeleteMiningOperationProcessor $processor;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->security = $this->createStub(Security::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);
        $this->processor = new DeleteMiningOperationProcessor(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            $this->entityManager,
            new MercurePublisherService($this->hub, new NullLogger()),
        );
    }

    public function testOwnerDeletesAPlannedOperationAndItsParticipantsAreNotified(): void
    {
        $operation = $this->spodumainOperation();
        $this->addMembership($operation, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt']);
        $this->actAs($this->aria);
        $publishedUpdates = $this->capturePublishedUpdates($this->hub);
        $this->entityManager->expects($this->once())->method('remove')->with($this->identicalTo($operation));
        $this->entityManager->expects($this->once())->method('flush');

        $this->delete($operation);

        self::assertSame(
            $this->expectedMessages([$this->aria, $this->brann], $operation, 'operation_deleted', []),
            $this->publishedMessages($publishedUpdates),
        );
    }

    public function testAdminCannotDeleteTheOperation(): void
    {
        $operation = $this->spodumainOperation();
        $this->addMembership($operation, $this->brann, MiningOperationMemberRole::Admin, MiningOperationMemberStatus::Accepted, ['Brann Holt']);
        $this->actAs($this->brann);
        $this->expectNothingDeleted();

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Only the operation owner can delete it');

        $this->delete($operation);
    }

    public function testOperationInProgressCannotBeDeleted(): void
    {
        $operation = $this->spodumainOperation(MiningOperationStatus::InProgress);
        $this->actAs($this->aria);
        $this->expectNothingDeleted();

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Only a planned operation can be deleted');

        $this->delete($operation);
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function delete(MiningOperation $operation): void
    {
        $this->processor->process(null, new Delete(), ['id' => $operation->getId()->toRfc4122()]);
    }

    private function expectNothingDeleted(): void
    {
        $this->entityManager->expects($this->never())->method('remove');
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');
    }
}
