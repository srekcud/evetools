<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ContributionValueSource;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Service\Industry\Project\ContributorPaidPurchaseReader;
use App\Service\Industry\Project\LineReservationService;
use App\Service\Industry\Project\ProjectContributionService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectContributionService::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-1013')]
final class GroupIntermediateClaimFlowTest extends TestCase
{
    use BuildsProjectServiceOnGoldenSde;

    private const float HYDROGEN_FUEL_BLOCK_JITA_PRICE = 2_150.0;
    private const float HYDROGEN_FUEL_BLOCK_LAUNCH_PRICE = 2_000.0;
    private const int HYDROGEN_ISOTOPES = 17889;
    private const int PROJECT_MATERIAL_EFFICIENCY = 10;
    private const int PROJECT_TIME_EFFICIENCY = 20;

    private ProjectContributionService $contributionService;

    protected function setUp(): void
    {
        $this->setUpGoldenSde();
        $this->jitaPrices = [self::HYDROGEN_FUEL_BLOCK => self::HYDROGEN_FUEL_BLOCK_JITA_PRICE];
        $this->contributionService = new ProjectContributionService(
            $this->contributionRepository,
            $this->entityManager,
            $this->jitaMarket,
            $this->createStub(LineReservationService::class),
            $this->projectService,
            $this->createStub(ContributorPaidPurchaseReader::class),
        );
    }

    public function testMemberClaimOfContainerStockOnAnIntermediateLineIsPendingAndLeavesThePlanUntouched(): void
    {
        [$project, $member, $hydrogenFuelBlock] = $this->groupProject();

        $claim = $this->contributionService->submit($member, $hydrogenFuelBlock, ContributionType::Material, 30, null, null, ContributionValueSource::LaunchPrice);

        self::assertSame(
            [ContributionStatus::Pending, ContributionValueSource::LaunchPrice, 60_000.0, 3, 120, 0],
            [$claim->getStatus(), $claim->getValueSource(), $claim->getEstimatedValue(), $hydrogenFuelBlock->getRuns(), $hydrogenFuelBlock->getRequiredQuantity(), $hydrogenFuelBlock->getFulfilledQuantity()],
        );
        self::assertSame(1_215, $this->materialLineOf($project, self::HYDROGEN_ISOTOPES)->getRequiredQuantity());
    }

    public function testApprovingTheClaimMakesTheClaimedStockTheStartingStockOfThePlan(): void
    {
        [$project, $member, $hydrogenFuelBlock] = $this->groupProject();
        $claim = $this->contributionService->submit($member, $hydrogenFuelBlock, ContributionType::Material, 30, null, null, ContributionValueSource::LaunchPrice);

        $this->contributionService->approve($claim, $this->owner);

        self::assertSame([2, 110, 30], [$hydrogenFuelBlock->getRuns(), $hydrogenFuelBlock->getRequiredQuantity(), $hydrogenFuelBlock->getFulfilledQuantity()]);
        self::assertSame(810, $this->materialLineOf($project, self::HYDROGEN_ISOTOPES)->getRequiredQuantity());
    }

    public function testOwnerAssignmentOfTheContainerStockIsApprovedAtOnceAndReducesThePlan(): void
    {
        [$project, $member, $hydrogenFuelBlock] = $this->groupProject();

        $assignment = $this->contributionService->assignUnclaimedStock($member, $hydrogenFuelBlock, 30, $this->owner);

        self::assertSame(
            [ContributionStatus::Approved, ContributionValueSource::LaunchPrice, 60_000.0, $member],
            [$assignment->getStatus(), $assignment->getValueSource(), $assignment->getEstimatedValue(), $assignment->getMember()],
        );
        self::assertSame([2, 110, 30], [$hydrogenFuelBlock->getRuns(), $hydrogenFuelBlock->getRequiredQuantity(), $hydrogenFuelBlock->getFulfilledQuantity()]);
        self::assertSame(810, $this->materialLineOf($project, self::HYDROGEN_ISOTOPES)->getRequiredQuantity());
    }

    public function testOwnerDonationOfTheContainerStockIsWorthNothingAndReducesThePlan(): void
    {
        [$project, , $hydrogenFuelBlock] = $this->groupProject();

        $donation = $this->contributionService->donateUnclaimedStock($this->ownerOf($project), $hydrogenFuelBlock, 30, $this->owner);

        self::assertSame([0.0, ContributionValueSource::Donation], [$donation->getEstimatedValue(), $donation->getValueSource()]);
        self::assertSame([2, 110, 30], [$hydrogenFuelBlock->getRuns(), $hydrogenFuelBlock->getRequiredQuantity(), $hydrogenFuelBlock->getFulfilledQuantity()]);
    }

    public function testIntermediateContributionWithAnotherValueSourceStaysSoloOnly(): void
    {
        [, $member, $hydrogenFuelBlock] = $this->groupProject();

        foreach ([ContributionValueSource::JitaPrice, ContributionValueSource::WalletPurchase] as $source) {
            $failure = $this->failureOf(fn () => $this->contributionService->submit($member, $hydrogenFuelBlock, ContributionType::Material, 30, null, null, $source));

            self::assertInstanceOf(\DomainException::class, $failure, $source->value);
        }
        self::assertSame([], $this->contributions);
        self::assertSame([3, 120, 0], [$hydrogenFuelBlock->getRuns(), $hydrogenFuelBlock->getRequiredQuantity(), $hydrogenFuelBlock->getFulfilledQuantity()]);
    }

    public function testAllocationOnABlueprintJobLineIsStillRefused(): void
    {
        [$project] = $this->groupProject();
        $copyLine = $this->lineOf($project, self::HYDROGEN_FUEL_BLOCK, 'copy', 'blueprint');

        $failure = $this->failureOf(fn () => $this->contributionService->donateUnclaimedStock($this->ownerOf($project), $copyLine, 1, $this->owner));

        self::assertInstanceOf(\DomainException::class, $failure);
        self::assertSame([], $this->contributions);
    }

    /**
     * @return array{IndustryProject, IndustryProjectParticipant, IndustryProjectBomLine}
     */
    private function groupProject(): array
    {
        $project = $this->soloProjectOf([[self::FERNITE_CARBIDE, 10, self::PROJECT_MATERIAL_EFFICIENCY, self::PROJECT_TIME_EFFICIENCY]]);
        $this->projectService->buildBom($project, $this->owner);
        $member = (new IndustryProjectParticipant())
            ->setUser($this->createStub(User::class))
            ->setRole(ParticipantRole::Member)
            ->setStatus(ParticipantStatus::Accepted);
        $project->addMember($member);
        $hydrogenFuelBlock = $this->lineOf($project, self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 'component');
        $hydrogenFuelBlock->setLaunchPrice(self::HYDROGEN_FUEL_BLOCK_LAUNCH_PRICE);

        return [$project, $member, $hydrogenFuelBlock];
    }

    private function failureOf(callable $action): ?\Throwable
    {
        try {
            $action();
        } catch (\Throwable $failure) {
            return $failure;
        }

        return null;
    }
}
