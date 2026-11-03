<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\Input\MiningOperation\UpdateMiningOperationMemberInput;
use App\ApiResource\MiningOperation\MiningOperationMemberResource;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningOperationAccess;
use App\State\Processor\MiningOperation\UpdateMiningOperationMemberProcessor;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(UpdateMiningOperationMemberProcessor::class)]
#[Group('issue-262')]
#[AllowMockObjectsWithoutExpectations]
final class UpdateMiningOperationMemberProcessorTest extends TestCase
{
    use BuildsSpodumainOperation;

    private Security&Stub $security;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private MiningOperation $operation;
    private MiningOperationMember $ariaMembership;
    private MiningOperationMember $brannMembership;
    private MiningOperationMember $cyraMembership;
    private UpdateMiningOperationMemberProcessor $processor;

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
        $this->processor = new UpdateMiningOperationMemberProcessor(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            $this->memberRepositoryReadingOperations(),
            new MiningOperationAccess(),
            new MiningOperationResourceMapper(),
            $this->entityManager,
            new MercurePublisherService($this->hub, new NullLogger()),
        );
    }

    public function testOwnerAcceptsThePendingRequestOfCyraWhoBecomesTheThirdParticipant(): void
    {
        $this->actAs($this->aria);
        $publishedUpdates = $this->capturePublishedUpdates($this->hub);
        $this->entityManager->expects($this->once())->method('flush');

        $resource = $this->update($this->cyraMembership, status: 'accepted');

        self::assertSame($this->cyraMembership->getId()->toRfc4122(), $resource->id);
        self::assertSame('accepted', $resource->status);
        self::assertSame('member', $resource->role);
        self::assertSame(MiningOperationMemberStatus::Accepted, $this->cyraMembership->getStatus());
        self::assertCount(3, $this->acceptedMembersOf($this->operation));
        self::assertSame(
            $this->expectedMessages([$this->aria, $this->brann, $this->cyra], $this->operation, 'member_updated', [
                'memberId' => $this->cyraMembership->getId()->toRfc4122(),
                'role' => 'member',
                'status' => 'accepted',
            ]),
            $this->publishedMessages($publishedUpdates),
        );
    }

    public function testPlainMemberCannotAcceptAPendingRequest(): void
    {
        $this->actAs($this->brann);
        $this->expectNothingChanged();

        $this->expectRefusal(AccessDeniedHttpException::class, 'Only the operation owner or an admin can perform this action');

        $this->update($this->cyraMembership, status: 'accepted');
    }

    public function testOwnerPromotesBrannToAdmin(): void
    {
        $this->actAs($this->aria);

        $resource = $this->update($this->brannMembership, role: 'admin');

        self::assertSame('admin', $resource->role);
        self::assertSame(MiningOperationMemberRole::Admin, $this->brannMembership->getRole());
    }

    public function testAdminCannotPromoteAMemberToAdmin(): void
    {
        $this->brannMembership->setRole(MiningOperationMemberRole::Admin);
        $eliaMembership = $this->addMembership($this->operation, $this->elia, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Elia Varr']);
        $this->actAs($this->brann);
        $this->expectNothingChanged();

        $this->expectRefusal(AccessDeniedHttpException::class, 'Only the operation owner can promote members to admin');

        $this->update($eliaMembership, role: 'admin');
    }

    public function testAdminCannotChangeTheRoleOfAnotherAdmin(): void
    {
        $this->brannMembership->setRole(MiningOperationMemberRole::Admin);
        $eliaMembership = $this->addMembership($this->operation, $this->elia, MiningOperationMemberRole::Admin, MiningOperationMemberStatus::Accepted, ['Elia Varr']);
        $this->actAs($this->brann);

        $this->expectRefusal(AccessDeniedHttpException::class, 'Only the operation owner can manage an admin');

        $this->update($eliaMembership, role: 'member');
    }

    public function testOwnerCannotModifyHerOwnMembership(): void
    {
        $this->actAs($this->aria);
        $this->expectNothingChanged();

        $this->expectRefusal(BadRequestHttpException::class, 'Cannot modify the operation owner');

        $this->update($this->ariaMembership, role: 'member');
    }

    public function testOnlyAPendingMemberCanBeAccepted(): void
    {
        $this->actAs($this->aria);
        $this->expectNothingChanged();

        $this->expectRefusal(BadRequestHttpException::class, 'Only pending members can be accepted');

        $this->update($this->brannMembership, status: 'accepted');
    }

    public function testMemberOfAnotherOperationIsNotFound(): void
    {
        $otherOperation = $this->spodumainOperation();
        $otherOperation->setJoinCode('3a6c9e2b5d');
        $dornMembership = $this->addMembership($otherOperation, $this->dorn, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Dorn Achar']);
        $this->actAs($this->aria);
        $this->expectNothingChanged();

        $this->expectRefusal(NotFoundHttpException::class, 'Member not found');

        $this->update($dornMembership, status: 'accepted');
    }

    public function testUnknownMemberIsNotFound(): void
    {
        $this->actAs($this->aria);

        $this->expectRefusal(NotFoundHttpException::class, 'Member not found');

        $this->processor->process($this->input(null, 'accepted'), new Patch(), [
            'operationId' => $this->operation->getId()->toRfc4122(),
            'id' => Uuid::v4()->toRfc4122(),
        ]);
    }

    public function testMemberIdThatIsNotAUuidIsNotFound(): void
    {
        $this->actAs($this->aria);

        $this->expectRefusal(NotFoundHttpException::class, 'Member not found');

        $this->processor->process($this->input(null, 'accepted'), new Patch(), [
            'operationId' => $this->operation->getId()->toRfc4122(),
            'id' => self::NOT_A_UUID,
        ]);
    }

    public function testOperationIdThatIsNotAUuidIsNotFound(): void
    {
        $this->actAs($this->aria);

        $this->expectRefusal(NotFoundHttpException::class, 'Mining operation not found');

        $this->processor->process($this->input(null, 'accepted'), new Patch(), [
            'operationId' => self::NOT_A_UUID,
            'id' => $this->cyraMembership->getId()->toRfc4122(),
        ]);
    }

    public function testAnonymousRequestIsUnauthorized(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->expectException(UnauthorizedHttpException::class);

        $this->update($this->cyraMembership, status: 'accepted');
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function update(MiningOperationMember $member, ?string $role = null, ?string $status = null): MiningOperationMemberResource
    {
        return $this->processor->process($this->input($role, $status), new Patch(), [
            'operationId' => $member->getOperation()->getId()->toRfc4122(),
            'id' => $member->getId()->toRfc4122(),
        ]);
    }

    private function input(?string $role, ?string $status): UpdateMiningOperationMemberInput
    {
        $input = new UpdateMiningOperationMemberInput();
        $input->role = $role;
        $input->status = $status;

        return $input;
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
