<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\Input\GroupIndustry\UpdateGroupMemberInput;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\State\Processor\GroupIndustry\UpdateGroupMemberProcessor;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use App\Tests\Unit\State\Provider\GroupIndustry\GroupProjectAccessProfiles;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

#[CoversClass(UpdateGroupMemberProcessor::class)]
class UpdateGroupMemberProcessorTest extends TestCase
{
    use GroupProjectAccessProfiles;

    private const string ONLY_THE_OWNER_MANAGES_AN_ADMIN = '403 Only the project owner can manage an admin';
    private const string ONLY_THE_OWNER_PROMOTES = '403 Only the project owner can promote members to admin';
    private const string MEMBER_NOT_FOUND = '404 Member not found';

    private Security&Stub $security;
    private EntityManagerInterface&Stub $entityManager;
    private UpdateGroupMemberProcessor $processor;
    private int $flushes = 0;

    private User $adminA;
    private GroupIndustryProjectMember $ownerMembership;
    private GroupIndustryProjectMember $adminAMembership;
    private GroupIndustryProjectMember $adminBMembership;
    private GroupIndustryProjectMember $memberM;
    private GroupIndustryProjectMember $pendingQ;
    private User $memberMUser;

    protected function setUp(): void
    {
        $this->createSabreBatch();
        $this->ownerMembership = $this->sabreBatchMemberships[0];
        $this->adminA = $this->userOfCorporation(self::FOREIGN_CORPORATION_ID);
        $this->adminAMembership = $this->addSabreBatchMembership($this->adminA, GroupMemberRole::Admin, GroupMemberStatus::Accepted);
        $this->adminBMembership = $this->addSabreBatchMembership($this->userOfCorporation(self::OWNER_CORPORATION_ID), GroupMemberRole::Admin, GroupMemberStatus::Accepted);
        $this->memberMUser = $this->userOfCorporation(self::FOREIGN_CORPORATION_ID);
        $this->memberM = $this->addSabreBatchMembership($this->memberMUser, GroupMemberRole::Member, GroupMemberStatus::Accepted);
        $this->pendingQ = $this->addSabreBatchMembership($this->userOfCorporation(self::FOREIGN_CORPORATION_ID), GroupMemberRole::Member, GroupMemberStatus::Pending);

        $this->security = $this->createStub(Security::class);
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
        $this->entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });
        $memberRepository = $this->sabreBatchMemberRepository();

        $this->processor = new UpdateGroupMemberProcessor(
            $this->security,
            $this->sabreBatchProjectRepository(),
            $memberRepository,
            $this->entityManager,
            new GroupProjectAccessChecker($memberRepository),
            new GroupIndustryResourceMapper(),
        );
    }

    public function testAdminCannotDemoteAnotherAdmin(): void
    {
        $this->actAs($this->adminA);

        self::assertSame(self::ONLY_THE_OWNER_MANAGES_AN_ADMIN, $this->patch($this->adminBMembership, role: 'member'));
        self::assertSame(['admin', 'accepted', 0], $this->stateOf($this->adminBMembership));
    }

    public function testAdminCannotAcceptAnotherAdminBeforeTheBodyIsValidated(): void
    {
        $this->actAs($this->adminA);

        self::assertSame(self::ONLY_THE_OWNER_MANAGES_AN_ADMIN, $this->patch($this->adminBMembership, status: 'accepted'));
        self::assertSame(['admin', 'accepted', 0], $this->stateOf($this->adminBMembership));
    }

    public function testAdminCannotDemoteHimself(): void
    {
        $this->actAs($this->adminA);

        self::assertSame(self::ONLY_THE_OWNER_MANAGES_AN_ADMIN, $this->patch($this->adminAMembership, role: 'member'));
        self::assertSame(['admin', 'accepted', 0], $this->stateOf($this->adminAMembership));
    }

    public function testAdminCannotChangeTheRoleOfARemovedAdmin(): void
    {
        $removedAdmin = $this->addSabreBatchMembership($this->userOfCorporation(self::FOREIGN_CORPORATION_ID), GroupMemberRole::Admin, GroupMemberStatus::Removed);
        $this->actAs($this->adminA);

        self::assertSame(self::ONLY_THE_OWNER_MANAGES_AN_ADMIN, $this->patch($removedAdmin, role: 'member'));
        self::assertSame(['admin', 'removed', 0], $this->stateOf($removedAdmin));
    }

    public function testAdminTargetingAnAdminIsRefusedBeforeAnInvalidRoleIsReported(): void
    {
        $this->actAs($this->adminA);

        self::assertSame(self::ONLY_THE_OWNER_MANAGES_AN_ADMIN, $this->patch($this->adminBMembership, role: 'overlord'));
    }

    public function testAdminTargetingAnAdminIsRefusedBeforeTheOwnerOnlyPromotion(): void
    {
        $this->actAs($this->adminA);

        self::assertSame(self::ONLY_THE_OWNER_MANAGES_AN_ADMIN, $this->patch($this->adminBMembership, role: 'admin'));
    }

    public function testAdminTargetingAnAdminIsRefusedBeforeAnInvalidStatusIsReported(): void
    {
        $this->actAs($this->adminA);

        self::assertSame(self::ONLY_THE_OWNER_MANAGES_AN_ADMIN, $this->patch($this->adminBMembership, status: 'removed'));
    }

    public function testOwnerDemotesAnAdmin(): void
    {
        $this->actAs($this->sabreBatchOwner);

        self::assertSame('granted member/accepted', $this->patch($this->adminBMembership, role: 'member'));
        self::assertSame(['member', 'accepted', 1], $this->stateOf($this->adminBMembership));
    }

    public function testOwnerPromotesAMemberToAdmin(): void
    {
        $this->actAs($this->sabreBatchOwner);

        self::assertSame('granted admin/accepted', $this->patch($this->memberM, role: 'admin'));
        self::assertSame(['admin', 'accepted', 1], $this->stateOf($this->memberM));
    }

    public function testAdminCannotPromoteAMember(): void
    {
        $this->actAs($this->adminA);

        self::assertSame(self::ONLY_THE_OWNER_PROMOTES, $this->patch($this->memberM, role: 'admin'));
        self::assertSame(['member', 'accepted', 0], $this->stateOf($this->memberM));
    }

    public function testAdminLeavesTheRoleOfAMemberToMember(): void
    {
        $this->actAs($this->adminA);

        self::assertSame('granted member/accepted', $this->patch($this->memberM, role: 'member'));
        self::assertSame(['member', 'accepted', 1], $this->stateOf($this->memberM));
    }

    public function testAdminAcceptsAPendingMember(): void
    {
        $this->actAs($this->adminA);

        self::assertSame('granted member/accepted', $this->patch($this->pendingQ, status: 'accepted'));
        self::assertSame(['member', 'accepted', 1], $this->stateOf($this->pendingQ));
    }

    public function testPlainMemberCannotManageAnAdmin(): void
    {
        $this->actAs($this->memberMUser);

        self::assertSame(self::ADMIN_OR_OWNER_ONLY, $this->patch($this->adminBMembership, role: 'member'));
        self::assertSame(['admin', 'accepted', 0], $this->stateOf($this->adminBMembership));
    }

    public function testAdminCannotModifyTheOwner(): void
    {
        $this->actAs($this->adminA);

        self::assertSame('400 Cannot modify the project owner', $this->patch($this->ownerMembership, role: 'member'));
        self::assertSame(['owner', 'accepted', 0], $this->stateOf($this->ownerMembership));
    }

    public function testInvalidRoleOnAMemberIsABadRequest(): void
    {
        $this->actAs($this->adminA);

        self::assertSame('400 Invalid role "overlord"', $this->patch($this->memberM, role: 'overlord'));
    }

    public function testOwnerRoleCannotBeGivenToAMember(): void
    {
        $this->actAs($this->sabreBatchOwner);

        self::assertSame('400 Invalid role "owner"', $this->patch($this->memberM, role: 'owner'));
        self::assertSame(['member', 'accepted', 0], $this->stateOf($this->memberM));
    }

    public function testStatusOtherThanAcceptedIsABadRequest(): void
    {
        $this->actAs($this->adminA);

        self::assertSame('400 Status can only be changed to "accepted"', $this->patch($this->pendingQ, status: 'removed'));
        self::assertSame(['member', 'pending', 0], $this->stateOf($this->pendingQ));
    }

    public function testAcceptedMemberCannotBeAcceptedAgain(): void
    {
        $this->actAs($this->adminA);

        self::assertSame('400 Only pending members can be accepted', $this->patch($this->memberM, status: 'accepted'));
    }

    public function testMissingUserIsUnauthorized(): void
    {
        $this->security->method('getUser')->willReturn(null);

        self::assertSame(self::UNAUTHORIZED, $this->patch($this->memberM, role: 'member'));
    }

    public function testUnknownProjectIsNotFoundBeforeTheAccessCheck(): void
    {
        $this->actAs($this->memberMUser);

        self::assertSame(self::PROJECT_NOT_FOUND, $this->patch($this->memberM, role: 'member', projectId: Uuid::v4()));
    }

    public function testUnknownMemberIsNotFoundAfterTheAccessCheck(): void
    {
        $this->actAs($this->adminA);
        $unknownMember = new GroupIndustryProjectMember();
        (new \ReflectionProperty(GroupIndustryProjectMember::class, 'id'))->setValue($unknownMember, Uuid::v4());

        self::assertSame(self::MEMBER_NOT_FOUND, $this->patch($unknownMember, role: 'member'));
    }

    public function testPlainMemberIsRefusedBeforeAnUnknownMemberIsReported(): void
    {
        $this->actAs($this->memberMUser);
        $unknownMember = new GroupIndustryProjectMember();
        (new \ReflectionProperty(GroupIndustryProjectMember::class, 'id'))->setValue($unknownMember, Uuid::v4());

        self::assertSame(self::ADMIN_OR_OWNER_ONLY, $this->patch($unknownMember, role: 'member'));
    }

    public function testMemberOfAnotherProjectIsNotFound(): void
    {
        $this->actAs($this->adminA);
        $otherProject = new GroupIndustryProject();
        $otherProject->setOwner($this->userOfCorporation(self::FOREIGN_CORPORATION_ID));
        $otherProjectAdmin = $this->addSabreBatchMembership($this->userOfCorporation(self::FOREIGN_CORPORATION_ID), GroupMemberRole::Admin, GroupMemberStatus::Accepted);
        $otherProjectAdmin->setProject($otherProject);

        self::assertSame(self::MEMBER_NOT_FOUND, $this->patch($otherProjectAdmin, role: 'member'));
        self::assertSame(['admin', 'accepted', 0], $this->stateOf($otherProjectAdmin));
    }

    public function testMissingMemberIdIsNotFound(): void
    {
        $this->actAs($this->adminA);

        self::assertSame(
            self::MEMBER_NOT_FOUND,
            $this->outcomeOf(fn () => $this->processor->process(new UpdateGroupMemberInput(), new Patch(), ['projectId' => $this->sabreBatch->getId()->toRfc4122()])),
        );
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function patch(GroupIndustryProjectMember $target, ?string $role = null, ?string $status = null, ?Uuid $projectId = null): string
    {
        $input = new UpdateGroupMemberInput();
        $input->role = $role;
        $input->status = $status;

        return $this->outcomeOf(function () use ($input, $target, $projectId): string {
            $resource = $this->processor->process($input, new Patch(), [
                'projectId' => ($projectId ?? $this->sabreBatch->getId())->toRfc4122(),
                'id' => $target->getId()->toRfc4122(),
            ]);

            return sprintf('granted %s/%s', $resource->role, $resource->status);
        });
    }

    private function stateOf(GroupIndustryProjectMember $member): array
    {
        return [$member->getRole()->value, $member->getStatus()->value, $this->flushes];
    }
}
