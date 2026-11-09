<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectPurchase;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Service\Industry\Project\DerivedContributionService;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Notification\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[CoversClass(DerivedContributionService::class)]
#[Group('issue-510')]
#[AllowMockObjectsWithoutExpectations]
final class DerivedContributionServiceDeclaredOverDerivedTest extends TestCase
{
    private const int TRITANIUM_TYPE_ID = 34;
    private const int PYERITE_TYPE_ID = 35;
    private const int ISHTAR_TYPE_ID = 12005;
    private const int TRITANIUM_LINE_QUANTITY = 1_000;
    private const int DECLARED_TRITANIUM_QUANTITY = 400;
    private const int PURCHASED_TRITANIUM_QUANTITY = 600;
    private const string PROJECT_NAME = 'Ishtar Wing Refit';
    private const string OWNER_NAME = 'Aria Solberg';
    private const string PARTICIPANT_NAME = 'Sana Vel';
    private const string ADMIN_NAME = 'Kira Doran';
    private const string REPLACED_NOTE = 'Replaced by the ESI/wallet contribution';

    private EntityManagerInterface&MockObject $entityManager;
    private NotificationDispatcher&MockObject $notificationDispatcher;
    private DerivedContributionService $service;
    private User $owner;
    private User $participant;
    private User $admin;
    private IndustryProject $project;
    private IndustryProjectParticipant $ownerParticipant;
    private IndustryProjectParticipant $participantMember;
    private IndustryProjectParticipant $adminMember;
    private IndustryProjectBomLine $tritaniumLine;
    private IndustryProjectBomLine $pyeriteLine;
    private IndustryProjectBomLine $ishtarLine;
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->owner = $this->userNamed(self::OWNER_NAME, 2_510_000_001);
        $this->participant = $this->userNamed(self::PARTICIPANT_NAME, 2_510_000_002);
        $this->admin = $this->userNamed(self::ADMIN_NAME, 2_510_000_003);

