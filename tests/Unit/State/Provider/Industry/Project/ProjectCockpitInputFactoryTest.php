<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectBomLineMark;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectPurchase;
use App\Enum\BomLineMarkState;
use App\Enum\ContributionStatus;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Service\Industry\Project\ClosingChecks;
use App\Service\Industry\Project\CockpitBomLine;
use App\Service\Industry\Project\ContainerStockState;
use App\Service\Industry\Project\ProjectCockpitInput;
use App\Service\Industry\Project\ProjectLineFulfillments;
use App\Service\Industry\Project\ProjectSettlement;
use App\Service\Industry\Project\SettlementTransfer;
use App\Service\Industry\Project\TransfersUnavailableReason;
use App\State\Provider\Industry\Project\ProjectCockpitInputFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectCockpitInputFactory::class)]
#[Group('issue-335')]
final class ProjectCockpitInputFactoryTest extends TestCase
{
    use BuildsWorkshopProjects;

    private const int TRITANIUM_TYPE_ID = 34;
    private const int ISOGEN_TYPE_ID = 37;
    private const int SABRE_TYPE_ID = 22456;
    private const int GILA_TYPE_ID = 17715;

    public function testJobLineCarriesTheRunsOfItsDeliveredEsiJobsOnly(): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Sabre batch', ProjectStatus::InProgress, $owner);
        $this->productOf($project, self::SABRE_TYPE_ID, 'Sabre', 5);
        $sabreJob = $this->jobOf($project, self::SABRE_TYPE_ID, 'Sabre', 'final', 5, 5, 5);
        $this->jobMatchOn($sabreJob, 1, 'delivered');
        $this->jobMatchOn($sabreJob, 2, 'delivered');
        $this->jobMatchOn($sabreJob, 3, 'active');
        $this->jobMatchOn($sabreJob, 4, 'cancelled');
        $this->jobMatchOn($sabreJob, 5, 'reverted');

        $input = (new ProjectCockpitInputFactory())->create($project, $owner);

