<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\GroupIndustry;

use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Repository\GroupIndustryContributionRepository;
use App\Service\GroupIndustry\GroupIndustryContributionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(GroupIndustryContributionService::class)]
#[AllowMockObjectsWithoutExpectations]
class GroupIndustryContributionServiceTest extends TestCase
{
    private const int TRITANIUM_TYPE_ID = 34;
    private const int MEXALLON_TYPE_ID = 36;
    private const int ISOGEN_TYPE_ID = 37;
    private const int MORPHITE_TYPE_ID = 11399;
    private const float TRITANIUM_BOM_PRICE = 5.5;
    private const float MEXALLON_BOM_PRICE = 47.25;
    private const float ISOGEN_BOM_PRICE = 0.0;

    private GroupIndustryContributionRepository&MockObject $contributionRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private GroupIndustryContributionService $service;

    private GroupIndustryProject $project;
    private GroupIndustryProjectMember $member;
    private GroupIndustryBomItem $bomItem;
    private User $reviewer;

    protected function setUp(): void
    {
        $this->contributionRepository = $this->createMock(GroupIndustryContributionRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->service = new GroupIndustryContributionService(
            $this->contributionRepository,
            $this->entityManager,
        );

        $this->project = new GroupIndustryProject();
        $this->project->setOwner($this->createMock(User::class));

        $this->member = $this->memberOfTheProject();

        $this->bomItem = new GroupIndustryBomItem();
        $this->bomItem->setProject($this->project);
        $this->bomItem->setTypeId(self::TRITANIUM_TYPE_ID);
        $this->bomItem->setTypeName('Tritanium');
        $this->bomItem->setRequiredQuantity(10000);

        $this->reviewer = $this->createMock(User::class);
    }

    #[Group('issue-154')]
    public function testSubmitMaterialIsValuedAtTheBomPriceOfItsLineTimesQuantity(): void
    {
        $this->givenNoExistingContribution();
        $tritanium = $this->materialLine(self::TRITANIUM_TYPE_ID, 'Tritanium', self::TRITANIUM_BOM_PRICE);
        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $contribution = $this->service->submit($this->member, $tritanium, ContributionType::Material, 1000);

        $this->assertSame(ContributionStatus::Pending, $contribution->getStatus());
        $this->assertSame(ContributionType::Material, $contribution->getType());
        $this->assertSame(1000, $contribution->getQuantity());
        $this->assertSame(5500.0, $contribution->getEstimatedValue());
        $this->assertFalse($contribution->isAutoDetected());
        $this->assertFalse($contribution->isVerified());
        $this->assertSame($this->project, $contribution->getProject());
        $this->assertSame($this->member, $contribution->getMember());
        $this->assertSame($tritanium, $contribution->getBomItem());
    }

    #[Group('issue-154')]
    public function testTwoMembersBringingTheSameMaterialAtTwoDatesAreValuedAtTheSameBomPrice(): void
    {
        $this->givenNoExistingContribution();
        $tritanium = $this->materialLine(self::TRITANIUM_TYPE_ID, 'Tritanium', self::TRITANIUM_BOM_PRICE);

        $firstContribution = $this->service->submit($this->member, $tritanium, ContributionType::Material, 1000);
        $secondContribution = $this->service->submit($this->memberOfTheProject(), $tritanium, ContributionType::Material, 1000);

        $this->assertSame(5500.0, $firstContribution->getEstimatedValue());
        $this->assertSame(5500.0, $secondContribution->getEstimatedValue());
    }

    #[Group('issue-154')]
    public function testSubmitMaterialAtANonIntegerTotalKeepsTheBomPriceTimesQuantityUnrounded(): void
    {
        $this->givenNoExistingContribution();
        $mexallon = $this->materialLine(self::MEXALLON_TYPE_ID, 'Mexallon', self::MEXALLON_BOM_PRICE);

        $contribution = $this->service->submit($this->member, $mexallon, ContributionType::Material, 33);

        $this->assertSame(1559.25, $contribution->getEstimatedValue());
    }

    #[Group('issue-154')]
    public function testSubmitMaterialWithAnExplicitNullValueOnALineWithABomPriceIsValuedAtItsBomPrice(): void
    {
        $this->givenNoExistingContribution();
        $tritanium = $this->materialLine(self::TRITANIUM_TYPE_ID, 'Tritanium', self::TRITANIUM_BOM_PRICE);
        $this->entityManager->expects($this->once())->method('persist');

        $contribution = $this->service->submit($this->member, $tritanium, ContributionType::Material, 200, estimatedValue: null);

        $this->assertSame(1100.0, $contribution->getEstimatedValue());
    }

    #[Group('issue-154')]
    public function testSubmitMaterialAtABomPriceOfZeroIsAKnownPriceValuedAtZero(): void
    {
        $this->givenNoExistingContribution();
        $isogen = $this->materialLine(self::ISOGEN_TYPE_ID, 'Isogen', self::ISOGEN_BOM_PRICE);
        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $contribution = $this->service->submit($this->member, $isogen, ContributionType::Material, 300);

        $this->assertSame(300, $contribution->getQuantity());
        $this->assertSame(0.0, $contribution->getEstimatedValue());
    }

    public static function valueSentForAMaterialWithABomPriceProvider(): iterable
    {
        yield 'E3 a value of its own' => [9999.99];
        yield 'E3b the value calculated from the BOM price' => [5500.0];
        yield 'a value of zero' => [0.0];
    }

    #[Group('issue-154')]
    #[DataProvider('valueSentForAMaterialWithABomPriceProvider')]
    public function testSubmitMaterialWithAValueOnALineWithABomPriceIsRefusedAndNothingIsPersisted(float $valueSent): void
    {
        $tritanium = $this->materialLine(self::TRITANIUM_TYPE_ID, 'Tritanium', self::TRITANIUM_BOM_PRICE);

        $refusal = $this->refusalOf($tritanium, ContributionType::Material, 1000, $valueSent);

        $this->assertSame('Type 34 has a BOM price: a material contribution is valued from it.', $refusal->getMessage());
    }

    #[Group('issue-154')]
    public function testSubmitMaterialWithAZeroValueOnALineWithABomPriceOfZeroIsRefusedAndNothingIsPersisted(): void
    {
        $isogen = $this->materialLine(self::ISOGEN_TYPE_ID, 'Isogen', self::ISOGEN_BOM_PRICE);

        $refusal = $this->refusalOf($isogen, ContributionType::Material, 300, 0.0);

        $this->assertSame('Type 37 has a BOM price: a material contribution is valued from it.', $refusal->getMessage());
    }

    public static function positiveValueEnteredWithoutBomPriceProvider(): iterable
    {
        yield 'E5 nine hundred ISK' => [900.0];
        yield 'one hundredth of an ISK' => [0.01];
    }

    #[Group('issue-154')]
    #[DataProvider('positiveValueEnteredWithoutBomPriceProvider')]
    public function testSubmitMaterialWithoutBomPriceIsRecordedAtTheEnteredValue(float $enteredValue): void
    {
        $this->givenNoExistingContribution();
        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $contribution = $this->service->submit($this->member, $this->morphiteBomItem(), ContributionType::Material, 100, estimatedValue: $enteredValue);

        $this->assertSame(100, $contribution->getQuantity());
        $this->assertSame($enteredValue, $contribution->getEstimatedValue());
    }

    #[Group('issue-154')]
    public function testSubmitMaterialWithoutBomPriceKeepsTheEnteredValueAndTheNote(): void
    {
        $this->givenNoExistingContribution();

        $contribution = $this->service->submit(
            $this->member,
            $this->bomItem,
            ContributionType::Material,
            1000,
            estimatedValue: 9999.99,
            note: 'Manual override',
        );

        $this->assertSame(9999.99, $contribution->getEstimatedValue());
        $this->assertSame('Manual override', $contribution->getNote());
    }

    public static function missingOrNotPositiveValueWithoutBomPriceProvider(): iterable
    {
        yield 'E6 no value' => [null];
        yield 'E7 a value of zero' => [0.0];
        yield 'a negative value' => [-50.0];
    }

    #[Group('issue-154')]
    #[DataProvider('missingOrNotPositiveValueWithoutBomPriceProvider')]
    public function testSubmitMaterialWithoutBomPriceNorPositiveEnteredValueIsRefusedAndNothingIsPersisted(?float $enteredValue): void
    {
        $refusal = $this->refusalOf($this->morphiteBomItem(), ContributionType::Material, 100, $enteredValue);

        $this->assertSame('No market price for type 11399: enter an estimated value.', $refusal->getMessage());
    }

    public static function contributionOtherThanMaterialProvider(): iterable
    {
        yield 'job install' => [ContributionType::JobInstall, 2, 1834250.5];
        yield 'BPC' => [ContributionType::Bpc, 1, 36000.0];
        yield 'free line rental' => [ContributionType::LineRental, 3, 0.0];
    }

    #[Group('issue-154')]
    #[DataProvider('contributionOtherThanMaterialProvider')]
    public function testContributionOtherThanMaterialOnALineWithABomPriceKeepsItsEnteredValue(ContributionType $type, int $quantity, float $enteredValue): void
    {
        $this->givenNoExistingContribution();
        $tritanium = $this->materialLine(self::TRITANIUM_TYPE_ID, 'Tritanium', self::TRITANIUM_BOM_PRICE);
        $this->entityManager->expects($this->once())->method('persist');

        $contribution = $this->service->submit($this->member, $tritanium, $type, $quantity, $enteredValue);

        $this->assertSame($type, $contribution->getType());
        $this->assertSame($enteredValue, $contribution->getEstimatedValue());
    }

    public function testSubmitBpcIsRecordedAtItsEnteredValue(): void
    {
        $this->givenNoExistingContribution();
        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $contribution = $this->service->submit(
            $this->member,
            $this->bomItem,
            ContributionType::Bpc,
            1,
            estimatedValue: 50000.0,
        );

        $this->assertSame(ContributionType::Bpc, $contribution->getType());
        $this->assertSame(50000.0, $contribution->getEstimatedValue());
    }

    public function testSubmitAntiDuplicateBlocksPending(): void
    {
        $existingContribution = new GroupIndustryContribution();
        $existingContribution->setStatus(ContributionStatus::Pending);

        $this->contributionRepository
            ->method('findByMemberBomItemAndType')
            ->willReturn($existingContribution);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('A contribution of this type is already pending or approved for this item');

        $this->service->submit(
            $this->member,
            $this->bomItem,
            ContributionType::Material,
            1000,
        );
    }

    public function testSubmitAntiDuplicateBlocksApproved(): void
    {
        $existingContribution = new GroupIndustryContribution();
        $existingContribution->setStatus(ContributionStatus::Approved);

        $this->contributionRepository
            ->method('findByMemberBomItemAndType')
            ->willReturn($existingContribution);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('A contribution of this type is already pending or approved for this item');

        $this->service->submit(
            $this->member,
            $this->bomItem,
            ContributionType::Material,
            1000,
        );
    }

    public function testSubmitMaterialAfterARejectionIsRecordedAtItsBomPrice(): void
    {
        $this->givenNoExistingContribution();
        $tritanium = $this->materialLine(self::TRITANIUM_TYPE_ID, 'Tritanium', self::TRITANIUM_BOM_PRICE);
        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $contribution = $this->service->submit($this->member, $tritanium, ContributionType::Material, 500);

        $this->assertSame(ContributionStatus::Pending, $contribution->getStatus());
        $this->assertSame(2750.0, $contribution->getEstimatedValue());
    }

    public function testApproveChangeStatusAndIncrementsFulfillment(): void
    {
        $contribution = new GroupIndustryContribution();
        $contribution->setProject($this->project);
        $contribution->setMember($this->member);
        $contribution->setBomItem($this->bomItem);
        $contribution->setType(ContributionType::Material);
        $contribution->setQuantity(500);
        $contribution->setEstimatedValue(2750.0);
        $contribution->setStatus(ContributionStatus::Pending);

        $this->assertSame(0, $this->bomItem->getFulfilledQuantity());

        $this->entityManager->expects($this->once())->method('flush');

        $this->service->approve($contribution, $this->reviewer);

        $this->assertSame(ContributionStatus::Approved, $contribution->getStatus());
        $this->assertSame($this->reviewer, $contribution->getReviewedBy());
        $this->assertNotNull($contribution->getReviewedAt());
        $this->assertSame(500, $this->bomItem->getFulfilledQuantity());
    }

    public function testApproveAlreadyApprovedThrows(): void
    {
        $contribution = new GroupIndustryContribution();
        $contribution->setStatus(ContributionStatus::Approved);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot approve a contribution with status "approved"');

        $this->service->approve($contribution, $this->reviewer);
    }

    public function testRejectChangesStatusWithoutFulfillment(): void
    {
        $contribution = new GroupIndustryContribution();
        $contribution->setProject($this->project);
        $contribution->setMember($this->member);
        $contribution->setBomItem($this->bomItem);
        $contribution->setType(ContributionType::Material);
        $contribution->setQuantity(500);
        $contribution->setEstimatedValue(2750.0);
        $contribution->setStatus(ContributionStatus::Pending);

        $this->assertSame(0, $this->bomItem->getFulfilledQuantity());

        $this->entityManager->expects($this->once())->method('flush');

        $this->service->reject($contribution, $this->reviewer);

        $this->assertSame(ContributionStatus::Rejected, $contribution->getStatus());
        $this->assertSame($this->reviewer, $contribution->getReviewedBy());
        $this->assertNotNull($contribution->getReviewedAt());
        $this->assertSame(0, $this->bomItem->getFulfilledQuantity());
    }

    public function testRejectAlreadyRejectedThrows(): void
    {
        $contribution = new GroupIndustryContribution();
        $contribution->setStatus(ContributionStatus::Rejected);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot reject a contribution with status "rejected"');

        $this->service->reject($contribution, $this->reviewer);
    }

    public function testSubmitAutoDetectedIsApprovedAndIncrementsFulfillment(): void
    {
        $this->contributionRepository
            ->method('findByMemberBomItemAndType')
            ->willReturn(null);

        $this->assertSame(0, $this->bomItem->getFulfilledQuantity());

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $contribution = $this->service->submitAutoDetected(
            $this->member,
            $this->bomItem,
            ContributionType::JobInstall,
            1,
            125000.0,
        );

        $this->assertSame(ContributionStatus::Approved, $contribution->getStatus());
        $this->assertTrue($contribution->isAutoDetected());
        $this->assertTrue($contribution->isVerified());
        $this->assertSame(ContributionType::JobInstall, $contribution->getType());
        $this->assertSame(125000.0, $contribution->getEstimatedValue());
        $this->assertSame(1, $this->bomItem->getFulfilledQuantity());
    }

    public function testSubmitAutoDetectedRecordsTheDetectedQuantityForTheMemberOnTheBomItemOfTheProject(): void
    {
        $this->givenNoExistingContribution();
        $morphite = $this->morphiteBomItem();

        $contribution = $this->service->submitAutoDetected($this->member, $morphite, ContributionType::Material, 120, 1092000.0);

        $this->assertSame($this->project, $contribution->getProject());
        $this->assertSame($this->member, $contribution->getMember());
        $this->assertSame($morphite, $contribution->getBomItem());
        $this->assertSame(120, $contribution->getQuantity());
        $this->assertSame(1092000.0, $contribution->getEstimatedValue());
        $this->assertSame(120, $morphite->getFulfilledQuantity());
    }

    public function testSubmitAutoDetectedIsRefusedWhenTheMemberAlreadyHasAPendingContributionOfThatTypeOnTheBomItem(): void
    {
        $this->givenExistingContributionWithStatus(ContributionStatus::Pending);
        $this->entityManager->expects($this->never())->method('persist');

        try {
            $this->service->submitAutoDetected($this->member, $this->bomItem, ContributionType::JobInstall, 3, 482500.0);
            $this->fail('A duplicate auto-detected contribution was accepted');
        } catch (\DomainException $duplicate) {
            $this->assertSame('A contribution of this type is already pending or approved for this item', $duplicate->getMessage());
        }
        $this->assertSame(0, $this->bomItem->getFulfilledQuantity());
    }

    public function testSubmitIsRefusedWhenTheMemberAlreadyHasAPendingContributionOfThatTypeOnTheBomItem(): void
    {
        $this->givenExistingContributionWithStatus(ContributionStatus::Pending);
        $this->entityManager->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('A contribution of this type is already pending or approved for this item');

        $this->service->submit($this->member, $this->bomItem, ContributionType::Bpc, 1, 36000.0);
    }

    public function testSubmitIsAcceptedWhenTheOnlyContributionOfThatTypeOnTheBomItemWasRejected(): void
    {
        $this->givenExistingContributionWithStatus(ContributionStatus::Rejected);
        $this->entityManager->expects($this->once())->method('persist');

        $contribution = $this->service->submit($this->member, $this->bomItem, ContributionType::Bpc, 1, 36000.0);

        $this->assertSame(ContributionStatus::Pending, $contribution->getStatus());
    }

    public function testSubmitJobInstallWithoutEnteredValueIsRefusedAndNothingIsPersisted(): void
    {
        $this->assertSubmissionIsRefused(ContributionType::JobInstall, 1, null);
    }

    public function testSubmitJobInstallWithAnEnteredZeroValueIsRefusedAndNothingIsPersisted(): void
    {
        $this->assertSubmissionIsRefused(ContributionType::JobInstall, 1, 0.0);
    }

    public function testSubmitJobInstallWithAnEnteredValueIsRecordedAtThatValueAndPending(): void
    {
        $contribution = $this->submitAndExpectOnePersist(ContributionType::JobInstall, 2, 1834250.5);

        $this->assertSame(ContributionType::JobInstall, $contribution->getType());
        $this->assertSame(2, $contribution->getQuantity());
        $this->assertSame(1834250.5, $contribution->getEstimatedValue());
        $this->assertSame(ContributionStatus::Pending, $contribution->getStatus());
    }

    public function testSubmitBpcWithoutEnteredValueIsRefusedAndNothingIsPersisted(): void
    {
        $this->assertSubmissionIsRefused(ContributionType::Bpc, 1, null);
    }

    public function testSubmitLineRentalWithoutEnteredValueIsRefusedAndNothingIsPersisted(): void
    {
        $this->assertSubmissionIsRefused(ContributionType::LineRental, 1, null);
    }

    public function testSubmitLineRentalWithAnEnteredZeroValueIsRecordedAsAFreeRental(): void
    {
        $contribution = $this->submitAndExpectOnePersist(ContributionType::LineRental, 3, 0.0);

        $this->assertSame(ContributionType::LineRental, $contribution->getType());
        $this->assertSame(3, $contribution->getQuantity());
        $this->assertSame(0.0, $contribution->getEstimatedValue());
        $this->assertSame(ContributionStatus::Pending, $contribution->getStatus());
    }

    public function testSubmitLineRentalWithAnEnteredValueIsRecordedAtThatValue(): void
    {
        $contribution = $this->submitAndExpectOnePersist(ContributionType::LineRental, 4, 75000.0);

        $this->assertSame(4, $contribution->getQuantity());
        $this->assertSame(75000.0, $contribution->getEstimatedValue());
        $this->assertSame(ContributionStatus::Pending, $contribution->getStatus());
    }

    private function refusalOf(GroupIndustryBomItem $bomItem, ContributionType $type, int $quantity, ?float $estimatedValue): \DomainException
    {
        $this->givenNoExistingContribution();
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        try {
            $this->service->submit($this->member, $bomItem, $type, $quantity, $estimatedValue);
        } catch (\DomainException $refusal) {
            return $refusal;
        }

        $this->fail('The contribution was recorded');
    }

    private function assertSubmissionIsRefused(ContributionType $type, int $quantity, ?float $estimatedValue): void
    {
        $this->givenNoExistingContribution();
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(\DomainException::class);

        $this->service->submit($this->member, $this->bomItem, $type, $quantity, $estimatedValue);
    }

    private function submitAndExpectOnePersist(ContributionType $type, int $quantity, float $estimatedValue): GroupIndustryContribution
    {
        $this->givenNoExistingContribution();
        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        return $this->service->submit($this->member, $this->bomItem, $type, $quantity, $estimatedValue);
    }

    private function givenNoExistingContribution(): void
    {
        $this->contributionRepository
            ->method('findByMemberBomItemAndType')
            ->willReturn(null);
    }

    private function givenExistingContributionWithStatus(ContributionStatus $status): void
    {
        $existing = new GroupIndustryContribution();
        $existing->setStatus($status);
        $this->contributionRepository
            ->method('findByMemberBomItemAndType')
            ->willReturnCallback(static fn (GroupIndustryProjectMember $member, GroupIndustryBomItem $bomItem, ContributionType $type, array $statuses): ?GroupIndustryContribution => in_array($status, $statuses, true) ? $existing : null);
    }

    private function memberOfTheProject(): GroupIndustryProjectMember
    {
        $member = new GroupIndustryProjectMember();
        $member->setProject($this->project);
        $member->setUser($this->createMock(User::class));

        return $member;
    }

    private function materialLine(int $typeId, string $typeName, float $bomPrice): GroupIndustryBomItem
    {
        $line = new GroupIndustryBomItem();
        $line->setProject($this->project);
        $line->setTypeId($typeId);
        $line->setTypeName($typeName);
        $line->setRequiredQuantity(10000);
        $line->setEstimatedPrice($bomPrice);

        return $line;
    }

    private function morphiteBomItem(): GroupIndustryBomItem
    {
        $morphite = new GroupIndustryBomItem();
        $morphite->setProject($this->project);
        $morphite->setTypeId(self::MORPHITE_TYPE_ID);
        $morphite->setTypeName('Morphite');
        $morphite->setRequiredQuantity(500);

        return $morphite;
    }
}
