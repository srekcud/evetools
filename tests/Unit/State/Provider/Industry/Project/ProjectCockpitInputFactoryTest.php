<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use App\Enum\ContributionStatus;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Service\Industry\Project\CockpitBomLine;
use App\Service\Industry\Project\ProjectCockpitInput;
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

    public function testInputCarriesTheStatusTheProductsTheBomLinesTheSoldQuantitiesAndThePendingContributions(): void
    {
        $owner = $this->pilot();
        $viewer = $this->pilot();
        $project = $this->workshopProject('Sabre batch', ProjectStatus::Selling, $owner);
        $viewerMembership = $this->membershipOf($project, $viewer, ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->productOf($project, self::SABRE_TYPE_ID, 'Sabre', 5);
        $this->productOf($project, self::GILA_TYPE_ID, 'Gila', 2);
        $tritanium = $this->materialOf($project, self::TRITANIUM_TYPE_ID, 'Tritanium', 40_000, 38_500);
        $sabreJob = $this->jobOf($project, self::SABRE_TYPE_ID, 'Sabre', 'final', 5, 5, 5);
        $this->matchedRunsOn($sabreJob, 2);
        $this->saleOf($project, self::SABRE_TYPE_ID, 'Sabre', 3);
        $this->saleOf($project, self::SABRE_TYPE_ID, 'Sabre', 1);
        $this->saleOf($project, self::ISOGEN_TYPE_ID, 'Isogen', 50);
        $this->contributionOf($project, $viewerMembership, 1_200, ContributionStatus::Pending);
        $this->contributionOf($project, $viewerMembership, 800, ContributionStatus::Pending);
        $this->contributionOf($project, $viewerMembership, 400, ContributionStatus::Approved);
        $this->contributionOf($project, $viewerMembership, 300, ContributionStatus::Rejected);

        $input = (new ProjectCockpitInputFactory())->create($project, $viewer);

        self::assertEquals(
            new ProjectCockpitInput(
                ProjectStatus::Selling,
                2,
                [
                    new CockpitBomLine($tritanium->getId()->toRfc4122(), self::TRITANIUM_TYPE_ID, 'Tritanium', 40_000, 38_500, false, null, null, 0),
                    new CockpitBomLine($sabreJob->getId()->toRfc4122(), self::SABRE_TYPE_ID, 'Sabre', 5, 5, true, 'final', 5, 2),
                ],
                [self::SABRE_TYPE_ID => 4, self::ISOGEN_TYPE_ID => 50],
                2,
                false,
            ),
            $input,
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
}
