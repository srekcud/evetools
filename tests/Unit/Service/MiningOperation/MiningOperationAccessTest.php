<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationVisibility;
use App\Service\MiningOperation\MiningOperationAccess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

#[CoversClass(MiningOperationAccess::class)]
#[Group('issue-262')]
final class MiningOperationAccessTest extends TestCase
{
    use BuildsSpodumainOperation;

    private const string NO_ACCESS = 'You do not have access to this operation';
    private const string NOT_AN_ADMINISTRATOR = 'Only the operation owner or an admin can perform this action';

    private MiningOperationAccess $access;
    private MiningOperation $operation;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->access = new MiningOperationAccess();
        $this->operation = $this->spodumainOperation();
    }

    public function testOwnerReadsParticipatesAndAdministers(): void
    {
        self::assertSame(['read' => true, 'participate' => true, 'administer' => true], $this->levelsOf($this->aria));
    }

    public function testAcceptedMemberReadsAndParticipatesButDoesNotAdminister(): void
    {
        $this->addMembership($this->operation, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt']);

        self::assertSame(['read' => true, 'participate' => true, 'administer' => false], $this->levelsOf($this->brann));
        $this->assertDenied(fn () => $this->access->assertCanAdminister($this->brann, $this->operation), self::NOT_AN_ADMINISTRATOR);
    }

    public function testPendingMemberOfAnotherCorporationNeitherReadsNorParticipates(): void
    {
        $this->addMembership($this->operation, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Cyra Lune']);

        $this->assertDenied(fn () => $this->access->assertCanRead($this->cyra, $this->operation), self::NO_ACCESS);
        $this->assertDenied(fn () => $this->access->assertCanParticipate($this->cyra, $this->operation), self::NO_ACCESS);
    }

    public function testCorporationMemberWithoutMembershipReadsACorporationOperationWithoutParticipating(): void
    {
        self::assertTrue($this->access->canRead($this->elia, $this->operation));
        $this->assertDenied(fn () => $this->access->assertCanParticipate($this->elia, $this->operation), self::NO_ACCESS);
    }

    public function testPendingCorporationMemberReadsACorporationOperationWithoutParticipating(): void
    {
        $this->addMembership($this->operation, $this->elia, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Elia Varr']);

        self::assertTrue($this->access->canRead($this->elia, $this->operation));
        $this->assertDenied(fn () => $this->access->assertCanParticipate($this->elia, $this->operation), self::NO_ACCESS);
    }

    public function testRemovedCorporationMemberReadsACorporationOperationWithoutParticipating(): void
    {
        $this->addMembership($this->operation, $this->elia, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Removed, []);

        self::assertTrue($this->access->canRead($this->elia, $this->operation));
        $this->assertDenied(fn () => $this->access->assertCanParticipate($this->elia, $this->operation), self::NO_ACCESS);
    }

    public function testCorporationMemberWithoutMembershipCannotReadAPrivateOperation(): void
    {
        $privateOperation = $this->spodumainOperation(visibility: MiningOperationVisibility::Private);

        $this->assertDenied(fn () => $this->access->assertCanRead($this->elia, $privateOperation), self::NO_ACCESS);
        $this->assertDenied(fn () => $this->access->assertCanParticipate($this->elia, $privateOperation), self::NO_ACCESS);
    }

    public function testPilotOfAnotherCorporationWithoutMembershipCannotRead(): void
    {
        $this->assertDenied(fn () => $this->access->assertCanRead($this->cyra, $this->operation), self::NO_ACCESS);
        $this->assertDenied(fn () => $this->access->assertCanParticipate($this->cyra, $this->operation), self::NO_ACCESS);
    }

    public function testPilotWithoutKnownCorporationAndWithoutMembershipCannotRead(): void
    {
        $this->assertDenied(fn () => $this->access->assertCanRead($this->dorn, $this->operation), self::NO_ACCESS);
        $this->assertDenied(fn () => $this->access->assertCanParticipate($this->dorn, $this->operation), self::NO_ACCESS);
    }

    public function testAcceptedAdminAdministers(): void
    {
        $this->addMembership($this->operation, $this->brann, MiningOperationMemberRole::Admin, MiningOperationMemberStatus::Accepted, ['Brann Holt']);

        self::assertSame(['read' => true, 'participate' => true, 'administer' => true], $this->levelsOf($this->brann));
    }

    public function testPendingAdminDoesNotAdminister(): void
    {
        $this->addMembership($this->operation, $this->brann, MiningOperationMemberRole::Admin, MiningOperationMemberStatus::Pending, ['Brann Holt']);

        $this->assertDenied(fn () => $this->access->assertCanAdminister($this->brann, $this->operation), self::NOT_AN_ADMINISTRATOR);
    }

    public function testAdminCannotManageAnotherAdmin(): void
    {
        $this->addMembership($this->operation, $this->brann, MiningOperationMemberRole::Admin, MiningOperationMemberStatus::Accepted, ['Brann Holt']);
        $eliaMembership = $this->addMembership($this->operation, $this->elia, MiningOperationMemberRole::Admin, MiningOperationMemberStatus::Accepted, ['Elia Varr']);

        $this->assertDenied(
            fn () => $this->access->assertCanManageMember($this->brann, $this->operation, $eliaMembership),
            'Only the operation owner can manage an admin',
        );
    }

    public function testOwnerAdministersAndManagesAnAdmin(): void
    {
        $eliaMembership = $this->addMembership($this->operation, $this->elia, MiningOperationMemberRole::Admin, MiningOperationMemberStatus::Accepted, ['Elia Varr']);

        $this->access->assertCanManageMember($this->aria, $this->operation, $eliaMembership);

        self::assertTrue($this->access->canAdminister($this->aria, $this->operation));
    }

    private function levelsOf(User $user): array
    {
        return [
            'read' => $this->access->canRead($user, $this->operation),
            'participate' => $this->access->canParticipate($user, $this->operation),
            'administer' => $this->access->canAdminister($user, $this->operation),
        ];
    }

    private function assertDenied(\Closure $check, string $expectedMessage): void
    {
        try {
            $check();
        } catch (AccessDeniedHttpException $denial) {
            self::assertSame($expectedMessage, $denial->getMessage());

            return;
        }

        self::fail(sprintf('Access must be denied with "%s"', $expectedMessage));
    }
}
