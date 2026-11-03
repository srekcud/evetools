<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Delete;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningOperationAccess;
use App\State\Processor\MiningOperation\RemoveMiningOperationMemberProcessor;
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
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(RemoveMiningOperationMemberProcessor::class)]
#[Group('issue-262')]
#[Group('issue-263')]
#[AllowMockObjectsWithoutExpectations]
final class RemoveMiningOperationMemberProcessorTest extends TestCase
{
    use BuildsSpodumainOperation;

    private Security&Stub $security;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private MiningOperation $operation;
    private MiningOperationMember $ariaMembership;
    private MiningOperationMember $brannMembership;
    private MiningOperationMember $cyraMembership;
    private RemoveMiningOperationMemberProcessor $processor;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->operation = $this->spodumainOperation();
        $this->ariaMembership = $this->membershipOf($this->operation, $this->aria);
        $this->brannMembership = $this->addMembership($this->operation, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt', 'Brann II']);
        $this->cyraMembership = $this->addMembership($this->operation, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Cyra Lune']);

        $this->security = $this->createStub(Security::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);
        $this->processor = new RemoveMiningOperationMemberProcessor(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            $this->memberRepositoryReadingOperations(),
            new MiningOperationAccess(),
            $this->entityManager,
            new MercurePublisherService($this->hub, new NullLogger()),
        );
    }

    public function testOwnerRefusesThePendingRequestOfCyraWhoseCharacterIsFreed(): void
    {
        $this->actAs($this->aria);
        $publishedUpdates = $this->capturePublishedUpdates($this->hub);
        $this->entityManager->expects($this->once())->method('flush');

        $this->remove($this->cyraMembership);

        self::assertSame(MiningOperationMemberStatus::Removed, $this->cyraMembership->getStatus());
        self::assertSame(MiningOperationMemberRole::Member, $this->cyraMembership->getRole());
        self::assertCount(0, $this->cyraMembership->getCharacters());
        self::assertCount(3, $this->operation->getMembers());
        self::assertSame(
            $this->expectedMessages([$this->aria, $this->brann, $this->cyra], $this->operation, 'member_removed', [
                'memberId' => $this->cyraMembership->getId()->toRfc4122(),
            ]),
            $this->publishedMessages($publishedUpdates),
        );
    }

    public function testAdminRemovesAPlainMember(): void
    {
        $this->brannMembership->setRole(MiningOperationMemberRole::Admin);
        $this->actAs($this->brann);

        $this->remove($this->cyraMembership);

        self::assertSame(MiningOperationMemberStatus::Removed, $this->cyraMembership->getStatus());
    }

    public function testAdminCannotRemoveAnotherAdmin(): void
    {
        $this->brannMembership->setRole(MiningOperationMemberRole::Admin);
        $eliaMembership = $this->addMembership($this->operation, $this->elia, MiningOperationMemberRole::Admin, MiningOperationMemberStatus::Accepted, ['Elia Varr']);
        $this->actAs($this->brann);
        $this->expectNothingChanged();

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Only the operation owner can manage an admin');

        $this->remove($eliaMembership);
    }

    public function testAdminCannotRemoveTheOwner(): void
    {
        $this->brannMembership->setRole(MiningOperationMemberRole::Admin);
        $this->actAs($this->brann);
        $this->expectNothingChanged();

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Cannot remove the operation owner');

        $this->remove($this->ariaMembership);
    }

    public function testPlainMemberRemovingTheOwnerIsRefusedForLackOfAdministrationFirst(): void
    {
        $this->actAs($this->brann);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Only the operation owner or an admin can perform this action');

        $this->remove($this->ariaMembership);
    }

    public function testAlreadyRemovedMemberCannotBeRemovedAgain(): void
    {
        $eliaMembership = $this->addMembership($this->operation, $this->elia, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Removed, []);
        $this->actAs($this->aria);
        $this->expectNothingChanged();

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Member already removed');

        $this->remove($eliaMembership);
    }

    public function testMemberOfAnotherOperationIsNotFound(): void
    {
        $otherOperation = $this->spodumainOperation();
        $otherOperation->setJoinCode('3a6c9e2b5d');
        $dornMembership = $this->addMembership($otherOperation, $this->dorn, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Dorn Achar']);
        $this->actAs($this->aria);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Member not found');

        $this->processor->process(null, new Delete(), [
            'operationId' => $this->operation->getId()->toRfc4122(),
            'id' => $dornMembership->getId()->toRfc4122(),
        ]);
    }

    public function testOwnerCannotRemoveAParticipantOnceTheOperationHasStarted(): void
    {
        $this->operation->start(new \DateTimeImmutable('2026-11-05T20:00:00+00:00'), null);
        $this->actAs($this->aria);
        $this->expectNothingChanged();

        $refusal = null;
        try {
            $this->remove($this->brannMembership);
        } catch (\Throwable $raised) {
            $refusal = $raised;
        }

        self::assertSame(
            [ConflictHttpException::class, 'Mining operation already started: use departure instead', MiningOperationMemberStatus::Accepted, ['Brann Holt', 'Brann II']],
            [$refusal === null ? null : $refusal::class, $refusal?->getMessage(), $this->brannMembership->getStatus(), $this->enrolledNamesOf($this->brannMembership)],
        );
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function remove(MiningOperationMember $member): void
    {
        $this->processor->process(null, new Delete(), [
            'operationId' => $this->operation->getId()->toRfc4122(),
            'id' => $member->getId()->toRfc4122(),
        ]);
    }

    private function expectNothingChanged(): void
    {
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');
    }
}
