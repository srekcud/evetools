<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Patch;
use App\Service\Industry\Project\LineReservationService;
use App\ApiResource\Industry\Project\ProjectContributionResource;
use App\ApiResource\Input\Industry\Project\ReviewContributionInput;
use App\Entity\Character;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ContributorPaidPurchaseReader;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\ProjectContributionService;
use App\Service\JitaMarketService;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\Industry\Project\ReviewContributionProcessor;
use App\State\Provider\Industry\Project\ProjectResourceMapper;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
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
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

/**
 * Rule under test: a contribution is reviewed (approved or rejected) by another owner/admin
 * than its contributor. Only exception: the project owner may review his own contribution
 * when he is the only owner/admin of the project (nobody else could ever review it).
 *
 * The project members are kept both in IndustryProject::getMembers() and in a fake
 * member repository, so the rule can be implemented with either source.
 */
#[CoversClass(ReviewContributionProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
class ReviewContributionProcessorTest extends TestCase
{
    private const int CONTRIBUTED_QUANTITY = 1500;
    private const int ALREADY_FULFILLED_QUANTITY = 2000;

    private Security&Stub $security;
    private IndustryProjectRepository&Stub $projectRepository;
    private IndustryProjectContributionRepository&Stub $contributionRepository;
    private IndustryProjectParticipantRepository&Stub $memberRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private ReviewContributionProcessor $processor;

    /** @var list<IndustryProjectParticipant> */
    private array $members = [];

    private User $owner;
    private IndustryProject $project;
    private IndustryProjectParticipant $ownerMember;
    private IndustryProjectBomLine $bomItem;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->projectRepository = $this->createStub(IndustryProjectRepository::class);
        $this->contributionRepository = $this->createStub(IndustryProjectContributionRepository::class);
        $this->memberRepository = $this->createStub(IndustryProjectParticipantRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);

