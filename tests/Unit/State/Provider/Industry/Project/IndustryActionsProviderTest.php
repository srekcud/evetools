<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Industry\Project\IndustryActionResource;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Industry\Application\MarketPrices;
use App\Industry\Application\WeightedPrice;
use App\Industry\Domain\Isk;
use App\Industry\Domain\OrderBookCoverage;
use App\Repository\IndustryProjectParticipantRepository;
use App\Service\Industry\Project\ActionAssessor;
use App\Service\Industry\Project\ActionQueueSorter;
use App\Service\Industry\Project\ProjectCockpitCalculator;
use App\Service\Industry\Project\ProjectPriceAlert;
use App\Service\Industry\Project\ProjectPriceAlertReader;
use App\State\Provider\Industry\Project\IndustryActionsProvider;
use App\State\Provider\Industry\Project\ProjectCockpitInputFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(IndustryActionsProvider::class)]
#[Group('issue-335')]
final class IndustryActionsProviderTest extends TestCase
{
    use BuildsWorkshopProjects;

    private const int TRITANIUM_TYPE_ID = 34;
    private const int PYERITE_TYPE_ID = 35;
    private const int SABRE_TYPE_ID = 22456;
    private const int GILA_TYPE_ID = 17715;
    private const int ISHTAR_TYPE_ID = 12005;
    private const int HECATE_TYPE_ID = 35683;

