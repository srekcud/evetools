<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\Get;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use App\State\Provider\GroupIndustry\GroupProjectProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GroupProjectProvider::class)]
class GroupProjectProviderTest extends TestCase
{
    use GroupProjectAccessProfiles;

    private Security&Stub $security;
    private GroupProjectProvider $provider;

    protected function setUp(): void
    {
        $this->createSabreBatch();
        $this->security = $this->createStub(Security::class);
        $memberRepository = $this->sabreBatchMemberRepository();

        $this->provider = new GroupProjectProvider(
            $this->security,
            $this->sabreBatchProjectRepository(),
            $memberRepository,
            new GroupProjectAccessChecker($memberRepository),
            new GroupIndustryResourceMapper(),
        );
    }

    public function testAcceptedMemberFromAnotherCorporationSeesProjectWithHisRole(): void
    {
        $this->actAs($this->sabreBatchMember(self::FOREIGN_CORPORATION_ID, GroupMemberRole::Admin, GroupMemberStatus::Accepted));

        $resource = $this->provider->provide(new Get(), ['id' => $this->sabreBatch->getId()->toRfc4122()]);

        self::assertSame('Sabre batch', $resource->name);
        self::assertSame('admin', $resource->myRole);
    }

    public function testRemovedMemberFromAnotherCorporationIsDenied(): void
    {
        $this->actAs($this->sabreBatchMember(self::FOREIGN_CORPORATION_ID, GroupMemberRole::Member, GroupMemberStatus::Removed));

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('You do not have access to this project');

        $this->provider->provide(new Get(), ['id' => $this->sabreBatch->getId()->toRfc4122()]);
    }

    public function testRemovedMemberFromOwnerCorporationSeesProjectWithoutRole(): void
    {
        $this->actAs($this->sabreBatchMember(self::OWNER_CORPORATION_ID, GroupMemberRole::Admin, GroupMemberStatus::Removed));

        $resource = $this->provider->provide(new Get(), ['id' => $this->sabreBatch->getId()->toRfc4122()]);

        self::assertSame('Sabre batch', $resource->name);
        self::assertNull($resource->myRole);
    }

    public function testAcceptedMemberSeesAcceptedStatus(): void
    {
        $this->actAs($this->sabreBatchMember(self::FOREIGN_CORPORATION_ID, GroupMemberRole::Member, GroupMemberStatus::Accepted));

        $resource = $this->provider->provide(new Get(), ['id' => $this->sabreBatch->getId()->toRfc4122()]);

        self::assertSame('accepted', $resource->myStatus);
    }

    public function testSameCorporationNonMemberHasNoStatus(): void
    {
        $this->actAs($this->userOfCorporation(self::OWNER_CORPORATION_ID));

        $resource = $this->provider->provide(new Get(), ['id' => $this->sabreBatch->getId()->toRfc4122()]);

        self::assertNull($resource->myStatus);
        self::assertNull($resource->myRole);
    }

    public static function projectReadMatrix(): iterable
    {
        yield 'A1 owner' => [self::OWNER, 'Sabre batch owner/accepted'];
        yield 'A2 accepted admin' => [self::ACCEPTED_ADMIN, 'Sabre batch admin/accepted'];
        yield 'A3 accepted member' => [self::ACCEPTED_MEMBER, 'Sabre batch member/accepted'];
        yield 'A4 pending member' => [self::PENDING_MEMBER, self::NO_ACCESS];
        yield 'A5 owner corporation without membership' => [self::OWNER_CORPORATION_NON_MEMBER, 'Sabre batch -/-'];
        yield 'A6 owner corporation pending member' => [self::OWNER_CORPORATION_PENDING_MEMBER, 'Sabre batch -/-'];
        yield 'A7 owner corporation removed former admin' => [self::OWNER_CORPORATION_REMOVED_FORMER_ADMIN, 'Sabre batch -/-'];
        yield 'A8 removed member' => [self::REMOVED_MEMBER, self::NO_ACCESS];
        yield 'A9 stranger' => [self::STRANGER, self::NO_ACCESS];
        yield 'A10 user without corporation' => [self::USER_WITHOUT_CORPORATION, self::NO_ACCESS];
        yield 'A12 accepted admin of a completed project' => [self::ACCEPTED_ADMIN_OF_COMPLETED_PROJECT, 'Sabre batch admin/accepted'];
        yield 'A13 stranger to a completed project' => [self::STRANGER_TO_COMPLETED_PROJECT, self::NO_ACCESS];
    }

    #[DataProvider('projectReadMatrix')]
    public function testProjectIsReadWithTheRoleAndStatusOfTheAcceptedMembershipOnly(string $profile, string $expectedOutcome): void
    {
        $this->actAs($this->userWithProfile($profile));

        self::assertSame($expectedOutcome, $this->readSabreBatch($this->sabreBatch->getId()));
    }

    public function testUnknownProjectIsNotFoundBeforeAnyAccessCheck(): void
    {
        $this->actAs($this->userOfCorporation(self::FOREIGN_CORPORATION_ID));

        self::assertSame(self::PROJECT_NOT_FOUND, $this->readSabreBatch(Uuid::v4()));
    }

    public function testMissingUserIsUnauthorized(): void
    {
        $this->security->method('getUser')->willReturn(null);

        self::assertSame(self::UNAUTHORIZED, $this->readSabreBatch($this->sabreBatch->getId()));
    }

    public function testOwnerWithoutCorporationNorMembershipReadsHisProjectByIdentity(): void
    {
        $this->sabreBatchMemberships = [];
        $this->createSabreBatch(ownerCorporationId: null, withOwnerMembership: false);
        $this->actAs($this->sabreBatchOwner);

        self::assertSame('Sabre batch -/-', $this->readSabreBatch($this->sabreBatch->getId()));
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function readSabreBatch(Uuid $projectId): string
    {
        return $this->outcomeOf(function () use ($projectId): string {
            $resource = $this->provider->provide(new Get(), ['id' => $projectId->toRfc4122()]);

            return sprintf('%s %s/%s', $resource->name, $resource->myRole ?? '-', $resource->myStatus ?? '-');
        });
    }
}
