<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\GroupIndustry;

use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

#[CoversClass(GroupProjectAccessChecker::class)]
class GroupProjectAccessCheckerTest extends TestCase
{
    use GroupProjectAccessProfiles;

    private const string ONLY_THE_OWNER_MANAGES_AN_ADMIN = '403 Only the project owner can manage an admin';

    private GroupProjectAccessChecker $accessChecker;

    protected function setUp(): void
    {
        $this->createSabreBatch();
        $this->accessChecker = new GroupProjectAccessChecker($this->sabreBatchMemberRepository());
    }

    public function testOwnerHasAccess(): void
    {
        $this->expectNotToPerformAssertions();

        $this->accessChecker->assertAcceptedMember($this->sabreBatchOwner, $this->sabreBatch);
        $this->accessChecker->assertAdminOrOwner($this->sabreBatchOwner, $this->sabreBatch);
    }

    public function testAcceptedMemberHasAccess(): void
    {
        $user = $this->sabreBatchMember(self::FOREIGN_CORPORATION_ID, GroupMemberRole::Member, GroupMemberStatus::Accepted);

        $this->expectNotToPerformAssertions();

        $this->accessChecker->assertAcceptedMember($user, $this->sabreBatch);
    }

    public function testPendingMemberHasNoAccess(): void
    {
        $user = $this->sabreBatchMember(self::FOREIGN_CORPORATION_ID, GroupMemberRole::Member, GroupMemberStatus::Pending);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('You do not have access to this project');

        $this->accessChecker->assertAcceptedMember($user, $this->sabreBatch);
    }

    public function testRemovedMemberHasNoAccess(): void
    {
        $user = $this->sabreBatchMember(self::FOREIGN_CORPORATION_ID, GroupMemberRole::Member, GroupMemberStatus::Removed);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('You do not have access to this project');

        $this->accessChecker->assertAcceptedMember($user, $this->sabreBatch);
    }

    public function testRemovedAdminCanNoLongerAdministrate(): void
    {
        $user = $this->sabreBatchMember(self::FOREIGN_CORPORATION_ID, GroupMemberRole::Admin, GroupMemberStatus::Removed);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Only project owner or admin can perform this action');

        $this->accessChecker->assertAdminOrOwner($user, $this->sabreBatch);
    }

    public function testAcceptedAdminOfAnotherProjectCannotAdministrateThisProject(): void
    {
        $user = $this->sabreBatchMemberOfAnotherProject(self::FOREIGN_CORPORATION_ID, GroupMemberRole::Admin, GroupMemberStatus::Accepted);

        self::assertSame(self::ADMIN_OR_OWNER_ONLY, $this->outcomeOf(fn () => $this->accessChecker->assertAdminOrOwner($user, $this->sabreBatch)));
    }

    public function testAcceptedMemberOfAnotherProjectCannotParticipateInThisProject(): void
    {
        $user = $this->sabreBatchMemberOfAnotherProject(self::FOREIGN_CORPORATION_ID, GroupMemberRole::Member, GroupMemberStatus::Accepted);

        self::assertSame(self::NO_ACCESS, $this->outcomeOf(fn () => $this->accessChecker->assertAcceptedMember($user, $this->sabreBatch)));
    }

    public function testAcceptedMemberOfAnotherProjectCannotReadThisProject(): void
    {
        $user = $this->sabreBatchMemberOfAnotherProject(self::FOREIGN_CORPORATION_ID, GroupMemberRole::Member, GroupMemberStatus::Accepted);

        self::assertSame(self::NO_ACCESS, $this->outcomeOf(fn () => $this->accessChecker->assertCanRead($user, $this->sabreBatch)));
    }

    #[DataProvider('readAccessMatrix')]
    public function testReadAccessIsOpenToTheOwnerAcceptedMembersAndTheOwnerCorporation(string $profile, string $expectedOutcome): void
    {
        $user = $this->userWithProfile($profile);

        self::assertSame($expectedOutcome, $this->outcomeOf(fn () => $this->accessChecker->assertCanRead($user, $this->sabreBatch)));
    }

    #[DataProvider('participationAccessMatrix')]
    public function testParticipationIsOpenToTheOwnerAndAcceptedMembersOnly(string $profile, string $expectedOutcome): void
    {
        $user = $this->userWithProfile($profile);

        self::assertSame($expectedOutcome, $this->outcomeOf(fn () => $this->accessChecker->assertAcceptedMember($user, $this->sabreBatch)));
    }

    #[DataProvider('administrationAccessMatrix')]
    public function testAdministrationIsOpenToTheOwnerAndAcceptedAdminsOnly(string $profile, string $expectedOutcome): void
    {
        $user = $this->userWithProfile($profile);

        self::assertSame($expectedOutcome, $this->outcomeOf(fn () => $this->accessChecker->assertAdminOrOwner($user, $this->sabreBatch)));
    }