    private Security&Stub $security;
    private IndustryProjectParticipantRepository&Stub $memberRepository;
    private MarketPrices&Stub $marketPrices;
    private ProjectPriceAlertReader&Stub $priceAlertReader;
    private User $viewer;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->memberRepository = $this->createStub(IndustryProjectParticipantRepository::class);
        $this->marketPrices = $this->createStub(MarketPrices::class);
        $this->priceAlertReader = $this->createStub(ProjectPriceAlertReader::class);
        $this->viewer = $this->pilot();
        $this->security->method('getUser')->willReturn($this->viewer);
    }

    public function testQueueWithoutConnectedUserIsUnauthorized(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);
        $provider = new IndustryActionsProvider($security, $this->memberRepository, new ProjectCockpitInputFactory(), new ProjectCockpitCalculator(), new ActionQueueSorter(), new ActionAssessor($this->marketPrices), $this->priceAlertReader);

        $this->expectException(UnauthorizedHttpException::class);

        $provider->provide(new GetCollection());
    }

    public function testBlockedProjectComesFirstThenTheSaleToRecordThenTheProjectToStart(): void
    {
        $cutlassBatch = $this->ownedProject('Cutlass batch', ProjectStatus::Draft);
        $ishtarBatch = $this->ownedProject('Ishtar batch', ProjectStatus::InProgress);
        $this->materialOf($ishtarBatch, self::TRITANIUM_TYPE_ID, 'Tritanium', 40_000, 0);
        $sabreBatch = $this->ownedProject('Sabre batch', ProjectStatus::Selling);
        $this->jobOf($sabreBatch, self::SABRE_TYPE_ID, 'Sabre', 'final', 5, 5, 5);
        $this->saleOf($sabreBatch, self::SABRE_TYPE_ID, 'Sabre', 2);
        $this->memberOfProjects($cutlassBatch, $ishtarBatch, $sabreBatch);

        self::assertSame(
            [['Ishtar batch', 'source_material', true], ['Sabre batch', 'record_sale', false], ['Cutlass batch', 'start_project', false]],
            $this->queueSummary(),
        );
    }

    public function testSaleToRecordThenProjectToCompleteThenProjectToStart(): void
    {
        $hecateBatch = $this->ownedProject('Hecate batch', ProjectStatus::Published);
        $this->materialOf($hecateBatch, self::PYERITE_TYPE_ID, 'Pyerite', 6_000, 6_000);
        $gilaBatch = $this->ownedProject('Gila batch', ProjectStatus::Selling);
        $this->jobOf($gilaBatch, self::GILA_TYPE_ID, 'Gila', 'final', 2, 2, 2);
        $this->saleOf($gilaBatch, self::GILA_TYPE_ID, 'Gila', 2);
        $sabreBatch = $this->ownedProject('Sabre batch', ProjectStatus::Selling);
        $this->jobOf($sabreBatch, self::SABRE_TYPE_ID, 'Sabre', 'final', 5, 5, 5);
        $this->saleOf($sabreBatch, self::SABRE_TYPE_ID, 'Sabre', 4);
        $this->memberOfProjects($hecateBatch, $gilaBatch, $sabreBatch);

        self::assertSame(
            [['Sabre batch', 'record_sale', false], ['Gila batch', 'complete_project', false], ['Hecate batch', 'start_project', false]],
            $this->queueSummary(),
        );
    }

    public function testTwoProjectsWithAJobToLaunchAreOrderedByProjectName(): void
    {
        $bravoBatch = $this->ownedProject('Bravo', ProjectStatus::InProgress);
        $this->jobOf($bravoBatch, self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 2, 2, 0);
        $alphaBatch = $this->ownedProject('Alpha', ProjectStatus::InProgress);
        $this->jobOf($alphaBatch, self::HECATE_TYPE_ID, 'Hecate', 'final', 1, 1, 0);
        $this->memberOfProjects($bravoBatch, $alphaBatch);

        self::assertSame(
            [['Alpha', 'launch_job', false], ['Bravo', 'launch_job', false]],
            $this->queueSummary(),
        );
    }

    public function testCompletedProjectAndDraftOfASimpleMemberLeaveTheQueueEmpty(): void
    {
        $completedBatch = $this->ownedProject('Ishtar batch', ProjectStatus::Completed);
        $draftOfAnotherOwner = $this->workshopProject('Cutlass batch', ProjectStatus::Draft, $this->pilot());
        $membership = $this->membershipOf($draftOfAnotherOwner, $this->viewer, ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->memberRepository->method('findAcceptedWithProjectsByUser')->willReturn([$this->ownerMembershipOf($completedBatch), $membership]);

        self::assertSame([], $this->provide());
    }

    public function testEntryCarriesEveryFieldOfTheNextActionOfTheCockpit(): void
    {
        $ishtarBatch = $this->ownedProject('Ishtar batch', ProjectStatus::InProgress);
        $this->materialOf($ishtarBatch, self::TRITANIUM_TYPE_ID, 'Tritanium', 40_000, 12_500);
        $tritaniumLine = $ishtarBatch->getBomItems()->first();
        $this->memberOfProjects($ishtarBatch);
        $this->marketPrices->method('unitPricesFor')->willReturn([self::TRITANIUM_TYPE_ID => new WeightedPrice(new Isk(5.0), new OrderBookCoverage(1.0))]);

        $entries = $this->provide();

        self::assertContainsOnlyInstancesOf(IndustryActionResource::class, $entries);
        self::assertSame(
            [[
                'projectId' => $ishtarBatch->getId()->toRfc4122(),
                'projectName' => 'Ishtar batch',
                'code' => 'source_material',
                'blocked' => true,
                'tab' => 'materials',
                'targetStatus' => null,
                'bomLineId' => $tritaniumLine->getId()->toRfc4122(),
                'typeId' => self::TRITANIUM_TYPE_ID,
                'typeName' => 'Tritanium',
                'quantity' => 27_500,
                'severity' => 'warning',
                'impactIsk' => 137_500.0,
                'jitaUnitPrice' => null,
                'breakEvenPerUnit' => null,
            ]],
            array_map('get_object_vars', $entries),
        );
    }

    public function testManagerOfADraftIsAskedToStartTheProjectAndTheUnnamedProjectKeepsAnAbsentName(): void
    {
        $unnamedBatch = $this->ownedProject(null, ProjectStatus::Draft);
        $this->memberOfProjects($unnamedBatch);

        self::assertSame(
            [[
                'projectId' => $unnamedBatch->getId()->toRfc4122(),
                'projectName' => null,
                'code' => 'start_project',
                'blocked' => false,
                'tab' => null,
                'targetStatus' => 'in_progress',
                'bomLineId' => null,
                'typeId' => null,
                'typeName' => null,
                'quantity' => null,
                'severity' => 'info',
                'impactIsk' => null,
                'jitaUnitPrice' => null,
                'breakEvenPerUnit' => null,
            ]],
            array_map('get_object_vars', $this->provide()),
        );
    }

    public function testProjectWithAJitaPriceBelowItsBreakEvenGetsAWarningLineBeforeTheOtherActions(): void
    {
        $sabreBatch = $this->ownedProject('Sabre batch', ProjectStatus::Selling);
        $this->jobOf($sabreBatch, self::SABRE_TYPE_ID, 'Sabre', 'final', 5, 5, 5);
        $this->saleOf($sabreBatch, self::SABRE_TYPE_ID, 'Sabre', 2);
        $cutlassBatch = $this->ownedProject('Cutlass batch', ProjectStatus::Draft);
        $this->memberOfProjects($cutlassBatch, $sabreBatch);
        $this->priceAlertReader->method('of')->willReturnCallback(
            static fn (IndustryProject $project): ?ProjectPriceAlert => $project === $sabreBatch ? new ProjectPriceAlert(self::SABRE_TYPE_ID, 'Sabre', 3, 690_000.0, 760_000.0) : null,
        );

        $entries = $this->provide();

        self::assertSame(
            [['Sabre batch', 'price_below_break_even'], ['Sabre batch', 'record_sale'], ['Cutlass batch', 'start_project']],
            array_map(static fn (IndustryActionResource $entry): array => [$entry->projectName, $entry->code], $entries),
        );
        self::assertSame(
            [$sabreBatch->getId()->toRfc4122(), false, 'sales', null, null, self::SABRE_TYPE_ID, 'Sabre', 3, 'warning', null, 690_000.0, 760_000.0],
            [
                $entries[0]->projectId,
                $entries[0]->blocked,
                $entries[0]->tab,
                $entries[0]->targetStatus,
                $entries[0]->bomLineId,
                $entries[0]->typeId,
                $entries[0]->typeName,
                $entries[0]->quantity,
                $entries[0]->severity,
                $entries[0]->impactIsk,
                $entries[0]->jitaUnitPrice,
                $entries[0]->breakEvenPerUnit,
            ],
        );
    }

    public function testProjectWithAPriceAlertAndNothingElseToDoStillAppearsInTheQueue(): void
    {
        $completedBatch = $this->ownedProject('Ishtar batch', ProjectStatus::Completed);
        $this->memberOfProjects($completedBatch);
        $this->priceAlertReader->method('of')->willReturn(new ProjectPriceAlert(self::ISHTAR_TYPE_ID, 'Ishtar', 2, 90_000_000.0, 100_000_000.0));

        self::assertSame([['Ishtar batch', 'price_below_break_even', false]], $this->queueSummary());
    }

    public function testJobWithAnUnknownRunCountKeepsAnUnknownQuantityInsteadOfZero(): void
    {
        $ishtarBatch = $this->ownedProject('Ishtar batch', ProjectStatus::InProgress);
        $this->jobOf($ishtarBatch, self::ISHTAR_TYPE_ID, 'Ishtar', 'final', null, 2, 0);
        $this->memberOfProjects($ishtarBatch);

        self::assertSame(
            [['launch_job', self::ISHTAR_TYPE_ID, null]],
            array_map(static fn (IndustryActionResource $entry): array => [$entry->code, $entry->typeId, $entry->quantity], $this->provide()),
        );
    }

    public function testBlockedMaterialWithoutJitaPriceKeepsAnUnknownImpactInsteadOfZero(): void
    {
        $ishtarBatch = $this->ownedProject('Ishtar batch', ProjectStatus::InProgress);
        $this->materialOf($ishtarBatch, self::TRITANIUM_TYPE_ID, 'Tritanium', 40_000, 0);
        $this->memberOfProjects($ishtarBatch);

        self::assertSame(
            [['source_material', 'warning', null]],
            array_map(static fn (IndustryActionResource $entry): array => [$entry->code, $entry->severity, $entry->impactIsk], $this->provide()),
        );
    }

    public function testJobWithAnUnknownRunCountIsUnknownSeverity(): void
    {
        $ishtarBatch = $this->ownedProject('Ishtar batch', ProjectStatus::InProgress);
        $this->jobOf($ishtarBatch, self::ISHTAR_TYPE_ID, 'Ishtar', 'final', null, 2, 0);
        $this->memberOfProjects($ishtarBatch);

        self::assertSame(
            [['launch_job', 'unknown', null]],
            array_map(static fn (IndustryActionResource $entry): array => [$entry->code, $entry->severity, $entry->impactIsk], $this->provide()),
        );
    }

    public function testJobWithAnUnknownJobGroupFailsTheWholeQueueInsteadOfBeingSkipped(): void
    {
        $healthyBatch = $this->ownedProject('Sabre batch', ProjectStatus::Draft);
        $faultyBatch = $this->ownedProject('Faulty batch', ProjectStatus::InProgress);
        $this->jobOf($faultyBatch, self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 2, 2, 0);
        $this->jobOf($faultyBatch, self::HECATE_TYPE_ID, 'Hecate', 'mystery', 1, 1, 0);
        $this->memberOfProjects($healthyBatch, $faultyBatch);

        try {
            $this->provide();
            self::fail('The queue must not be returned when a job group is unknown');
        } catch (\Throwable $failure) {
            self::assertSame(
                [true, true],
                [
                    str_contains($failure->getMessage(), 'Unknown job group "mystery"'),
                    str_contains($failure->getMessage(), 'Faulty batch') || str_contains($failure->getMessage(), $faultyBatch->getId()->toRfc4122()),
                ],
            );
        }
    }

    private function ownedProject(?string $name, ProjectStatus $status): IndustryProject
    {
        $project = $this->workshopProject($name, $status, $this->viewer);
        $this->ownerMembershipOf($project);

        return $project;
    }

    private function ownerMembershipOf(IndustryProject $project): IndustryProjectParticipant
    {
        foreach ($project->getMembers() as $membership) {
            if ($membership->getUser() === $this->viewer) {
                return $membership;
            }
        }

        return $this->membershipOf($project, $this->viewer, ParticipantRole::Owner, ParticipantStatus::Accepted);
    }

    private function memberOfProjects(IndustryProject ...$projects): void
    {
        $this->memberRepository->method('findAcceptedWithProjectsByUser')->willReturn(
            array_map(fn (IndustryProject $project): IndustryProjectParticipant => $this->ownerMembershipOf($project), $projects),
        );
    }

    private function queueSummary(): array
    {
        return array_map(
            static fn (IndustryActionResource $entry): array => [$entry->projectName, $entry->code, $entry->blocked],
            $this->provide(),
        );
    }

    private function provide(): array
    {
        return (new IndustryActionsProvider(
            $this->security,
            $this->memberRepository,
            new ProjectCockpitInputFactory(),
            new ProjectCockpitCalculator(),
            new ActionQueueSorter(),
            new ActionAssessor($this->marketPrices),
            $this->priceAlertReader,
        ))->provide(new GetCollection());
    }
}
