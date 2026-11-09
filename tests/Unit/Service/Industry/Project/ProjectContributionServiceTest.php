<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Service\Industry\Project\LineReservationService;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Repository\IndustryProjectContributionRepository;
use App\Service\Industry\Project\ProjectContributionService;
use App\Service\JitaMarketService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectContributionService::class)]
#[AllowMockObjectsWithoutExpectations]
class ProjectContributionServiceTest extends TestCase
{
    private const int TRITANIUM_TYPE_ID = 34;
    private const int MEXALLON_TYPE_ID = 36;
    private const int MORPHITE_TYPE_ID = 11399;
    private const float TRITANIUM_BOM_PRICE = 5.5;
    private const float TRITANIUM_JITA_PRICE = 7.0;
    private const float TRITANIUM_JITA_PRICE_LATER = 8.0;
    private const float MEXALLON_BOM_PRICE = 47.25;
    private const float MEXALLON_JITA_PRICE = 48.75;
    private const float MORPHITE_JITA_PRICE = 531.25;

    private IndustryProjectContributionRepository&MockObject $contributionRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private JitaMarketService&MockObject $jitaMarket;
    private ProjectContributionService $service;

    private array $jitaPrices = [];
    private array $jitaLookups = [];

    private IndustryProject $project;
    private IndustryProjectParticipant $member;
    private IndustryProjectBomLine $bomItem;
    private User $reviewer;

