<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectBomLineMark;
use App\Entity\IndustryProjectJobMatch;
use App\Enum\BomLineMarkState;
use App\Enum\ContributionStatus;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Service\Industry\Project\ChainRecipeIngredients;
use App\Service\Industry\Project\CockpitBomLine;
use App\Service\Industry\Project\ContainerStockState;
use App\Service\Industry\Project\ProjectCockpitInput;
use App\Service\Industry\Project\ProjectLineFulfillments;
use App\State\Provider\Industry\Project\ProjectCockpitInputFactory;
use App\Tests\Unit\Service\Industry\Project\InMemoryChainRecipeIngredients;
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
    private const int FUSION_THRUSTER_TYPE_ID = 11531;
    private const int RAM_STARSHIP_TECH_TYPE_ID = 11478;

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

        $input = (new ProjectCockpitInputFactory(new InMemoryChainRecipeIngredients([])))->create($project, $owner);

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

        $input = (new ProjectCockpitInputFactory(new InMemoryChainRecipeIngredients([])))->create($project, $owner);

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

        $input = (new ProjectCockpitInputFactory(new InMemoryChainRecipeIngredients([])))->create($project, $owner);

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

        $input = (new ProjectCockpitInputFactory(new InMemoryChainRecipeIngredients([])))->create($project, $owner);

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

        $input = (new ProjectCockpitInputFactory(new InMemoryChainRecipeIngredients([])))->create($project, $owner);

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

        $input = (new ProjectCockpitInputFactory(new InMemoryChainRecipeIngredients([])))->create($project, $owner);

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

        $input = (new ProjectCockpitInputFactory(new InMemoryChainRecipeIngredients([])))->create($project, $viewer);

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

    #[Group('issue-1196')]
    public function testInProgressJobLinesCarryTheirChainLevelAndTheirParentType(): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Sabre batch', ProjectStatus::InProgress, $owner);
        $this->productOf($project, self::SABRE_TYPE_ID, 'Sabre', 5);
        $this->jobOf($project, self::SABRE_TYPE_ID, 'Sabre', 'final', 5, 5, 0);
        $this->jobOf($project, self::FUSION_THRUSTER_TYPE_ID, 'Fusion Thruster', 'component', 9, 45, 0)->setParentTypeId(self::SABRE_TYPE_ID);
        $this->jobOf($project, self::RAM_STARSHIP_TECH_TYPE_ID, 'R.A.M.- Starship Tech', 'component', 3, 300, 0)->setParentTypeId(self::FUSION_THRUSTER_TYPE_ID);
        $this->materialOf($project, self::TRITANIUM_TYPE_ID, 'Tritanium', 40_000, 0);
        $recipes = new InMemoryChainRecipeIngredients([
            self::SABRE_TYPE_ID => [self::FUSION_THRUSTER_TYPE_ID],
            self::FUSION_THRUSTER_TYPE_ID => [self::RAM_STARSHIP_TECH_TYPE_ID],
        ]);

        $input = (new ProjectCockpitInputFactory($recipes))->create($project, $owner);

        self::assertSame(
            [[3, null], [2, self::SABRE_TYPE_ID], [1, self::FUSION_THRUSTER_TYPE_ID], [null, null]],
            array_map(static fn (CockpitBomLine $line): array => [$line->chainLevel, $line->parentTypeId], $input->bomLines),
        );
    }

    #[Group('issue-1196')]
    public function testChainLevelsAreOnlyComputedForAnInProgressProject(): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Sabre batch', ProjectStatus::Selling, $owner);
        $this->productOf($project, self::SABRE_TYPE_ID, 'Sabre', 5);
        $this->jobOf($project, self::SABRE_TYPE_ID, 'Sabre', 'final', 5, 5, 5);
        $recipes = new class implements ChainRecipeIngredients {
            public function producedIngredientsOf(array $productTypeIds): array
            {
                throw new \LogicException('The recipes must not be read outside the in progress status.');
            }
        };

        $input = (new ProjectCockpitInputFactory($recipes))->create($project, $owner);

        self::assertNull($input->bomLines[0]->chainLevel);
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

        $factory = new ProjectCockpitInputFactory(new InMemoryChainRecipeIngredients([]));

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

        self::assertSame(1, (new ProjectCockpitInputFactory(new InMemoryChainRecipeIngredients([])))->create($project, $owner)->pendingContributionCount);
    }

    #[Group('issue-1176')]
    public function testSoleOwnerDoesNotCountHisContributionAwaitingWalletUnits(): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Sabre batch', ProjectStatus::InProgress, $owner);
        $ownerMembership = $this->membershipOf($project, $owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->contributionOf($project, $ownerMembership, 1_200, ContributionStatus::Pending);
        $project->getContributions()->first()->setAwaitingQuantity(40);

        self::assertSame(0, (new ProjectCockpitInputFactory(new InMemoryChainRecipeIngredients([])))->create($project, $owner)->pendingContributionCount);
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

        $input = (new ProjectCockpitInputFactory(new InMemoryChainRecipeIngredients([])))->create($project, $owner, $fulfillments);

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
        $factory = new ProjectCockpitInputFactory(new InMemoryChainRecipeIngredients([]));

        $viewerIsManager = array_map(
            static fn ($viewer): bool => $factory->create($project, $viewer)->viewerIsManager,
            ['owner' => $owner, 'acceptedAdmin' => $acceptedAdmin, 'acceptedMember' => $acceptedMember, 'pendingAdmin' => $pendingAdmin, 'removedAdmin' => $removedAdmin],
        );

        self::assertSame(
            ['owner' => true, 'acceptedAdmin' => true, 'acceptedMember' => false, 'pendingAdmin' => false, 'removedAdmin' => false],
            $viewerIsManager,
        );
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
