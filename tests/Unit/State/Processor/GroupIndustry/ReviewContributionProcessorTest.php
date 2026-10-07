<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\GroupIndustry\GroupIndustryContributionResource;
use App\ApiResource\Input\GroupIndustry\ReviewContributionInput;
use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Repository\GroupIndustryContributionRepository;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\GroupIndustryProjectRepository;
use App\Service\GroupIndustry\GroupIndustryContributionService;
use App\Service\JitaMarketService;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\GroupIndustry\ReviewContributionProcessor;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

/**
 * Rule under test: a contribution is reviewed (approved or rejected) by another owner/admin
 * than its contributor. Only exception: the project owner may review his own contribution
 * when he is the only owner/admin of the project (nobody else could ever review it).
 *
 * The project members are kept both in GroupIndustryProject::getMembers() and in a fake
 * member repository, so the rule can be implemented with either source.
 */
#[CoversClass(ReviewContributionProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
class ReviewContributionProcessorTest extends TestCase
{
    private const int CONTRIBUTED_QUANTITY = 1500;
    private const int ALREADY_FULFILLED_QUANTITY = 2000;

    private Security&Stub $security;
    private GroupIndustryProjectRepository&Stub $projectRepository;
    private GroupIndustryContributionRepository&Stub $contributionRepository;
    private GroupIndustryProjectMemberRepository&Stub $memberRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private ReviewContributionProcessor $processor;

    /** @var list<GroupIndustryProjectMember> */
    private array $members = [];

    private User $owner;
    private GroupIndustryProject $project;
    private GroupIndustryProjectMember $ownerMember;
    private GroupIndustryBomItem $bomItem;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->projectRepository = $this->createStub(GroupIndustryProjectRepository::class);
        $this->contributionRepository = $this->createStub(GroupIndustryContributionRepository::class);
        $this->memberRepository = $this->createStub(GroupIndustryProjectMemberRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);