        self::assertSame(3, $input->bomLines[0]->deliveredRuns);
        self::assertSame(15, $input->bomLines[0]->matchedRuns);
    }

    #[Group('issue-1195')]
    public function testJobLineCarriesTheLatestEndDateOfItsRunningEsiJobsOnly(): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Sabre batch', ProjectStatus::InProgress, $owner);
        $this->productOf($project, self::SABRE_TYPE_ID, 'Sabre', 5);
        $sabreJob = $this->jobOf($project, self::SABRE_TYPE_ID, 'Sabre', 'final', 5, 5, 0);
        $this->jobMatchEndingOn($sabreJob, 1, 'active', '2026-11-22 08:00:00');
        $this->jobMatchEndingOn($sabreJob, 2, 'ready', '2026-11-22 20:00:00');
        $this->jobMatchEndingOn($sabreJob, 3, 'delivered', '2026-11-25 09:00:00');
        $this->jobMatchEndingOn($sabreJob, 4, 'cancelled', '2026-11-26 09:00:00');

        $input = (new ProjectCockpitInputFactory())->create($project, $owner);

        self::assertSame('2026-11-22 20:00:00', $input->bomLines[0]->latestJobEndDate?->format('Y-m-d H:i:s'));
    }

    #[Group('issue-1195')]
    public function testJobLineWithoutRunningEsiJobHasNoLatestEndDate(): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Sabre batch', ProjectStatus::InProgress, $owner);
        $this->productOf($project, self::SABRE_TYPE_ID, 'Sabre', 5);
        $sabreJob = $this->jobOf($project, self::SABRE_TYPE_ID, 'Sabre', 'final', 5, 5, 0);
        $this->jobMatchEndingOn($sabreJob, 1, 'delivered', '2026-11-25 09:00:00');
        $this->jobMatchOn($sabreJob, 2, 'active');

        $input = (new ProjectCockpitInputFactory())->create($project, $owner);

        self::assertNull($input->bomLines[0]->latestJobEndDate);
    }

    #[Group('issue-807')]
    public function testJobLineCarriesTheCountedRunsOfItsStepMarkAndTheDeliveredRunsRaisedByItsDoneMark(): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Sabre batch', ProjectStatus::InProgress, $owner);
        $this->productOf($project, self::SABRE_TYPE_ID, 'Sabre', 10);
        $sabreJob = $this->jobOf($project, self::SABRE_TYPE_ID, 'Sabre', 'final', 10, 10, 0);
        $sabreJob->setMark(new IndustryProjectBomLineMark(BomLineMarkState::Done, 10, new \DateTimeImmutable('2026-11-13T09:00:00+00:00')));
        $this->jobMatchOn($sabreJob, 6, 'delivered');

        $input = (new ProjectCockpitInputFactory())->create($project, $owner);

        self::assertSame([10, 10], [$input->bomLines[0]->matchedRuns, $input->bomLines[0]->deliveredRuns]);
    }

    #[Group('issue-997')]
    public function testJobLineWithoutEsiJobCountsItsDoneMarkRunsAsDelivered(): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Sabre batch', ProjectStatus::InProgress, $owner);
        $this->productOf($project, self::SABRE_TYPE_ID, 'Sabre', 2);
        $sabreJob = $this->jobOf($project, self::SABRE_TYPE_ID, 'Sabre', 'final', 2, 2, 0);
        $sabreJob->setMark(new IndustryProjectBomLineMark(BomLineMarkState::Done, 2, new \DateTimeImmutable('2026-11-13T09:00:00+00:00')));

        $input = (new ProjectCockpitInputFactory())->create($project, $owner);

        self::assertSame([2, 2], [$input->bomLines[0]->matchedRuns, $input->bomLines[0]->deliveredRuns]);
    }

    #[Group('issue-997')]
    public function testJobLineWithALaunchedMarkIsNotDeliveredYet(): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Sabre batch', ProjectStatus::InProgress, $owner);
        $this->productOf($project, self::SABRE_TYPE_ID, 'Sabre', 2);
        $sabreJob = $this->jobOf($project, self::SABRE_TYPE_ID, 'Sabre', 'final', 2, 2, 0);
        $sabreJob->setMark(new IndustryProjectBomLineMark(BomLineMarkState::Launched, 2, new \DateTimeImmutable('2026-11-13T09:00:00+00:00')));

        $input = (new ProjectCockpitInputFactory())->create($project, $owner);

        self::assertSame([2, 0], [$input->bomLines[0]->matchedRuns, $input->bomLines[0]->deliveredRuns]);
    }

    public function testInputCarriesTheStatusTheProductsTheBomLinesTheSoldQuantitiesAndThePendingContributions(): void
    {
        $owner = $this->pilot();
        $viewer = $this->pilot();
        $project = $this->workshopProject('Sabre batch', ProjectStatus::Selling, $owner);
        $this->membershipOf($project, $viewer, ParticipantRole::Member, ParticipantStatus::Accepted);
        $contributorMembership = $this->membershipOf($project, $this->pilot(), ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->productOf($project, self::SABRE_TYPE_ID, 'Sabre', 5);
        $this->productOf($project, self::GILA_TYPE_ID, 'Gila', 2);
        $tritanium = $this->materialOf($project, self::TRITANIUM_TYPE_ID, 'Tritanium', 40_000, 38_500);
        $sabreJob = $this->jobOf($project, self::SABRE_TYPE_ID, 'Sabre', 'final', 5, 5, 5);
        $this->matchedRunsOn($sabreJob, 2);
        $this->saleOf($project, self::SABRE_TYPE_ID, 'Sabre', 3);
        $this->saleOf($project, self::SABRE_TYPE_ID, 'Sabre', 1);
        $this->saleOf($project, self::ISOGEN_TYPE_ID, 'Isogen', 50);
        $this->contributionOf($project, $contributorMembership, 1_200, ContributionStatus::Pending);
        $this->contributionOf($project, $contributorMembership, 800, ContributionStatus::Pending);
        $this->contributionOf($project, $contributorMembership, 400, ContributionStatus::Approved);
        $this->contributionOf($project, $contributorMembership, 300, ContributionStatus::Rejected);

        $input = (new ProjectCockpitInputFactory())->create($project, $viewer);

        self::assertEquals(
            new ProjectCockpitInput(
                ProjectStatus::Selling,
                2,
                [
                    new CockpitBomLine($tritanium->getId()->toRfc4122(), self::TRITANIUM_TYPE_ID, 'Tritanium', 40_000, 38_500, false, null, null, 0, 0),
                    new CockpitBomLine($sabreJob->getId()->toRfc4122(), self::SABRE_TYPE_ID, 'Sabre', 5, 5, true, 'final', 5, 2, 0),
                ],
                [self::SABRE_TYPE_ID => 4, self::ISOGEN_TYPE_ID => 50],
                2,
                false,
            ),
            $input,
        );
    }

    #[Group('issue-1021')]
    public function testOwnerWithAnotherAcceptedAdminDoesNotCountHisOwnPendingContributionsToReview(): void
    {
        $owner = $this->pilot();
        $admin = $this->pilot();
        $contributor = $this->pilot();
        $project = $this->workshopProject('Sabre batch', ProjectStatus::InProgress, $owner);
        $ownerMembership = $this->membershipOf($project, $owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $adminMembership = $this->membershipOf($project, $admin, ParticipantRole::Admin, ParticipantStatus::Accepted);
        $contributorMembership = $this->membershipOf($project, $contributor, ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->contributionOf($project, $ownerMembership, 1_200, ContributionStatus::Pending);
        $this->contributionOf($project, $ownerMembership, 800, ContributionStatus::Pending);
        $this->contributionOf($project, $adminMembership, 500, ContributionStatus::Pending);
        $this->contributionOf($project, $contributorMembership, 300, ContributionStatus::Pending);
        $this->contributionOf($project, $contributorMembership, 100, ContributionStatus::Approved);

        $factory = new ProjectCockpitInputFactory();

        self::assertSame(
            ['owner' => 2, 'admin' => 3],
            [
                'owner' => $factory->create($project, $owner)->pendingContributionCount,
                'admin' => $factory->create($project, $admin)->pendingContributionCount,
            ],
        );
    }

    #[Group('issue-1021')]
    public function testSoleReviewingOwnerCountsHisOwnPendingContributions(): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Sabre batch', ProjectStatus::InProgress, $owner);
        $ownerMembership = $this->membershipOf($project, $owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->contributionOf($project, $ownerMembership, 1_200, ContributionStatus::Pending);

        self::assertSame(1, (new ProjectCockpitInputFactory())->create($project, $owner)->pendingContributionCount);
    }

    #[Group('issue-1176')]
    public function testSoleOwnerDoesNotCountHisContributionAwaitingWalletUnits(): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Sabre batch', ProjectStatus::InProgress, $owner);
        $ownerMembership = $this->membershipOf($project, $owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->contributionOf($project, $ownerMembership, 1_200, ContributionStatus::Pending);
        $project->getContributions()->first()->setAwaitingQuantity(40);

        self::assertSame(0, (new ProjectCockpitInputFactory())->create($project, $owner)->pendingContributionCount);
    }

    #[Group('issue-866')]
    public function testMaterialLineCarriesTheEffectiveFulfilledQuantityAndJobLinesTheirOwn(): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Sabre batch', ProjectStatus::InProgress, $owner);
        $this->productOf($project, self::SABRE_TYPE_ID, 'Sabre', 5);
        $tritanium = $this->materialOf($project, self::TRITANIUM_TYPE_ID, 'Tritanium', 40_000, 1_000);
        $sabreJob = $this->jobOf($project, self::SABRE_TYPE_ID, 'Sabre', 'final', 5, 5, 0);
        $fulfillments = new ProjectLineFulfillments(ContainerStockState::Known, [self::TRITANIUM_TYPE_ID => 25_000, self::SABRE_TYPE_ID => 5]);

        $input = (new ProjectCockpitInputFactory())->create($project, $owner, $fulfillments);

        self::assertSame(
            [$tritanium->getId()->toRfc4122() => 25_000, $sabreJob->getId()->toRfc4122() => 0],
            array_column(array_map(static fn (CockpitBomLine $line): array => [$line->id, $line->fulfilledQuantity], $input->bomLines), 1, 0),
        );
    }

    public function testOnlyTheOwnerAndTheAcceptedAdminAreManagers(): void
    {
        $owner = $this->pilot();
        $acceptedAdmin = $this->pilot();
        $acceptedMember = $this->pilot();
        $pendingAdmin = $this->pilot();
        $removedAdmin = $this->pilot();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $owner);
        $this->membershipOf($project, $acceptedAdmin, ParticipantRole::Admin, ParticipantStatus::Accepted);
        $this->membershipOf($project, $acceptedMember, ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->membershipOf($project, $pendingAdmin, ParticipantRole::Admin, ParticipantStatus::Pending);
        $this->membershipOf($project, $removedAdmin, ParticipantRole::Admin, ParticipantStatus::Removed);
        $factory = new ProjectCockpitInputFactory();

        $viewerIsManager = array_map(
            static fn ($viewer): bool => $factory->create($project, $viewer)->viewerIsManager,
            ['owner' => $owner, 'acceptedAdmin' => $acceptedAdmin, 'acceptedMember' => $acceptedMember, 'pendingAdmin' => $pendingAdmin, 'removedAdmin' => $removedAdmin],
        );

        self::assertSame(
            ['owner' => true, 'acceptedAdmin' => true, 'acceptedMember' => false, 'pendingAdmin' => false, 'removedAdmin' => false],
            $viewerIsManager,
        );
    }

    #[Group('issue-1218')]
    public function testUnreceivedTransferCountIsTheTransfersOfTheSettlementWithoutAReceipt(): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::Completed, $owner);
        $received = new \DateTimeImmutable('2026-11-20T10:00:00+00:00');
        $settlement = $this->settlementOf([
            $this->transferOf($received),
            $this->transferOf(null),
            $this->transferOf(null),
        ]);

        $input = (new ProjectCockpitInputFactory())->create($project, $owner, null, $settlement);

        self::assertSame(2, $input->unreceivedTransferCount);
    }

    #[Group('issue-1218')]
    public function testUnreceivedTransferCountIsZeroWhenTheSettlementHasNoTransferToMake(): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::Completed, $owner);

        $input = (new ProjectCockpitInputFactory())->create($project, $owner, null, $this->settlementOf([]));

        self::assertSame(0, $input->unreceivedTransferCount);
    }

    #[Group('issue-1218')]
    public function testUnreceivedTransferCountIsUnknownWithoutASettlementOrWhenItsTransfersAreUnavailable(): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::Completed, $owner);
        $factory = new ProjectCockpitInputFactory();

        self::assertNull($factory->create($project, $owner)->unreceivedTransferCount);
        self::assertNull($factory->create($project, $owner, null, $this->settlementOf(null))->unreceivedTransferCount);
    }

    /**
     * @param list<SettlementTransfer>|null $transfers
     */
    private function settlementOf(?array $transfers): ProjectSettlement
    {
        return new ProjectSettlement(
            'IP-1218',
            $transfers,
            $transfers === null ? TransfersUnavailableReason::UnknownCost : null,
            0,
            new ClosingChecks(0, [], null, null, null),
        );
    }

    private function transferOf(?\DateTimeImmutable $receivedAt): SettlementTransfer
    {
        return new SettlementTransfer('from-participant', 'Payer', 'to-participant', 'Recipient', 'to-user', 250_000_00, $receivedAt, false);
    }

    #[Group('issue-1219')]
    public function testInputCountsOnlyThePendingMembersAsJoinRequests(): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::Published, $owner);
        $this->membershipOf($project, $owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->membershipOf($project, $this->pilot(), ParticipantRole::Member, ParticipantStatus::Pending);
        $this->membershipOf($project, $this->pilot(), ParticipantRole::Member, ParticipantStatus::Pending);
        $this->membershipOf($project, $this->pilot(), ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->membershipOf($project, $this->pilot(), ParticipantRole::Member, ParticipantStatus::Removed);

        self::assertSame(2, (new ProjectCockpitInputFactory())->create($project, $owner)->pendingJoinRequestCount);
    }

    #[Group('issue-1175')]
    public function testCountsTheBoughtLinesWhoseContainerStockDoesNotHoldThePurchases(): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Sabre batch', ProjectStatus::InProgress, $owner);
        $tritanium = $this->materialOf($project, self::TRITANIUM_TYPE_ID, 'Tritanium', 1_000, 1_000);
        $isogen = $this->materialOf($project, self::ISOGEN_TYPE_ID, 'Isogen', 500, 500);
        $this->materialOf($project, 35, 'Pyerite', 300, 0);
        $this->purchaseOn($tritanium, 600);
        $this->purchaseOn($tritanium, 400);
        $this->purchaseOn($isogen, 500);
        $fulfillments = new ProjectLineFulfillments(ContainerStockState::Known, [self::TRITANIUM_TYPE_ID => 700, self::ISOGEN_TYPE_ID => 500]);

        $input = (new ProjectCockpitInputFactory())->create($project, $owner, $fulfillments);

        self::assertSame(1, $input->purchasesToDeliverCount);
    }

    #[Group('issue-1175')]
    public function testBoughtLinesAreNotCountedWhenTheContainerStockIsUnknown(): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Sabre batch', ProjectStatus::InProgress, $owner);
        $this->purchaseOn($this->materialOf($project, self::TRITANIUM_TYPE_ID, 'Tritanium', 1_000, 1_000), 1_000);

        $input = (new ProjectCockpitInputFactory())->create($project, $owner);

        self::assertSame(0, $input->purchasesToDeliverCount);
    }

    private function purchaseOn(IndustryProjectBomLine $line, int $quantity): void
    {
        $line->addPurchase((new IndustryProjectPurchase())->setTypeId($line->getTypeId())->setQuantity($quantity)->setUnitPrice(1.0)->setTotalPrice((float) $quantity)->setSource('manual'));
    }

    private function jobMatchEndingOn(IndustryProjectBomLine $line, int $runs, string $status, string $endDate): void
    {
        $line->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(612_337_000 + $runs)->setRuns($runs)->setStatus($status)->setEndDate(new \DateTimeImmutable($endDate)));
    }

    private function jobMatchOn(IndustryProjectBomLine $line, int $runs, string $status): void
    {
        $line->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(612_336_000 + $runs)->setRuns($runs)->setStatus($status));
    }
}