    public function testOwnerWithoutCorporationNorMembershipKeepsEveryAccessLevelByIdentity(): void
    {
        $this->sabreBatchMemberships = [];
        $this->createSabreBatch(ownerCorporationId: null, withOwnerMembership: false);
        $owner = $this->sabreBatchOwner;

        self::assertSame(
            [self::GRANTED, self::GRANTED, self::GRANTED],
            [
                $this->outcomeOf(fn () => $this->accessChecker->assertCanRead($owner, $this->sabreBatch)),
                $this->outcomeOf(fn () => $this->accessChecker->assertAcceptedMember($owner, $this->sabreBatch)),
                $this->outcomeOf(fn () => $this->accessChecker->assertAdminOrOwner($owner, $this->sabreBatch)),
            ],
        );
    }

    public function testUserWithoutCorporationCannotReadTheProjectOfAnOwnerWithoutCorporation(): void
    {
        $this->sabreBatchMemberships = [];
        $this->createSabreBatch(ownerCorporationId: null);
        $user = $this->userOfCorporation(null);

        self::assertSame(self::NO_ACCESS, $this->outcomeOf(fn () => $this->accessChecker->assertCanRead($user, $this->sabreBatch)));
    }

    public function testAcceptedMemberWithoutCorporationReadsTheProjectOfAnOwnerWithoutCorporation(): void
    {
        $this->sabreBatchMemberships = [];
        $this->createSabreBatch(ownerCorporationId: null);
        $user = $this->sabreBatchMember(null, GroupMemberRole::Member, GroupMemberStatus::Accepted);

        self::assertSame(self::GRANTED, $this->outcomeOf(fn () => $this->accessChecker->assertCanRead($user, $this->sabreBatch)));
    }

    public function testOwnerManagesAnAcceptedAdmin(): void
    {
        $admin = $this->membershipOf(GroupMemberRole::Admin, GroupMemberStatus::Accepted);

        self::assertSame(self::GRANTED, $this->manageOutcome($this->sabreBatchOwner, $admin));
    }

    public function testOwnerManagesAnAcceptedMember(): void
    {
        $member = $this->membershipOf(GroupMemberRole::Member, GroupMemberStatus::Accepted);

        self::assertSame(self::GRANTED, $this->manageOutcome($this->sabreBatchOwner, $member));
    }

    public function testAdminCannotManageAnotherAcceptedAdmin(): void
    {
        $actingAdmin = $this->sabreBatchMember(self::FOREIGN_CORPORATION_ID, GroupMemberRole::Admin, GroupMemberStatus::Accepted);
        $otherAdmin = $this->membershipOf(GroupMemberRole::Admin, GroupMemberStatus::Accepted);

        self::assertSame(self::ONLY_THE_OWNER_MANAGES_AN_ADMIN, $this->manageOutcome($actingAdmin, $otherAdmin));
    }

    public function testAdminCannotManageHimself(): void
    {
        $actingAdmin = $this->sabreBatchMember(self::FOREIGN_CORPORATION_ID, GroupMemberRole::Admin, GroupMemberStatus::Accepted);
        $ownMembership = $this->sabreBatchMemberships[array_key_last($this->sabreBatchMemberships)];

        self::assertSame(self::ONLY_THE_OWNER_MANAGES_AN_ADMIN, $this->manageOutcome($actingAdmin, $ownMembership));
    }

    public function testAdminCannotManageARemovedAdmin(): void
    {
        $actingAdmin = $this->sabreBatchMember(self::FOREIGN_CORPORATION_ID, GroupMemberRole::Admin, GroupMemberStatus::Accepted);
        $removedAdmin = $this->membershipOf(GroupMemberRole::Admin, GroupMemberStatus::Removed);

        self::assertSame(self::ONLY_THE_OWNER_MANAGES_AN_ADMIN, $this->manageOutcome($actingAdmin, $removedAdmin));
    }

    public function testAdminManagesAnAcceptedMember(): void
    {
        $actingAdmin = $this->sabreBatchMember(self::FOREIGN_CORPORATION_ID, GroupMemberRole::Admin, GroupMemberStatus::Accepted);
        $member = $this->membershipOf(GroupMemberRole::Member, GroupMemberStatus::Accepted);

        self::assertSame(self::GRANTED, $this->manageOutcome($actingAdmin, $member));
    }

    public function testAdminManagesAPendingMember(): void
    {
        $actingAdmin = $this->sabreBatchMember(self::FOREIGN_CORPORATION_ID, GroupMemberRole::Admin, GroupMemberStatus::Accepted);
        $pendingMember = $this->membershipOf(GroupMemberRole::Member, GroupMemberStatus::Pending);

        self::assertSame(self::GRANTED, $this->manageOutcome($actingAdmin, $pendingMember));
    }

    private function membershipOf(GroupMemberRole $role, GroupMemberStatus $status): GroupIndustryProjectMember
    {
        return $this->addSabreBatchMembership($this->userOfCorporation(self::FOREIGN_CORPORATION_ID), $role, $status);
    }

    private function manageOutcome(User $user, GroupIndustryProjectMember $target): string
    {
        return $this->outcomeOf(fn () => $this->accessChecker->assertCanManageMember($user, $this->sabreBatch, $target));
    }
}