        $this->memberRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?GroupIndustryProjectMember => $this->membersMatching($criteria)[0] ?? null,
        );
        $this->memberRepository->method('findBy')->willReturnCallback(
            fn (array $criteria): array => $this->membersMatching($criteria),
        );
        $this->memberRepository->method('count')->willReturnCallback(
            fn (array $criteria = []): int => \count($this->membersMatching($criteria)),
        );

        $this->processor = new ReviewContributionProcessor(
            $this->security,
            $this->projectRepository,
            $this->contributionRepository,
            new GroupIndustryContributionService(
                $this->contributionRepository,
                $this->createStub(JitaMarketService::class),
                $this->entityManager,
            ),
            new GroupProjectAccessChecker($this->memberRepository),
            new GroupIndustryResourceMapper(),
            new MercurePublisherService($this->hub, new NullLogger()),
        );

        $this->owner = $this->createStub(User::class);
        $this->project = new GroupIndustryProject();
        $this->project->setOwner($this->owner);
        $this->assignId($this->project);
        $this->projectRepository->method('find')->willReturn($this->project);

        $this->ownerMember = $this->addMember($this->owner, GroupMemberRole::Owner);

        $this->bomItem = new GroupIndustryBomItem();
        $this->bomItem->setProject($this->project);
        $this->bomItem->setTypeId(34);
        $this->bomItem->setTypeName('Tritanium');
        $this->bomItem->setRequiredQuantity(10000);
        $this->bomItem->setFulfilledQuantity(self::ALREADY_FULFILLED_QUANTITY);
        $this->assignId($this->bomItem);
    }

    // --- New rule: no self-review ---------------------------------------------------------

    public function testAdminCannotApproveHisOwnContribution(): void
    {
        $admin = $this->createStub(User::class);
        $adminMember = $this->addMember($admin, GroupMemberRole::Admin);
        $contribution = $this->pendingContributionOf($adminMember);

        $this->assertSelfReviewIsRefused($admin, $contribution, 'approved');
    }

    public function testAdminCannotRejectHisOwnContribution(): void
    {
        $admin = $this->createStub(User::class);
        $adminMember = $this->addMember($admin, GroupMemberRole::Admin);
        $contribution = $this->pendingContributionOf($adminMember);

        $this->assertSelfReviewIsRefused($admin, $contribution, 'rejected');
    }

    public function testOwnerCannotApproveHisOwnContributionWhenAnAdminCanReviewIt(): void
    {
        $this->addMember($this->createStub(User::class), GroupMemberRole::Admin);
        $contribution = $this->pendingContributionOf($this->ownerMember);

        $this->assertSelfReviewIsRefused($this->owner, $contribution, 'approved');
    }

    public function testOwnerCannotRejectHisOwnContributionWhenAnAdminCanReviewIt(): void
    {
        $this->addMember($this->createStub(User::class), GroupMemberRole::Admin);
        $contribution = $this->pendingContributionOf($this->ownerMember);

        $this->assertSelfReviewIsRefused($this->owner, $contribution, 'rejected');
    }

    // --- Exception: the sole owner/admin may review his own contribution -------------------

    public function testSoleOwnerWithoutAdminCanApproveHisOwnContribution(): void
    {
        $this->addMember($this->createStub(User::class), GroupMemberRole::Member);
        $contribution = $this->pendingContributionOf($this->ownerMember);

        $this->entityManager->expects($this->once())->method('flush');
        $this->expectOneMercureEvent('contribution_approved');

        $resource = $this->review($this->owner, $contribution, 'approved');

        self::assertSame('approved', $resource->status);
        self::assertSame(ContributionStatus::Approved, $contribution->getStatus());
        self::assertSame($this->owner, $contribution->getReviewedBy());
        self::assertSame(3500, $this->bomItem->getFulfilledQuantity());
    }

    public function testSoleOwnerWithoutAdminCanRejectHisOwnContribution(): void
    {
        $this->addMember($this->createStub(User::class), GroupMemberRole::Member);
        $contribution = $this->pendingContributionOf($this->ownerMember);

        $this->entityManager->expects($this->once())->method('flush');
        $this->expectOneMercureEvent('contribution_rejected');

        $resource = $this->review($this->owner, $contribution, 'rejected');

        self::assertSame('rejected', $resource->status);
        self::assertSame(ContributionStatus::Rejected, $contribution->getStatus());
        self::assertSame(self::ALREADY_FULFILLED_QUANTITY, $this->bomItem->getFulfilledQuantity());
    }

    public function testOwnerWhoseOnlyAdminIsNotAcceptedCanApproveHisOwnContribution(): void
    {
        // A pending (not yet accepted) admin cannot review anything, so the owner stays the only reviewer.
        $this->addMember($this->createStub(User::class), GroupMemberRole::Admin, GroupMemberStatus::Pending);
        $contribution = $this->pendingContributionOf($this->ownerMember);

        $this->entityManager->expects($this->once())->method('flush');
        $this->expectOneMercureEvent('contribution_approved');

        $this->review($this->owner, $contribution, 'approved');

        self::assertSame(ContributionStatus::Approved, $contribution->getStatus());
        self::assertSame(3500, $this->bomItem->getFulfilledQuantity());
    }

    // --- Guards: reviewing someone else's contribution ------------------------------------

    public function testAdminCanApproveAnotherMembersContribution(): void
    {
        $admin = $this->createStub(User::class);
        $this->addMember($admin, GroupMemberRole::Admin);
        $contributor = $this->addMember($this->createStub(User::class), GroupMemberRole::Member);
        $contribution = $this->pendingContributionOf($contributor);

        $this->entityManager->expects($this->once())->method('flush');
        $this->expectOneMercureEvent('contribution_approved');

        $resource = $this->review($admin, $contribution, 'approved');

        self::assertSame('approved', $resource->status);
        self::assertSame(ContributionStatus::Approved, $contribution->getStatus());
        self::assertSame($admin, $contribution->getReviewedBy());
        self::assertSame(3500, $this->bomItem->getFulfilledQuantity());
    }

    public function testAdminCanApproveTheOwnersContribution(): void
    {
        $admin = $this->createStub(User::class);
        $this->addMember($admin, GroupMemberRole::Admin);
        $contribution = $this->pendingContributionOf($this->ownerMember);

        $this->entityManager->expects($this->once())->method('flush');

        $this->review($admin, $contribution, 'approved');

        self::assertSame(ContributionStatus::Approved, $contribution->getStatus());
        self::assertSame($admin, $contribution->getReviewedBy());
    }

    public function testOwnerCanRejectAnotherMembersContribution(): void
    {
        $contributor = $this->addMember($this->createStub(User::class), GroupMemberRole::Member);
        $contribution = $this->pendingContributionOf($contributor);

        $this->entityManager->expects($this->once())->method('flush');
        $this->expectOneMercureEvent('contribution_rejected');

        $resource = $this->review($this->owner, $contribution, 'rejected');

        self::assertSame('rejected', $resource->status);
        self::assertSame(ContributionStatus::Rejected, $contribution->getStatus());
        self::assertSame($this->owner, $contribution->getReviewedBy());
        self::assertSame(self::ALREADY_FULFILLED_QUANTITY, $this->bomItem->getFulfilledQuantity());
    }

    // --- Guards: existing authorization and state rules -----------------------------------

    public function testMemberCannotReviewAContribution(): void
    {
        $member = $this->createStub(User::class);
        $this->addMember($member, GroupMemberRole::Member);
        $contributor = $this->addMember($this->createStub(User::class), GroupMemberRole::Member);
        $contribution = $this->pendingContributionOf($contributor);

        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');

        try {
            $this->review($member, $contribution, 'approved');
            self::fail('A member must not be able to review a contribution');
        } catch (AccessDeniedHttpException $e) {
            self::assertSame('Only project owner or admin can perform this action', $e->getMessage());
        }

        self::assertSame(ContributionStatus::Pending, $contribution->getStatus());
    }

    public function testAdminWhoseMembershipIsNotAcceptedCannotReviewAContribution(): void
    {
        $pendingAdmin = $this->createStub(User::class);
        $this->addMember($pendingAdmin, GroupMemberRole::Admin, GroupMemberStatus::Pending);
        $contributor = $this->addMember($this->createStub(User::class), GroupMemberRole::Member);
        $contribution = $this->pendingContributionOf($contributor);

        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(AccessDeniedHttpException::class);

        $this->review($pendingAdmin, $contribution, 'approved');
    }

    public function testCannotApproveAnAlreadyApprovedContribution(): void
    {
        $contributor = $this->addMember($this->createStub(User::class), GroupMemberRole::Member);
        $contribution = $this->pendingContributionOf($contributor);
        $contribution->setStatus(ContributionStatus::Approved);

        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Cannot approve a contribution with status "approved"');

        $this->review($this->owner, $contribution, 'approved');
    }

    public function testContributionOfAnotherProjectIsNotFound(): void
    {
        $otherProject = new GroupIndustryProject();
        $otherProject->setOwner($this->createStub(User::class));
        $contributor = $this->addMember($this->createStub(User::class), GroupMemberRole::Member);
        $contribution = $this->pendingContributionOf($contributor);
        $contribution->setProject($otherProject);

        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Contribution not found');

        $this->review($this->owner, $contribution, 'approved');
    }

    // --- Helpers --------------------------------------------------------------------------

    private function assertSelfReviewIsRefused(User $reviewer, GroupIndustryContribution $contribution, string $decision): void
    {
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');

        try {
            $this->review($reviewer, $contribution, $decision);
            self::fail('A user must not be able to review his own contribution');
        } catch (BadRequestHttpException $e) {
            self::assertSame('You cannot review your own contribution', $e->getMessage());
        }

        self::assertSame(ContributionStatus::Pending, $contribution->getStatus());
        self::assertNull($contribution->getReviewedBy());
        self::assertNull($contribution->getReviewedAt());
        self::assertSame(self::ALREADY_FULFILLED_QUANTITY, $this->bomItem->getFulfilledQuantity());
    }

    private function review(User $reviewer, GroupIndustryContribution $contribution, string $decision): GroupIndustryContributionResource
    {
        $this->security->method('getUser')->willReturn($reviewer);
        $this->contributionRepository->method('find')->willReturn($contribution);

        $input = new ReviewContributionInput();
        $input->status = $decision;

        return $this->processor->process($input, new Patch(), [
            'projectId' => $this->project->getId()->toRfc4122(),
            'id' => $contribution->getId()->toRfc4122(),
        ]);
    }

    private function addMember(User $user, GroupMemberRole $role, GroupMemberStatus $status = GroupMemberStatus::Accepted): GroupIndustryProjectMember
    {
        $member = new GroupIndustryProjectMember();
        $member->setUser($user);
        $member->setRole($role);
        $member->setStatus($status);
        $this->assignId($member);
        $this->project->addMember($member);
        $this->members[] = $member;

        return $member;
    }

    private function pendingContributionOf(GroupIndustryProjectMember $contributor): GroupIndustryContribution
    {
        $contribution = new GroupIndustryContribution();
        $contribution->setProject($this->project);
        $contribution->setMember($contributor);
        $contribution->setBomItem($this->bomItem);
        $contribution->setType(ContributionType::Material);
        $contribution->setQuantity(self::CONTRIBUTED_QUANTITY);
        $contribution->setEstimatedValue(7500000.0);
        $contribution->setStatus(ContributionStatus::Pending);
        $contribution->setIsAutoDetected(false);
        $contribution->setIsVerified(false);
        $this->assignId($contribution);

        return $contribution;
    }

    private function expectOneMercureEvent(string $action): void
    {
        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with($this->callback(static function (Update $update) use ($action): bool {
                $payload = json_decode($update->getData(), true);

                return $payload['action'] === $action;
            }));
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return list<GroupIndustryProjectMember>
     */
    private function membersMatching(array $criteria): array
    {
        return array_values(array_filter(
            $this->members,
            static function (GroupIndustryProjectMember $member) use ($criteria): bool {
                foreach ($criteria as $field => $expected) {
                    $actual = $member->{'get' . ucfirst($field)}();
                    $matches = \is_array($expected) ? \in_array($actual, $expected, true) : $actual === $expected;
                    if (!$matches) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    private function assignId(object $entity): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, Uuid::v4());
    }
}