    protected function setUp(): void
    {
        $this->contributionRepository = $this->createMock(IndustryProjectContributionRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->jitaMarket = $this->createMock(JitaMarketService::class);
        $this->jitaMarket->method('getCheapestPercentilePrices')->willReturnCallback(
            function (array $typeIds, float $percentile = 0.05): array {
                $this->jitaLookups[] = [$typeIds, $percentile];

                return array_combine($typeIds, array_map(fn (int $typeId): ?float => $this->jitaPrices[$typeId] ?? null, $typeIds));
            },
        );

        $this->service = new ProjectContributionService(
            $this->contributionRepository,
            $this->entityManager,
            $this->jitaMarket,
            $this->createStub(LineReservationService::class),
        );

        $this->project = new IndustryProject();
        $this->project->setOwner($this->createMock(User::class));

        $this->member = $this->memberOfTheProject();

        $this->bomItem = new IndustryProjectBomLine();
        $this->bomItem->setProject($this->project);
        $this->bomItem->setTypeId(self::TRITANIUM_TYPE_ID);
        $this->bomItem->setTypeName('Tritanium');
        $this->bomItem->setRequiredQuantity(10000);

        $this->reviewer = $this->createMock(User::class);
    }

    #[Group('issue-363')]
    public function testSubmitMaterialIsValuedAtTheJitaPriceOfTheDepositDayTimesQuantityNotAtTheBomPrice(): void
    {
        $this->givenNoExistingContribution();
        $this->jitaPrices = [self::TRITANIUM_TYPE_ID => self::TRITANIUM_JITA_PRICE];
        $tritanium = $this->materialLine(self::TRITANIUM_TYPE_ID, 'Tritanium', self::TRITANIUM_BOM_PRICE);
        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $contribution = $this->service->submit($this->member, $tritanium, ContributionType::Material, 1000);

        $this->assertSame(ContributionStatus::Pending, $contribution->getStatus());
        $this->assertSame(ContributionType::Material, $contribution->getType());
        $this->assertSame(1000, $contribution->getQuantity());
        $this->assertSame(7000.0, $contribution->getEstimatedValue());
        $this->assertFalse($contribution->isAutoDetected());
        $this->assertFalse($contribution->isVerified());
        $this->assertSame($this->project, $contribution->getProject());
        $this->assertSame($this->member, $contribution->getMember());
        $this->assertSame($tritanium, $contribution->getBomItem());
        $this->assertSame([[[self::TRITANIUM_TYPE_ID], 0.05]], $this->jitaLookups);
    }

    #[Group('issue-363')]
    public function testTwoDepositsOfTheSameMaterialOnTwoDaysKeepTheirOwnJitaPrice(): void
    {
        $this->givenNoExistingContribution();
        $tritanium = $this->materialLine(self::TRITANIUM_TYPE_ID, 'Tritanium', self::TRITANIUM_BOM_PRICE);

        $this->jitaPrices = [self::TRITANIUM_TYPE_ID => self::TRITANIUM_JITA_PRICE];
        $firstContribution = $this->service->submit($this->member, $tritanium, ContributionType::Material, 1000);
        $this->jitaPrices = [self::TRITANIUM_TYPE_ID => self::TRITANIUM_JITA_PRICE_LATER];
        $secondContribution = $this->service->submit($this->memberOfTheProject(), $tritanium, ContributionType::Material, 1000);

        $this->assertSame(7000.0, $firstContribution->getEstimatedValue());
        $this->assertSame(8000.0, $secondContribution->getEstimatedValue());
    }

    #[Group('issue-363')]
    public function testSubmitMaterialAtANonIntegerTotalKeepsTheJitaPriceTimesQuantityUnrounded(): void
    {
        $this->givenNoExistingContribution();
        $this->jitaPrices = [self::MEXALLON_TYPE_ID => self::MEXALLON_JITA_PRICE];
        $mexallon = $this->materialLine(self::MEXALLON_TYPE_ID, 'Mexallon', self::MEXALLON_BOM_PRICE);

        $contribution = $this->service->submit($this->member, $mexallon, ContributionType::Material, 33);

        $this->assertSame(1608.75, $contribution->getEstimatedValue());
    }

    #[Group('issue-363')]
    public function testMaterialValueStaysFrozenWhenTheJitaPriceChangesBeforeItIsApproved(): void
    {
        $this->givenNoExistingContribution();
        $this->jitaPrices = [self::TRITANIUM_TYPE_ID => self::TRITANIUM_JITA_PRICE];
        $tritanium = $this->materialLine(self::TRITANIUM_TYPE_ID, 'Tritanium', self::TRITANIUM_BOM_PRICE);
        $contribution = $this->service->submit($this->member, $tritanium, ContributionType::Material, 1000);

        $this->jitaPrices = [self::TRITANIUM_TYPE_ID => self::TRITANIUM_JITA_PRICE_LATER];
        $this->service->approve($contribution, $this->reviewer);

        $this->assertSame(ContributionStatus::Approved, $contribution->getStatus());
        $this->assertSame(7000.0, $contribution->getEstimatedValue());
        $this->assertSame(1000, $tritanium->getFulfilledQuantity());
        $this->assertCount(1, $this->jitaLookups);
    }

    #[Group('issue-363')]
    public function testSubmitMaterialKeepsTheNoteAndIgnoresTheBomPriceOfAMoreExpensiveLine(): void
    {
        $this->givenNoExistingContribution();
        $this->jitaPrices = [self::MORPHITE_TYPE_ID => self::MORPHITE_JITA_PRICE];

        $contribution = $this->service->submit(
            $this->member,
            $this->morphiteBomItem(),
            ContributionType::Material,
            40,
            note: 'Bought last night',
        );

        $this->assertSame(21_250.0, $contribution->getEstimatedValue());
        $this->assertSame('Bought last night', $contribution->getNote());
    }

    public static function valueSentForAMaterialProvider(): iterable
    {
        yield 'E2 the value of the Jita price' => [7000.0];
        yield 'E2 a value of its own' => [9999.99];
        yield 'E2 a value of zero' => [0.0];
    }

    #[Group('issue-363')]
    #[DataProvider('valueSentForAMaterialProvider')]
    public function testSubmitMaterialWithAnEnteredValueIsRefusedAndNothingIsPersisted(float $valueSent): void
    {
        $this->jitaPrices = [self::TRITANIUM_TYPE_ID => self::TRITANIUM_JITA_PRICE];
        $tritanium = $this->materialLine(self::TRITANIUM_TYPE_ID, 'Tritanium', self::TRITANIUM_BOM_PRICE);

        $refusal = $this->refusalOf($tritanium, ContributionType::Material, 1000, $valueSent);

        $this->assertSame('A material contribution takes no entered value: it is valued at the Jita price of the deposit day.', $refusal->getMessage());
    }

    public static function missingJitaPriceProvider(): iterable
    {
        yield 'E3 no order for the type' => [null];
        yield 'a Jita price of zero' => [0.0];
        yield 'a negative Jita price' => [-2.5];
    }

    #[Group('issue-363')]
    #[DataProvider('missingJitaPriceProvider')]
    public function testSubmitMaterialWithoutAJitaPriceIsRefusedAndNothingIsPersisted(?float $jitaPrice): void
    {
        $this->jitaPrices = [self::MORPHITE_TYPE_ID => $jitaPrice];

        $refusal = $this->refusalOf($this->morphiteBomItem(), ContributionType::Material, 100, null);

        $this->assertSame('No market price for type 11399: no Jita price for the deposit day.', $refusal->getMessage());
    }

    #[Group('issue-363')]
    public function testSubmitMaterialWithoutAJitaPriceFallsBackOnNeitherTheBomPriceNorTheEnteredValue(): void
    {
        $this->jitaPrices = [self::TRITANIUM_TYPE_ID => null];
        $tritanium = $this->materialLine(self::TRITANIUM_TYPE_ID, 'Tritanium', self::TRITANIUM_BOM_PRICE);

        $refusal = $this->refusalOf($tritanium, ContributionType::Material, 1000, null);

        $this->assertSame('No market price for type 34: no Jita price for the deposit day.', $refusal->getMessage());
    }

    #[Group('issue-421')]
    public function testSubmitCourierContributionIsRefusedBecauseOnlyTheDeliveredContractDerivesIt(): void
    {
        $refusal = $this->refusalOf($this->bomItem, ContributionType::Courier, 1, 376550.0);

        $this->assertSame('The courier reward is derived from the linked courier contract and cannot be submitted', $refusal->getMessage());
    }

    public static function contributionOtherThanMaterialProvider(): iterable
    {
        yield 'job install' => [ContributionType::JobInstall, 2, 1834250.5];
        yield 'BPC' => [ContributionType::Bpc, 1, 36000.0];
        yield 'free line rental' => [ContributionType::LineRental, 3, 0.0];
    }

    #[Group('issue-363')]
    #[DataProvider('contributionOtherThanMaterialProvider')]
    public function testContributionOtherThanMaterialKeepsItsEnteredValueWithoutReadingAJitaPrice(ContributionType $type, int $quantity, float $enteredValue): void
    {
        $this->givenNoExistingContribution();
        $tritanium = $this->materialLine(self::TRITANIUM_TYPE_ID, 'Tritanium', self::TRITANIUM_BOM_PRICE);
        $this->entityManager->expects($this->once())->method('persist');

        $contribution = $this->service->submit($this->member, $tritanium, $type, $quantity, $enteredValue);

        $this->assertSame($type, $contribution->getType());
        $this->assertSame($enteredValue, $contribution->getEstimatedValue());
        $this->assertFalse($contribution->isAutoDetected());
        $this->assertFalse($contribution->isVerified());
        $this->assertSame(ContributionStatus::Pending, $contribution->getStatus());
        $this->assertSame([], $this->jitaLookups);
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
        $existingContribution = new IndustryProjectContribution();
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
        $existingContribution = new IndustryProjectContribution();
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

    #[Group('issue-363')]
    public function testSubmitMaterialAfterARejectionIsRecordedAtTheJitaPrice(): void
    {
        $this->givenNoExistingContribution();
        $this->jitaPrices = [self::TRITANIUM_TYPE_ID => self::TRITANIUM_JITA_PRICE];
        $tritanium = $this->materialLine(self::TRITANIUM_TYPE_ID, 'Tritanium', self::TRITANIUM_BOM_PRICE);
        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $contribution = $this->service->submit($this->member, $tritanium, ContributionType::Material, 500);

        $this->assertSame(ContributionStatus::Pending, $contribution->getStatus());
        $this->assertSame(3500.0, $contribution->getEstimatedValue());
    }

    public function testApproveChangeStatusAndIncrementsFulfillment(): void
    {
        $contribution = new IndustryProjectContribution();
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
        $contribution = new IndustryProjectContribution();
        $contribution->setStatus(ContributionStatus::Approved);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot approve a contribution with status "approved"');

        $this->service->approve($contribution, $this->reviewer);
    }

    public function testRejectChangesStatusWithoutFulfillment(): void
    {
        $contribution = new IndustryProjectContribution();
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
        $contribution = new IndustryProjectContribution();
        $contribution->setStatus(ContributionStatus::Rejected);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot reject a contribution with status "rejected"');

        $this->service->reject($contribution, $this->reviewer);
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

    #[Group('issue-363')]
    public function testSubmitJobInstallWithAnEnteredZeroValueIsRefusedThenRecordedAtFiveThousandOnceCorrected(): void
    {
        $this->givenNoExistingContribution();
        $this->entityManager->expects($this->once())->method('persist');

        try {
            $this->service->submit($this->member, $this->bomItem, ContributionType::JobInstall, 1, 0.0);
            $this->fail('The contribution was recorded');
        } catch (\DomainException $refusal) {
            $this->assertSame('The estimated value of a job_install contribution must be positive.', $refusal->getMessage());
        }

        $contribution = $this->service->submit($this->member, $this->bomItem, ContributionType::JobInstall, 1, 5000.0);

        $this->assertSame(ContributionStatus::Pending, $contribution->getStatus());
        $this->assertSame(5000.0, $contribution->getEstimatedValue());
        $this->assertFalse($contribution->isVerified());
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

    #[Group('issue-168')]
    public function testAntiDuplicateCheckRefusesAMemberWhoseContributionOfThatTypeIsPending(): void
    {
        $this->givenExistingContributionWithStatus(ContributionStatus::Pending);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('A contribution of this type is already pending or approved for this item');

        $this->service->validateAntiDuplicate($this->member, $this->bomItem, ContributionType::JobInstall);
    }

    #[Group('issue-168')]
    public function testAntiDuplicateCheckRefusesAMemberWhoseContributionOfThatTypeIsApproved(): void
    {
        $this->givenExistingContributionWithStatus(ContributionStatus::Approved);

        $this->expectException(\DomainException::class);

        $this->service->validateAntiDuplicate($this->member, $this->bomItem, ContributionType::LineRental);
    }

    #[Group('issue-168')]
    public function testAntiDuplicateCheckAcceptsAMemberWhoseContributionOfThatTypeWasRejected(): void
    {
        $this->givenExistingContributionWithStatus(ContributionStatus::Rejected);
        $this->entityManager->expects($this->never())->method('persist');

        $this->service->validateAntiDuplicate($this->member, $this->bomItem, ContributionType::Bpc);

        $this->addToAssertionCount(1);
    }

    private function refusalOf(IndustryProjectBomLine $bomItem, ContributionType $type, int $quantity, ?float $estimatedValue): \DomainException
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

    private function submitAndExpectOnePersist(ContributionType $type, int $quantity, float $estimatedValue): IndustryProjectContribution
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
        $existing = new IndustryProjectContribution();
        $existing->setStatus($status);
        $this->contributionRepository
            ->method('findByMemberBomItemAndType')
            ->willReturnCallback(static fn (IndustryProjectParticipant $member, IndustryProjectBomLine $bomItem, ContributionType $type, array $statuses): ?IndustryProjectContribution => in_array($status, $statuses, true) ? $existing : null);
    }

    private function memberOfTheProject(): IndustryProjectParticipant
    {
        $member = new IndustryProjectParticipant();
        $member->setProject($this->project);
        $member->setUser($this->createMock(User::class));

        return $member;
    }

    private function materialLine(int $typeId, string $typeName, float $bomPrice): IndustryProjectBomLine
    {
        $line = new IndustryProjectBomLine();
        $line->setProject($this->project);
        $line->setTypeId($typeId);
        $line->setTypeName($typeName);
        $line->setRequiredQuantity(10000);
        $line->setEstimatedPrice($bomPrice);

        return $line;
    }

    private function morphiteBomItem(): IndustryProjectBomLine
    {
        $morphite = new IndustryProjectBomLine();
        $morphite->setProject($this->project);
        $morphite->setTypeId(self::MORPHITE_TYPE_ID);
        $morphite->setTypeName('Morphite');
        $morphite->setRequiredQuantity(500);

        return $morphite;
    }
}