        $this->memberRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?IndustryProjectParticipant => $this->membersMatching($criteria)[0] ?? null,
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
            new ProjectContributionService(
                $this->contributionRepository,
                $this->entityManager,
                $this->createStub(JitaMarketService::class),
                $this->createStub(LineReservationService::class),
                $this->createStub(IndustryProjectService::class),
                $this->createStub(ContributorPaidPurchaseReader::class),
            ),
            new ProjectAccessChecker($this->memberRepository),
            new ProjectResourceMapper(),
            new MercurePublisherService($this->hub, new NullLogger()),
        );

        $this->owner = $this->createStub(User::class);
        $this->project = (new IndustryProject());
        $this->project->setOwner($this->owner);
        $this->assignId($this->project);
        $this->projectRepository->method('find')->willReturn($this->project);

        $this->ownerMember = $this->addMember($this->owner, ParticipantRole::Owner);

        $this->bomItem = new IndustryProjectBomLine();
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
        $adminMember = $this->addMember($admin, ParticipantRole::Admin);
        $contribution = $this->pendingContributionOf($adminMember);

        $this->assertSelfReviewIsRefused($admin, $contribution, 'approved');
    }

    public function testAdminCannotRejectHisOwnContribution(): void
    {
        $admin = $this->createStub(User::class);
        $adminMember = $this->addMember($admin, ParticipantRole::Admin);
        $contribution = $this->pendingContributionOf($adminMember);

        $this->assertSelfReviewIsRefused($admin, $contribution, 'rejected');
    }

    public function testOwnerCannotApproveHisOwnContributionWhenAnAdminCanReviewIt(): void
    {
        $this->addMember($this->createStub(User::class), ParticipantRole::Admin);
        $contribution = $this->pendingContributionOf($this->ownerMember);

        $this->assertSelfReviewIsRefused($this->owner, $contribution, 'approved');
    }

    public function testOwnerCannotRejectHisOwnContributionWhenAnAdminCanReviewIt(): void
    {
        $this->addMember($this->createStub(User::class), ParticipantRole::Admin);
        $contribution = $this->pendingContributionOf($this->ownerMember);

        $this->assertSelfReviewIsRefused($this->owner, $contribution, 'rejected');
    }

    // --- Exception: the sole owner/admin may review his own contribution -------------------

    public function testSoleOwnerWithoutAdminCanApproveHisOwnContribution(): void
    {
        $this->addMember($this->createStub(User::class), ParticipantRole::Member);
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
        $this->addMember($this->createStub(User::class), ParticipantRole::Member);
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
        $this->addMember($this->createStub(User::class), ParticipantRole::Admin, ParticipantStatus::Pending);
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
        $this->addMember($admin, ParticipantRole::Admin);
        $contributor = $this->addMember($this->createStub(User::class), ParticipantRole::Member);
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
        $this->addMember($admin, ParticipantRole::Admin);
        $contribution = $this->pendingContributionOf($this->ownerMember);

        $this->entityManager->expects($this->once())->method('flush');

        $this->review($admin, $contribution, 'approved');

        self::assertSame(ContributionStatus::Approved, $contribution->getStatus());
        self::assertSame($admin, $contribution->getReviewedBy());
    }

    public function testOwnerCanApproveThePendingContributionOfARemovedMember(): void
    {
        $removedMember = $this->addMember($this->createStub(User::class), ParticipantRole::Member, ParticipantStatus::Removed);
        $contribution = $this->pendingContributionOf($removedMember);

        $this->entityManager->expects($this->once())->method('flush');
        $this->expectOneMercureEvent('contribution_approved');

        $resource = $this->review($this->owner, $contribution, 'approved');

        self::assertSame('approved', $resource->status);
        self::assertSame(ContributionStatus::Approved, $contribution->getStatus());
        self::assertSame($this->owner, $contribution->getReviewedBy());
        self::assertSame(ParticipantStatus::Removed, $removedMember->getStatus());
        self::assertSame(3500, $this->bomItem->getFulfilledQuantity());
    }

    public function testOwnerCanRejectAnotherMembersContribution(): void
    {
        $contributor = $this->addMember($this->createStub(User::class), ParticipantRole::Member);
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
        $this->addMember($member, ParticipantRole::Member);
        $contributor = $this->addMember($this->createStub(User::class), ParticipantRole::Member);
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
        $this->addMember($pendingAdmin, ParticipantRole::Admin, ParticipantStatus::Pending);
        $contributor = $this->addMember($this->createStub(User::class), ParticipantRole::Member);
        $contribution = $this->pendingContributionOf($contributor);

        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(AccessDeniedHttpException::class);

        $this->review($pendingAdmin, $contribution, 'approved');
    }

    public function testCannotApproveAnAlreadyApprovedContribution(): void
    {
        $contributor = $this->addMember($this->createStub(User::class), ParticipantRole::Member);
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
        $otherProject = (new IndustryProject());
        $otherProject->setOwner($this->createStub(User::class));
        $contributor = $this->addMember($this->createStub(User::class), ParticipantRole::Member);
        $contribution = $this->pendingContributionOf($contributor);
        $contribution->setProject($otherProject);

        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Contribution not found');

        $this->review($this->owner, $contribution, 'approved');
    }

    public function testOwnerCannotApproveAPendingContributionOnACompletedProject(): void
    {
        $this->project->setStatus(ProjectStatus::Completed);
        $contributor = $this->addMember($this->createStub(User::class), ParticipantRole::Member);
        $contribution = $this->pendingContributionOf($contributor);

        $this->assertReviewOnCompletedProjectIsRefused($contribution, 'approved');
    }

    public function testOwnerCannotRejectAPendingContributionOnACompletedProject(): void
    {
        $this->project->setStatus(ProjectStatus::Completed);
        $contributor = $this->addMember($this->createStub(User::class), ParticipantRole::Member);
        $contribution = $this->pendingContributionOf($contributor);

        $this->assertReviewOnCompletedProjectIsRefused($contribution, 'rejected');
    }

    public function testOwnerCanApproveAPendingContributionOnAnInProgressProject(): void
    {
        $this->project->setStatus(ProjectStatus::InProgress);
        $contributor = $this->addMember($this->createStub(User::class), ParticipantRole::Member);
        $contribution = $this->pendingContributionOf($contributor);

        $this->entityManager->expects($this->once())->method('flush');
        $this->expectOneMercureEvent('contribution_approved');

        $resource = $this->review($this->owner, $contribution, 'approved');

        self::assertSame('approved', $resource->status);
        self::assertSame(ContributionStatus::Approved, $contribution->getStatus());
        self::assertSame(3500, $this->bomItem->getFulfilledQuantity());
    }

    #[Group('issue-510')]
    public function testAdminRejectsAnApprovedDeclaredContributionThatDuplicatesADerivedOneAndTheLineIsDowngraded(): void
    {
        $admin = $this->createStub(User::class);
        $this->addMember($admin, ParticipantRole::Admin);
        $contributor = $this->addMember($this->createStub(User::class), ParticipantRole::Member);
        $declared = $this->approvedDeclaredDoublingADerivedOne($contributor);
        $payloads = $this->capturePublishedPayloads();
        $this->entityManager->expects($this->once())->method('flush');

        $resource = $this->review($admin, $declared, 'rejected');

        self::assertSame('rejected', $resource->status);
        self::assertFalse($resource->duplicatesDerived);
        self::assertSame(ContributionStatus::Rejected, $declared->getStatus());
        self::assertSame($admin, $declared->getReviewedBy());
        self::assertSame(self::ALREADY_FULFILLED_QUANTITY - self::CONTRIBUTED_QUANTITY, $this->bomItem->getFulfilledQuantity());
        self::assertCount(1, $payloads);
        self::assertSame('contribution_rejected', $payloads[0]['action']);
        self::assertSame($declared->getId()->toRfc4122(), $payloads[0]['data']['contributionId']);
    }

    #[Group('issue-510')]
    public function testRejectingTheDerivedContributionIsABadRequestAndNothingChanges(): void
    {
        $admin = $this->createStub(User::class);
        $this->addMember($admin, ParticipantRole::Admin);
        $contributor = $this->addMember($this->createStub(User::class), ParticipantRole::Member);
        $this->approvedDeclaredDoublingADerivedOne($contributor);
        $derived = $this->project->getContributions()->filter(static fn (IndustryProjectContribution $contribution): bool => $contribution->isAutoDetected())->first();
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');

        try {
            $this->review($admin, $derived, 'rejected');
            self::fail('The derived contribution was rejected');
        } catch (BadRequestHttpException $refusal) {
            self::assertSame('Cannot reject a contribution with status "approved"', $refusal->getMessage());
        }

        self::assertSame(ContributionStatus::Approved, $derived->getStatus());
        self::assertSame(self::ALREADY_FULFILLED_QUANTITY, $this->bomItem->getFulfilledQuantity());
    }

    #[Group('issue-510')]
    public function testOwnerCannotRejectHisOwnApprovedDuplicateWhenAnAdminCanReviewIt(): void
    {
        $this->addMember($this->createStub(User::class), ParticipantRole::Admin);
        $declared = $this->approvedDeclaredDoublingADerivedOne($this->ownerMember);
        $this->entityManager->expects($this->never())->method('flush');

        try {
            $this->review($this->owner, $declared, 'rejected');
            self::fail('The owner rejected his own contribution');
        } catch (BadRequestHttpException $refusal) {
            self::assertSame('You cannot review your own contribution', $refusal->getMessage());
        }

        self::assertSame(ContributionStatus::Approved, $declared->getStatus());
        self::assertSame(self::ALREADY_FULFILLED_QUANTITY, $this->bomItem->getFulfilledQuantity());
    }

    #[Group('issue-953')]
    public function testOwnerReopensAnApprovedContributionOfAMemberAndTheLineIsDowngraded(): void
    {
        $contributor = $this->addMember($this->createStub(User::class), ParticipantRole::Member);
        $contribution = $this->pendingContributionOf($contributor)->setStatus(ContributionStatus::Approved);
        $payloads = $this->capturePublishedPayloads();

        $resource = $this->review($this->owner, $contribution, 'pending');

        self::assertSame('pending', $resource->status);
        self::assertSame(ContributionStatus::Pending, $contribution->getStatus());
        self::assertNull($contribution->getReviewedBy());
        self::assertSame(self::ALREADY_FULFILLED_QUANTITY - self::CONTRIBUTED_QUANTITY, $this->bomItem->getFulfilledQuantity());
        self::assertSame('contribution_synced', $payloads[0]['action']);
        self::assertSame($contribution->getId()->toRfc4122(), $payloads[0]['data']['contributionId']);
    }

    #[Group('issue-953')]
    public function testAdminCannotReopenHisOwnApprovedContribution(): void
    {
        $admin = $this->createStub(User::class);
        $adminMember = $this->addMember($admin, ParticipantRole::Admin);
        $contribution = $this->pendingContributionOf($adminMember)->setStatus(ContributionStatus::Approved);
        $this->entityManager->expects($this->never())->method('flush');

        try {
            $this->review($admin, $contribution, 'pending');
            self::fail('A user must not be able to reopen his own contribution');
        } catch (BadRequestHttpException $refusal) {
            self::assertSame('You cannot review your own contribution', $refusal->getMessage());
        }

        self::assertSame(ContributionStatus::Approved, $contribution->getStatus());
        self::assertSame(self::ALREADY_FULFILLED_QUANTITY, $this->bomItem->getFulfilledQuantity());
    }

    #[Group('issue-953')]
    public function testReopeningIsRefusedAsABadRequestOnceTheProjectIsSelling(): void
    {
        $contributor = $this->addMember($this->createStub(User::class), ParticipantRole::Member);
        $contribution = $this->pendingContributionOf($contributor)->setStatus(ContributionStatus::Approved);
        $this->project->setStatus(ProjectStatus::Selling);
        $this->hub->expects($this->never())->method('publish');

        try {
            $this->review($this->owner, $contribution, 'pending');
            self::fail('A contribution cannot be reopened once the project is selling');
        } catch (BadRequestHttpException) {
            self::assertSame(ContributionStatus::Approved, $contribution->getStatus());
            self::assertSame(self::ALREADY_FULFILLED_QUANTITY, $this->bomItem->getFulfilledQuantity());
        }
    }

    #[Group('issue-953')]
    public function testReviewInputAcceptsTheThreeDecisions(): void
    {
        $validator = \Symfony\Component\Validator\Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        foreach (['approved', 'rejected', 'pending'] as $decision) {
            $input = new ReviewContributionInput();
            $input->status = $decision;
            self::assertCount(0, $validator->validate($input), $decision);
        }

        $input = new ReviewContributionInput();
        $input->status = 'reopened';
        self::assertCount(1, $validator->validate($input));
    }

    // --- Helpers --------------------------------------------------------------------------

    private function assertReviewOnCompletedProjectIsRefused(IndustryProjectContribution $contribution, string $decision): void
    {
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');

        $refusal = null;
        try {
            $this->review($this->owner, $contribution, $decision);
        } catch (BadRequestHttpException $e) {
            $refusal = $e;
        }

        self::assertInstanceOf(BadRequestHttpException::class, $refusal);

        self::assertSame(ContributionStatus::Pending, $contribution->getStatus());
        self::assertNull($contribution->getReviewedBy());
        self::assertSame(self::ALREADY_FULFILLED_QUANTITY, $this->bomItem->getFulfilledQuantity());
    }

    private function assertSelfReviewIsRefused(User $reviewer, IndustryProjectContribution $contribution, string $decision): void
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

    #[Group('issue-168')]
    public function testApprovalIsPublishedWithTheContributionAndThePilotOfItsReviewer(): void
    {
        $character = new Character();
        $character->setName('Mira Dane');
        $this->owner->method('getMainCharacter')->willReturn($character);
        $contributor = $this->addMember($this->createStub(User::class), ParticipantRole::Member);
        $contribution = $this->pendingContributionOf($contributor);
        $payloads = $this->capturePublishedPayloads();

        $this->review($this->owner, $contribution, 'approved');

        self::assertCount(1, $payloads);
        self::assertSame('contribution_approved', $payloads[0]['action']);
        self::assertSame($contribution->getId()->toRfc4122(), $payloads[0]['data']['contributionId']);
        self::assertSame('Mira Dane', $payloads[0]['data']['reviewedBy']);
    }

    #[Group('issue-321')]
    public function testApprovalNamesTheContributorPilotTheBomLineAndTheQuantity(): void
    {
        $payloads = $this->capturePublishedPayloads();

        $this->review($this->owner, $this->pendingContributionOfPilot('Vex Tanner'), 'approved');

        self::assertSame('contribution_approved', $payloads[0]['action']);
        self::assertSame(['Vex Tanner', 'Tritanium', self::CONTRIBUTED_QUANTITY], [
            $payloads[0]['data']['characterName'] ?? 'absent',
            $payloads[0]['data']['bomItemName'] ?? 'absent',
            $payloads[0]['data']['quantity'] ?? 'absent',
        ]);
    }

    #[Group('issue-321')]
    public function testRejectionNamesTheContributorPilotTheBomLineAndTheQuantity(): void
    {
        $payloads = $this->capturePublishedPayloads();

        $this->review($this->owner, $this->pendingContributionOfPilot('Vex Tanner'), 'rejected');

        self::assertSame('contribution_rejected', $payloads[0]['action']);
        self::assertSame(['Vex Tanner', 'Tritanium', self::CONTRIBUTED_QUANTITY], [
            $payloads[0]['data']['characterName'] ?? 'absent',
            $payloads[0]['data']['bomItemName'] ?? 'absent',
            $payloads[0]['data']['quantity'] ?? 'absent',
        ]);
    }

    #[Group('issue-321')]
    public function testAContributorWithoutMainCharacterIsPublishedAsUnknownAndNeverByTheNameOfAnAlt(): void
    {
        $contributor = $this->addMember($this->createStub(User::class), ParticipantRole::Member);
        $contribution = $this->pendingContributionOf($contributor);
        $payloads = $this->capturePublishedPayloads();

        $this->review($this->owner, $contribution, 'approved');

        self::assertSame('Unknown', $payloads[0]['data']['characterName'] ?? 'absent');
    }

    #[Group('issue-168')]
    public function testRejectionByAReviewerWithoutMainCharacterIsPublishedWithAnUnknownReviewer(): void
    {
        $contributor = $this->addMember($this->createStub(User::class), ParticipantRole::Member);
        $contribution = $this->pendingContributionOf($contributor);
        $payloads = $this->capturePublishedPayloads();

        $this->review($this->owner, $contribution, 'rejected');

        self::assertCount(1, $payloads);
        self::assertSame('contribution_rejected', $payloads[0]['action']);
        self::assertSame($contribution->getId()->toRfc4122(), $payloads[0]['data']['contributionId']);
        self::assertSame('Unknown', $payloads[0]['data']['reviewedBy']);
    }

    #[Group('issue-168')]
    public function testReviewWithoutContributionIdentifierIsNotFound(): void
    {
        $this->security->method('getUser')->willReturn($this->owner);
        $input = new ReviewContributionInput();
        $input->status = 'approved';

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Contribution not found');

        $this->processor->process($input, new Patch(), ['projectId' => $this->project->getId()->toRfc4122()]);
    }

    #[Group('issue-168')]
    public function testReviewWithoutProjectIdentifierIsNotFound(): void
    {
        $this->security->method('getUser')->willReturn($this->owner);
        $input = new ReviewContributionInput();
        $input->status = 'approved';

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Contribution not found');

        $this->processor->process($input, new Patch(), ['id' => Uuid::v4()->toRfc4122()]);
    }

    private function pendingContributionOfPilot(string $pilotName): IndustryProjectContribution
    {
        $contributorUser = new User();
        $contributorUser->setMainCharacter((new Character())->setName($pilotName));

        return $this->pendingContributionOf($this->addMember($contributorUser, ParticipantRole::Member));
    }

    private function review(User $reviewer, IndustryProjectContribution $contribution, string $decision): ProjectContributionResource
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

    private function addMember(User $user, ParticipantRole $role, ParticipantStatus $status = ParticipantStatus::Accepted): IndustryProjectParticipant
    {
        $member = new IndustryProjectParticipant();
        $member->setUser($user);
        $member->setRole($role);
        $member->setStatus($status);
        $this->assignId($member);
        $this->project->addMember($member);
        $this->members[] = $member;

        return $member;
    }

    private function pendingContributionOf(IndustryProjectParticipant $contributor): IndustryProjectContribution
    {
        $contribution = new IndustryProjectContribution();
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

    private function approvedDeclaredDoublingADerivedOne(IndustryProjectParticipant $contributor): IndustryProjectContribution
    {
        $declared = $this->pendingContributionOf($contributor);
        $declared->setStatus(ContributionStatus::Approved);
        $this->project->addContribution($declared);

        $derived = $this->pendingContributionOf($contributor);
        $derived->setStatus(ContributionStatus::Approved);
        $derived->setIsAutoDetected(true);
        $derived->setIsVerified(true);
        $derived->setQuantity(600);
        $this->project->addContribution($derived);

        return $declared;
    }

    private function capturePublishedPayloads(): \ArrayObject
    {
        $payloads = new \ArrayObject();
        $this->hub->method('publish')->willReturnCallback(
            static function (Update $update) use ($payloads): string {
                $payloads[] = json_decode($update->getData(), true);

                return 'update-id';
            },
        );

        return $payloads;
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
     * @return list<IndustryProjectParticipant>
     */
    private function membersMatching(array $criteria): array
    {
        return array_values(array_filter(
            $this->members,
            static function (IndustryProjectParticipant $member) use ($criteria): bool {
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