        $this->project = (new IndustryProject())->setOwner($this->owner)->setName(self::PROJECT_NAME);
        $this->project->enrolOwner();
        $this->ownerParticipant = $this->project->getMembers()->first();
        $this->participantMember = $this->enrol($this->participant, ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->adminMember = $this->enrol($this->admin, ParticipantRole::Admin, ParticipantStatus::Accepted);

        $this->tritaniumLine = $this->materialLine(self::TRITANIUM_TYPE_ID, 'Tritanium', self::TRITANIUM_LINE_QUANTITY);
        $this->pyeriteLine = $this->materialLine(self::PYERITE_TYPE_ID, 'Pyerite', 500);
        $this->ishtarLine = (new IndustryProjectBomLine())
            ->setTypeId(self::ISHTAR_TYPE_ID)
            ->setTypeName('Ishtar')
            ->setRequiredQuantity(5)
            ->setIsJob(true)
            ->setJobGroup('final')
            ->setActivityType('manufacturing')
            ->setRuns(5);
        $this->project->addBomItem($this->ishtarLine);

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof IndustryProjectContribution) {
                $this->project->addContribution($entity);
            }
        });
        $this->entityManager->method('remove')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof IndustryProjectContribution) {
                $this->project->getContributions()->removeElement($entity);
            }
        });

        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static fn (Update $update): string => 'update-id');

        $this->notificationDispatcher = $this->createMock(NotificationDispatcher::class);
        $this->notificationDispatcher->method('dispatch')->willReturnCallback(
            function (User $user, string $category, string $level, string $title, string $message, ?array $data = null, ?string $route = null): void {
                $this->dispatched[] = compact('user', 'category', 'level', 'title', 'message', 'data', 'route');
            },
        );

        $this->service = new DerivedContributionService(
            $this->entityManager,
            new MercurePublisherService($hub, new NullLogger()),
            $this->notificationDispatcher,
        );
    }

    public function testPendingDeclaredMaterialIsRejectedWhenTheOwnersPurchaseCreatesTheDerivedContribution(): void
    {
        $declared = $this->declared($this->ownerParticipant, $this->tritaniumLine, ContributionType::Material, self::DECLARED_TRITANIUM_QUANTITY, ContributionStatus::Pending);
        $this->linkPurchase($this->tritaniumLine, self::TRITANIUM_TYPE_ID, self::PURCHASED_TRITANIUM_QUANTITY, 6.5);

        $this->service->refreshLine($this->tritaniumLine);

        $derived = $this->singleDerivedOf($this->tritaniumLine);
        $this->assertSame([ContributionType::Material, self::PURCHASED_TRITANIUM_QUANTITY, ContributionStatus::Approved], [$derived->getType(), $derived->getQuantity(), $derived->getStatus()]);
        $this->assertSame(ContributionStatus::Rejected, $declared->getStatus());
        $this->assertSame(self::REPLACED_NOTE, $declared->getNote());
        $this->assertSame($this->owner, $declared->getReviewedBy());
        $this->assertNotNull($declared->getReviewedAt());
        $this->assertSame(self::DECLARED_TRITANIUM_QUANTITY, $declared->getQuantity());
        $this->assertSame(self::PURCHASED_TRITANIUM_QUANTITY, $this->tritaniumLine->getFulfilledQuantity());
    }

    public function testRejectionOfThePendingDeclaredNotifiesTheOwnerAndTheAdminOnceEachWithTheDerivedContributionAsData(): void
    {
        $this->declared($this->ownerParticipant, $this->tritaniumLine, ContributionType::Material, self::DECLARED_TRITANIUM_QUANTITY, ContributionStatus::Pending);
        $this->linkPurchase($this->tritaniumLine, self::TRITANIUM_TYPE_ID, self::PURCHASED_TRITANIUM_QUANTITY, 6.5);

        $this->service->refreshLine($this->tritaniumLine);

        $derived = $this->singleDerivedOf($this->tritaniumLine);
        $projectId = $this->project->getId()->toRfc4122();
        $this->assertSame([$this->owner, $this->admin], array_column($this->dispatched, 'user'));
        foreach ($this->dispatched as $notification) {
            $this->assertSame(Notification::CATEGORY_INDUSTRY, $notification['category']);
            $this->assertSame(Notification::LEVEL_WARNING, $notification['level']);
            $this->assertSame('/industry/projects/'.$projectId, $notification['route']);
            $this->assertSame(
                ['projectId' => $projectId, 'contributionId' => $derived->getId()->toRfc4122(), 'bomItemId' => $this->tritaniumLine->getId()->toRfc4122()],
                $notification['data'],
            );
        }
    }

    public function testNotificationNamesTheProjectTheLineTheParticipantAndTheContributionType(): void
    {
        $this->declared($this->ownerParticipant, $this->tritaniumLine, ContributionType::Material, self::DECLARED_TRITANIUM_QUANTITY, ContributionStatus::Pending);
        $this->linkPurchase($this->tritaniumLine, self::TRITANIUM_TYPE_ID, self::PURCHASED_TRITANIUM_QUANTITY, 6.5);

        $this->service->refreshLine($this->tritaniumLine);

        $this->assertNotSame([], $this->dispatched);
        $text = $this->dispatched[0]['title'].' '.$this->dispatched[0]['message'];
        $this->assertStringContainsString(self::PROJECT_NAME, $text);
        $this->assertStringContainsString('Tritanium', $text);
        $this->assertStringContainsString(self::OWNER_NAME, $text);
        $this->assertStringContainsStringIgnoringCase('material', $text);
    }

    public function testNotificationCallsAProjectWithoutNameUntitledProject(): void
    {
        $this->project->setName(null);
        $this->declared($this->ownerParticipant, $this->tritaniumLine, ContributionType::Material, self::DECLARED_TRITANIUM_QUANTITY, ContributionStatus::Pending);
        $this->linkPurchase($this->tritaniumLine, self::TRITANIUM_TYPE_ID, self::PURCHASED_TRITANIUM_QUANTITY, 6.5);

        $this->service->refreshLine($this->tritaniumLine);

        $this->assertNotSame([], $this->dispatched);
        $this->assertStringContainsString('Untitled project', $this->dispatched[0]['title'].' '.$this->dispatched[0]['message']);
    }

    public function testApprovedDeclaredMaterialStaysAndBothCountWhileTheReviewersAreNotified(): void
    {
        $declared = $this->declared($this->ownerParticipant, $this->tritaniumLine, ContributionType::Material, self::DECLARED_TRITANIUM_QUANTITY, ContributionStatus::Approved);
        $declared->setReviewedBy($this->admin)->setNote('hangar stock');
        $this->tritaniumLine->setFulfilledQuantity(self::DECLARED_TRITANIUM_QUANTITY);
        $this->linkPurchase($this->tritaniumLine, self::TRITANIUM_TYPE_ID, self::PURCHASED_TRITANIUM_QUANTITY, 6.5);

        $this->service->refreshLine($this->tritaniumLine);

        $this->assertSame(
            [ContributionStatus::Approved, self::DECLARED_TRITANIUM_QUANTITY, $this->admin, 'hangar stock', null],
            [$declared->getStatus(), $declared->getQuantity(), $declared->getReviewedBy(), $declared->getNote(), $declared->getReviewedAt()],
        );
        $this->assertSame(self::TRITANIUM_LINE_QUANTITY, $this->tritaniumLine->getFulfilledQuantity());
        $this->assertSame([$this->owner, $this->admin], array_column($this->dispatched, 'user'));
    }

    public function testRejectionNoteKeepsTheNoteTheParticipantTypedWhenDeclaring(): void
    {
        $declared = $this->declared($this->ownerParticipant, $this->tritaniumLine, ContributionType::Material, self::DECLARED_TRITANIUM_QUANTITY, ContributionStatus::Pending);
        $declared->setNote('stock du hangar');
        $this->linkPurchase($this->tritaniumLine, self::TRITANIUM_TYPE_ID, self::PURCHASED_TRITANIUM_QUANTITY, 6.5);

        $this->service->refreshLine($this->tritaniumLine);

        $this->assertSame('Replaced by the ESI/wallet contribution. Declared note: stock du hangar', $declared->getNote());
    }

    public function testPendingDeclaredJobInstallIsRejectedWhenTheParticipantsLinkedJobCreatesTheirDerivedContributionAndTheyAreNotNotified(): void
    {
        $declared = $this->declared($this->participantMember, $this->ishtarLine, ContributionType::JobInstall, 3, ContributionStatus::Pending);
        $this->linkJobStartedBy($this->ishtarLine, 6_510_000_001, 3, 214_000.0, self::PARTICIPANT_NAME);

        $this->service->refreshLine($this->ishtarLine);

        $derived = $this->singleDerivedOf($this->ishtarLine);
        $this->assertSame([$this->participantMember, ContributionType::JobInstall, 3], [$derived->getMember(), $derived->getType(), $derived->getQuantity()]);
        $this->assertSame([ContributionStatus::Rejected, self::REPLACED_NOTE, $this->owner], [$declared->getStatus(), $declared->getNote(), $declared->getReviewedBy()]);
        $this->assertSame([$this->owner, $this->admin], array_column($this->dispatched, 'user'));
        $this->assertSame(0, $this->ishtarLine->getFulfilledQuantity());
    }

    public function testDerivedMaterialOfTheOwnerLeavesAParticipantsPendingJobInstallUntouched(): void
    {
        $declared = $this->declared($this->participantMember, $this->ishtarLine, ContributionType::JobInstall, 3, ContributionStatus::Pending);
        $this->linkPurchase($this->ishtarLine, self::ISHTAR_TYPE_ID, 2, 90_500_000.0);

        $this->service->refreshLine($this->ishtarLine);

        $this->assertSame([ContributionStatus::Pending, null, null], [$declared->getStatus(), $declared->getNote(), $declared->getReviewedAt()]);
        $this->assertSame([], $this->dispatched);
    }

    public function testPendingDeclaredOnAnotherLineOfTheSameParticipantAndTypeIsLeftAlone(): void
    {
        $declared = $this->declared($this->ownerParticipant, $this->pyeriteLine, ContributionType::Material, 200, ContributionStatus::Pending);
        $this->linkPurchase($this->tritaniumLine, self::TRITANIUM_TYPE_ID, self::PURCHASED_TRITANIUM_QUANTITY, 6.5);

        $this->service->refreshLine($this->tritaniumLine);

        $this->assertSame(ContributionStatus::Pending, $declared->getStatus());
        $this->assertSame([], $this->dispatched);
    }

    public function testRejectedDeclaredIsNeverTouchedAndNobodyIsNotified(): void
    {
        $declared = $this->declared($this->ownerParticipant, $this->tritaniumLine, ContributionType::Material, self::DECLARED_TRITANIUM_QUANTITY, ContributionStatus::Rejected);
        $declared->setNote('too vague');
        $this->linkPurchase($this->tritaniumLine, self::TRITANIUM_TYPE_ID, self::PURCHASED_TRITANIUM_QUANTITY, 6.5);

        $this->service->refreshLine($this->tritaniumLine);

        $this->assertSame([ContributionStatus::Rejected, 'too vague', null], [$declared->getStatus(), $declared->getNote(), $declared->getReviewedAt()]);
        $this->assertSame([], $this->dispatched);
    }

    public function testSeveralPendingDeclaredOfTheSameParticipantLineAndTypeAreAllRejected(): void
    {
        $first = $this->declared($this->ownerParticipant, $this->tritaniumLine, ContributionType::Material, 100, ContributionStatus::Pending);
        $second = $this->declared($this->ownerParticipant, $this->tritaniumLine, ContributionType::Material, 150, ContributionStatus::Pending);
        $this->linkPurchase($this->tritaniumLine, self::TRITANIUM_TYPE_ID, self::PURCHASED_TRITANIUM_QUANTITY, 6.5);

        $this->service->refreshLine($this->tritaniumLine);

        $this->assertSame([ContributionStatus::Rejected, ContributionStatus::Rejected], [$first->getStatus(), $second->getStatus()]);
        $this->assertSame(self::PURCHASED_TRITANIUM_QUANTITY, $this->tritaniumLine->getFulfilledQuantity());
    }

    public function testPendingDeclaredOfARemovedParticipantIsRejectedAndTheReviewersNotifiedAreTheAcceptedOnes(): void
    {
        $removedPilot = $this->userNamed('Old Pilot', 2_510_000_004);
        $removedMember = $this->enrol($removedPilot, ParticipantRole::Member, ParticipantStatus::Removed);
        $this->enrol($this->userNamed('Waiting Pilot', 2_510_000_005), ParticipantRole::Admin, ParticipantStatus::Pending);
        $declared = $this->declared($removedMember, $this->ishtarLine, ContributionType::JobInstall, 3, ContributionStatus::Pending);
        $this->linkJobStartedBy($this->ishtarLine, 6_510_000_002, 3, 214_000.0, 'Old Pilot');

        $this->service->refreshLine($this->ishtarLine);

        $this->assertSame(ContributionStatus::Rejected, $declared->getStatus());
        $this->assertSame([$this->owner, $this->admin], array_column($this->dispatched, 'user'));
    }

    public function testTheOwnerAloneIsNotifiedWhenNoOtherParticipantIsAnAcceptedAdmin(): void
    {
        $this->adminMember->setStatus(ParticipantStatus::Removed);
        $this->declared($this->participantMember, $this->tritaniumLine, ContributionType::Material, self::DECLARED_TRITANIUM_QUANTITY, ContributionStatus::Approved);
        $this->declared($this->ownerParticipant, $this->tritaniumLine, ContributionType::Material, self::DECLARED_TRITANIUM_QUANTITY, ContributionStatus::Pending);
        $this->linkPurchase($this->tritaniumLine, self::TRITANIUM_TYPE_ID, self::PURCHASED_TRITANIUM_QUANTITY, 6.5);

        $this->service->refreshLine($this->tritaniumLine);

        $this->assertSame([$this->owner], array_column($this->dispatched, 'user'));
    }

    public function testChangingTheQuantityOfAnExistingDerivedContributionNotifiesNobodyMoreAndTouchesNoDeclared(): void
    {
        $declared = $this->declared($this->ownerParticipant, $this->tritaniumLine, ContributionType::Material, self::DECLARED_TRITANIUM_QUANTITY, ContributionStatus::Approved);
        $this->tritaniumLine->setFulfilledQuantity(self::DECLARED_TRITANIUM_QUANTITY);
        $purchase = $this->linkPurchase($this->tritaniumLine, self::TRITANIUM_TYPE_ID, self::PURCHASED_TRITANIUM_QUANTITY, 6.5);
        $this->service->refreshLine($this->tritaniumLine);
        $notificationsAfterCreation = \count($this->dispatched);

        $purchase->setQuantity(700)->setTotalPrice(4550.0);
        $this->service->refreshLine($this->tritaniumLine);

        $this->assertSame(2, $notificationsAfterCreation);
        $this->assertCount($notificationsAfterCreation, $this->dispatched);
        $this->assertSame(700, $this->singleDerivedOf($this->tritaniumLine)->getQuantity());
        $this->assertSame([ContributionStatus::Approved, self::DECLARED_TRITANIUM_QUANTITY], [$declared->getStatus(), $declared->getQuantity()]);
    }

    public function testDerivedContributionDeletedThenCreatedAgainNotifiesAgainWhileTheDeclaredStillExists(): void
    {
        $this->declared($this->ownerParticipant, $this->tritaniumLine, ContributionType::Material, self::DECLARED_TRITANIUM_QUANTITY, ContributionStatus::Approved);
        $purchase = $this->linkPurchase($this->tritaniumLine, self::TRITANIUM_TYPE_ID, self::PURCHASED_TRITANIUM_QUANTITY, 6.5);
        $this->service->refreshLine($this->tritaniumLine);
        $this->tritaniumLine->getPurchases()->removeElement($purchase);
        $this->service->refreshLine($this->tritaniumLine);
        $this->assertCount(2, $this->dispatched);

        $this->linkPurchase($this->tritaniumLine, self::TRITANIUM_TYPE_ID, self::PURCHASED_TRITANIUM_QUANTITY, 6.5);
        $this->service->refreshLine($this->tritaniumLine);

        $this->assertCount(4, $this->dispatched);
    }

    public function testCompletedProjectChangesNothingAndNotifiesNobody(): void
    {
        $declared = $this->declared($this->ownerParticipant, $this->tritaniumLine, ContributionType::Material, self::DECLARED_TRITANIUM_QUANTITY, ContributionStatus::Pending);
        $this->linkPurchase($this->tritaniumLine, self::TRITANIUM_TYPE_ID, self::PURCHASED_TRITANIUM_QUANTITY, 6.5);
        $this->project->setStatus(ProjectStatus::Completed);
        $this->notificationDispatcher->expects($this->never())->method('dispatch');

        $this->service->refreshLine($this->tritaniumLine);

        $this->assertSame([ContributionStatus::Pending, null], [$declared->getStatus(), $declared->getNote()]);
        $this->assertSame([$declared], $this->project->getContributions()->toArray());
        $this->assertSame(0, $this->tritaniumLine->getFulfilledQuantity());
        $this->assertSame([], $this->dispatched);
    }

    private function userNamed(string $name, int $eveCharacterId): User
    {
        $user = new User();
        $character = (new Character())->setEveCharacterId($eveCharacterId)->setName($name);
        $user->addCharacter($character);
        $user->setMainCharacter($character);

        return $user;
    }

    private function enrol(User $user, ParticipantRole $role, ParticipantStatus $status): IndustryProjectParticipant
    {
        $member = (new IndustryProjectParticipant())->setUser($user)->setRole($role)->setStatus($status);
        $this->project->addMember($member);

        return $member;
    }

    private function materialLine(int $typeId, string $typeName, int $requiredQuantity): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity($requiredQuantity)
            ->setEstimatedPrice(5.5)
            ->setIsJob(false);
        $this->project->addBomItem($line);

        return $line;
    }

    private function declared(
        IndustryProjectParticipant $member,
        IndustryProjectBomLine $line,
        ContributionType $type,
        int $quantity,
        ContributionStatus $status,
    ): IndustryProjectContribution {
        $contribution = (new IndustryProjectContribution())
            ->setProject($this->project)
            ->setMember($member)
            ->setBomItem($line)
            ->setType($type)
            ->setQuantity($quantity)
            ->setEstimatedValue(900.0)
            ->setStatus($status)
            ->setIsAutoDetected(false)
            ->setIsVerified(false);
        $this->project->addContribution($contribution);

        return $contribution;
    }

    private function linkPurchase(IndustryProjectBomLine $line, int $typeId, int $quantity, float $unitPrice): IndustryProjectPurchase
    {
        $purchase = (new IndustryProjectPurchase())
            ->setTypeId($typeId)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setTotalPrice($unitPrice * $quantity)
            ->setSource('esi_wallet');
        $line->addPurchase($purchase);

        return $purchase;
    }

    private function linkJobStartedBy(IndustryProjectBomLine $line, int $esiJobId, int $runs, float $cost, string $characterName): IndustryProjectJobMatch
    {
        $jobMatch = (new IndustryProjectJobMatch())
            ->setEsiJobId($esiJobId)
            ->setRuns($runs)
            ->setCost($cost)
            ->setCharacterName($characterName);
        $line->addJobMatch($jobMatch);

        return $jobMatch;
    }

    private function singleDerivedOf(IndustryProjectBomLine $line): IndustryProjectContribution
    {
        $derived = array_values(array_filter(
            $this->project->getContributions()->toArray(),
            static fn (IndustryProjectContribution $contribution): bool => $contribution->isAutoDetected() && $contribution->getBomItem() === $line,
        ));
        $this->assertCount(1, $derived);

        return $derived[0];
    }
}
