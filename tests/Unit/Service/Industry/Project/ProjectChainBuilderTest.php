<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Service\Industry\Project\ProjectChain;
use App\Service\Industry\Project\ProjectChainBuilder;
use App\Service\Industry\Project\ProjectChainNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectChainBuilder::class)]
#[Group('issue-337')]
final class ProjectChainBuilderTest extends TestCase
{
    private const int HAMMERHEAD_II = 337_002;
    private const int HAMMERHEAD_I = 337_001;
    private const int PARTICLE_ACCELERATOR_UNIT = 337_003;
    private const int TRITANIUM = 34;
    private const int ORPHAN_COMPONENT = 337_009;
    private const int TYPE_WITHOUT_JOB = 337_099;

    private const string PRODUCT_LINE_ID = '01970000-0000-7000-8000-000000000001';
    private const string COMPONENT_LINE_ID = '01970000-0000-7000-8000-000000000002';
    private const string COPY_LINE_ID = '01970000-0000-7000-8000-000000000003';
    private const string INVENTION_LINE_ID = '01970000-0000-7000-8000-000000000004';
    private const string TRITANIUM_LINE_ID = '01970000-0000-7000-8000-000000000005';
    private const string PARTICLE_ACCELERATOR_UNIT_LINE_ID = '01970000-0000-7000-8000-000000000006';
    private const string SECOND_COMPONENT_LINE_ID = '01970000-0000-7000-8000-000000000000';
    private const string ORPHAN_COMPONENT_LINE_ID = '01970000-0000-7000-8000-000000000007';
    private const string SALE_NODE_ID = 'sale';

    private const string OSTINGELE_RAITARU_ID = '01970000-0000-7000-8000-0000000000a1';
    private const string DELETED_STRUCTURE_ID = '01970000-0000-7000-8000-0000000000a2';
    private const string OSTINGELE_RAITARU_NAME = 'Ostingele - Raitaru of the Hammerheads';

    private const int PRODUCT_RUNS = 50;
    private const int COMPONENT_RUNS = 50;
    private const int TEN_RUNS = 10;

    private IndustryProject $project;
    private IndustryProjectParticipant $ownerParticipant;
    private IndustryProjectParticipant $uriel;
    private IndustryProjectParticipant $vesna;
    private IndustryProjectBomLine $product;
    private IndustryProjectBomLine $component;
    private IndustryProjectBomLine $copy;
    private IndustryProjectBomLine $invention;
    private IndustryProjectBomLine $tritanium;
    private IndustryProjectBomLine $particleAcceleratorUnit;

    private int $nextEsiJobId = 6_337_000_001;

    protected function setUp(): void
    {
        $owner = $this->pilot('Aria Solberg');
        $this->project = (new IndustryProject())->setOwner($owner)->setStatus(ProjectStatus::InProgress);
        $this->ownerParticipant = $this->participant($owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->uriel = $this->participant($this->pilot('Uriel Kade'), ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->vesna = $this->participant($this->pilot('Vesna Moor'), ParticipantRole::Member, ParticipantStatus::Accepted);

        $this->product = $this->jobLine(self::PRODUCT_LINE_ID, self::HAMMERHEAD_II, 'Hammerhead II', 'final', 'manufacturing', self::PRODUCT_RUNS, 2, 4, null);
        $this->component = $this->jobLine(self::COMPONENT_LINE_ID, self::HAMMERHEAD_I, 'Hammerhead I', 'component', 'manufacturing', self::COMPONENT_RUNS, 10, 20, self::HAMMERHEAD_II);
        $this->copy = $this->jobLine(self::COPY_LINE_ID, self::HAMMERHEAD_I, 'Hammerhead I', 'blueprint', 'copy', 20, null, null, self::HAMMERHEAD_II);
        $this->invention = $this->jobLine(self::INVENTION_LINE_ID, self::HAMMERHEAD_II, 'Hammerhead II', 'blueprint', 'invention', 20, null, null, null);
        $this->tritanium = $this->materialLine(self::TRITANIUM_LINE_ID, self::TRITANIUM, 'Tritanium', 1_000, 0);
        $this->particleAcceleratorUnit = $this->materialLine(self::PARTICLE_ACCELERATOR_UNIT_LINE_ID, self::PARTICLE_ACCELERATOR_UNIT, 'Particle Accelerator Unit', 4_000, 0);
    }

    public function testProjectWithoutLinksNorContributionsHasItsProductBlockedByItsComponentAndNothingPaid(): void
    {
        $chain = $this->chainOfHammerheads();

        self::assertSame(
            ['blocked', 'todo', 'todo', 'todo', 'todo'],
            [
                $this->node($chain, self::PRODUCT_LINE_ID)->status,
                $this->node($chain, self::COMPONENT_LINE_ID)->status,
                $this->node($chain, self::TRITANIUM_LINE_ID)->status,
                $this->node($chain, self::PARTICLE_ACCELERATOR_UNIT_LINE_ID)->status,
                $this->node($chain, self::SALE_NODE_ID)->status,
            ],
        );
        self::assertSame(
            array_fill(0, 7, ['none', null]),
            array_map(static fn (ProjectChainNode $node): array => [$node->costState, $node->realCost], $chain->nodes),
        );
    }

    public function testComponentWhoseDeliveredJobCoversItsRunsIsDoneWithItsContributedCostAndNoLongerBlocksTheProduct(): void
    {
        $this->link($this->component, 'delivered', self::COMPONENT_RUNS);

        $chain = $this->chainOfHammerheads([
            $this->contribution($this->ownerParticipant, $this->component, ContributionType::JobInstall, self::COMPONENT_RUNS, 214_000.0, ContributionStatus::Approved),
        ]);

        $component = $this->node($chain, self::COMPONENT_LINE_ID);
        self::assertSame(['done', 214_000.0, 'known'], [$component->status, $component->realCost, $component->costState]);
        self::assertSame('todo', $this->node($chain, self::PRODUCT_LINE_ID)->status);
    }

    public function testProductWithTwoOfItsFiveJobsDeliveredAndTheOthersRunningIsActiveWithItsFiveJobs(): void
    {
        foreach (['delivered', 'delivered', 'active', 'paused', 'ready'] as $status) {
            $this->link($this->product, $status, self::TEN_RUNS);
        }

        $product = $this->node($this->chainOfHammerheads(), self::PRODUCT_LINE_ID);

        self::assertSame(['active', 5], [$product->status, \count($product->jobs)]);
    }

    public function testProductWhoseFiveDeliveredJobsCoverItsRunsIsDone(): void
    {
        for ($job = 0; $job < 5; ++$job) {
            $this->link($this->product, 'delivered', self::TEN_RUNS);
        }

        self::assertSame('done', $this->node($this->chainOfHammerheads(), self::PRODUCT_LINE_ID)->status);
    }

    public function testCancelledJobOfTheProductIsLeftOutOfItsJobsAndCountsNoRunTowardsItsDelivery(): void
    {
        for ($job = 0; $job < 4; ++$job) {
            $this->link($this->product, 'delivered', self::TEN_RUNS);
        }
        $cancelledJobId = $this->link($this->product, 'cancelled', self::TEN_RUNS)->getEsiJobId();

        $product = $this->node($this->chainOfHammerheads(), self::PRODUCT_LINE_ID);

        self::assertSame('active', $product->status);
        self::assertNotContains($cancelledJobId, array_column($product->jobs, 'esiJobId'));
        self::assertCount(4, $product->jobs);
    }

    public function testProductWithOnlyCancelledAndRevertedJobsIsStillBlockedByItsComponentAndListsNoJob(): void
    {
        $this->link($this->product, 'cancelled', self::TEN_RUNS);
        $this->link($this->product, 'reverted', self::TEN_RUNS);

        $product = $this->node($this->chainOfHammerheads(), self::PRODUCT_LINE_ID);

        self::assertSame(['blocked', []], [$product->status, $product->jobs]);
    }

    public function testProductWithAJobOfAnUnexpectedStatusIsUnknownAndNeverTodo(): void
    {
        $this->link($this->product, 'delivered', self::TEN_RUNS);
        $this->link($this->product, 'weird', self::TEN_RUNS);

        self::assertSame('unknown', $this->node($this->chainOfHammerheads(), self::PRODUCT_LINE_ID)->status);
    }

    public function testApprovedContributionOfUnknownValueMakesTheRealCostOfTheMaterialUnknownWithBothSuppliersListed(): void
    {
        $chain = $this->chainOfHammerheads([
            $this->contribution($this->uriel, $this->particleAcceleratorUnit, ContributionType::Material, 2_000, 100_000.0, ContributionStatus::Approved),
            $this->contribution($this->vesna, $this->particleAcceleratorUnit, ContributionType::Material, 1_500, null, ContributionStatus::Approved),
        ]);

        $particleAcceleratorUnit = $this->node($chain, self::PARTICLE_ACCELERATOR_UNIT_LINE_ID);
        self::assertSame(['unknown', null], [$particleAcceleratorUnit->costState, $particleAcceleratorUnit->realCost]);
        self::assertSame(
            [
                ['participantId' => $this->uriel->getId()->toRfc4122(), 'characterName' => 'Uriel Kade', 'type' => 'material', 'quantity' => 2_000],
                ['participantId' => $this->vesna->getId()->toRfc4122(), 'characterName' => 'Vesna Moor', 'type' => 'material', 'quantity' => 1_500],
            ],
            $this->suppliersByCharacterName($particleAcceleratorUnit),
        );
    }

    public function testMaterialWithOnlyPendingContributionsHasNoCostAndNoSupplier(): void
    {
        $chain = $this->chainOfHammerheads([
            $this->contribution($this->uriel, $this->particleAcceleratorUnit, ContributionType::Material, 2_000, 100_000.0, ContributionStatus::Pending),
        ]);

        $particleAcceleratorUnit = $this->node($chain, self::PARTICLE_ACCELERATOR_UNIT_LINE_ID);
        self::assertSame(['none', null, []], [$particleAcceleratorUnit->costState, $particleAcceleratorUnit->realCost, $particleAcceleratorUnit->suppliers]);
    }

    public function testPendingAndRejectedContributionsCountNeitherInTheRealCostNorInTheSuppliers(): void
    {
        $chain = $this->chainOfHammerheads([
            $this->contribution($this->uriel, $this->tritanium, ContributionType::Material, 600, 3_300.0, ContributionStatus::Approved),
            $this->contribution($this->vesna, $this->tritanium, ContributionType::Material, 250, 1_400.0, ContributionStatus::Pending),
            $this->contribution($this->ownerParticipant, $this->tritanium, ContributionType::Material, 150, 900.0, ContributionStatus::Rejected),
        ]);

        $tritanium = $this->node($chain, self::TRITANIUM_LINE_ID);
        self::assertSame(['known', 3_300.0], [$tritanium->costState, $tritanium->realCost]);
        self::assertSame(['Uriel Kade'], array_column($tritanium->suppliers, 'characterName'));
    }

    public function testApprovedContributionsOfOneParticipantAndOneTypeFormOneSupplierWithTheirSummedQuantity(): void
    {
        $chain = $this->chainOfHammerheads([
            $this->contribution($this->uriel, $this->tritanium, ContributionType::Material, 300, 1_650.0, ContributionStatus::Approved),
            $this->contribution($this->uriel, $this->tritanium, ContributionType::Material, 200, 1_120.0, ContributionStatus::Approved),
        ]);

        $tritanium = $this->node($chain, self::TRITANIUM_LINE_ID);
        self::assertSame(2_770.0, $tritanium->realCost);
        self::assertSame(
            [['participantId' => $this->uriel->getId()->toRfc4122(), 'characterName' => 'Uriel Kade', 'type' => 'material', 'quantity' => 500]],
            $tritanium->suppliers,
        );
    }

    public function testRemovedParticipantStaysASupplierOfTheApprovedContributionsCountedInTheCost(): void
    {
        $this->vesna->setStatus(ParticipantStatus::Removed);

        $chain = $this->chainOfHammerheads([
            $this->contribution($this->vesna, $this->tritanium, ContributionType::Material, 700, 3_850.0, ContributionStatus::Approved),
        ]);

        $tritanium = $this->node($chain, self::TRITANIUM_LINE_ID);
        self::assertSame([3_850.0, ['Vesna Moor']], [$tritanium->realCost, array_column($tritanium->suppliers, 'characterName')]);
    }

    public function testMaterialPartlyDeliveredIsActiveWithItsRequiredAndFulfilledQuantities(): void
    {
        $this->tritanium->setFulfilledQuantity(400);

        $tritanium = $this->node($this->chainOfHammerheads(), self::TRITANIUM_LINE_ID);

        self::assertSame(['active', 1_000, 400], [$tritanium->status, $tritanium->requiredQuantity, $tritanium->fulfilledQuantity]);
    }

    public function testMaterialFullyDeliveredIsDone(): void
    {
        $this->particleAcceleratorUnit->setFulfilledQuantity(4_000);

        self::assertSame('done', $this->node($this->chainOfHammerheads(), self::PARTICLE_ACCELERATOR_UNIT_LINE_ID)->status);
    }

    public function testJobLineAlsoBoughtAsAMaterialSumsBothContributionsButKeepsTheStatusOfItsJobs(): void
    {
        $this->component->setFulfilledQuantity(self::COMPONENT_RUNS);

        $chain = $this->chainOfHammerheads([
            $this->contribution($this->uriel, $this->component, ContributionType::Material, self::COMPONENT_RUNS, 1_250_000.0, ContributionStatus::Approved),
            $this->contribution($this->ownerParticipant, $this->component, ContributionType::JobInstall, self::COMPONENT_RUNS, 214_000.0, ContributionStatus::Approved),
        ]);

        $component = $this->node($chain, self::COMPONENT_LINE_ID);
        self::assertSame(['todo', 1_464_000.0, 'known', 2], [$component->status, $component->realCost, $component->costState, \count($component->suppliers)]);
    }

    public function testJobNodeCarriesItsActivityRunsEfficiencyAndQuantities(): void
    {
        $product = $this->node($this->chainOfHammerheads(), self::PRODUCT_LINE_ID);

        self::assertSame(
            ['product', 'manufacturing', self::HAMMERHEAD_II, 'Hammerhead II', self::PRODUCT_RUNS, 2, 4, self::PRODUCT_RUNS, 0, null, null],
            [
                $product->stage, $product->activity, $product->typeId, $product->typeName, $product->runs, $product->meLevel, $product->teLevel,
                $product->requiredQuantity, $product->fulfilledQuantity, $product->soldQuantity, $product->grossRevenue,
            ],
        );
    }

    public function testMaterialNodeHasNoActivityRunsEfficiencyJobsNorStructures(): void
    {
        $tritanium = $this->node($this->chainOfHammerheads(), self::TRITANIUM_LINE_ID);

        self::assertSame(
            ['material', null, null, null, null, [], [], null],
            [$tritanium->stage, $tritanium->activity, $tritanium->runs, $tritanium->meLevel, $tritanium->teLevel, $tritanium->jobs, $tritanium->structures, $tritanium->plannedStructure],
        );
    }

    public function testJobOfTheJobTreeCarriesItsEsiIdentifierStatusRunsPilotEndCostAndFacility(): void
    {
        $this->link($this->component, 'active', 25, 'Ostingele - Sotiyo', 'Uriel Kade', '2026-11-08 14:30:00', 107_000.0);

        self::assertSame(
            [[
                'esiJobId' => 6_337_000_001,
                'status' => 'active',
                'runs' => 25,
                'characterName' => 'Uriel Kade',
                'endDate' => '2026-11-08T14:30:00+00:00',
                'cost' => 107_000.0,
                'facilityName' => 'Ostingele - Sotiyo',
            ]],
            $this->node($this->chainOfHammerheads(), self::COMPONENT_LINE_ID)->jobs,
        );
    }

    public function testStructuresOfAJobAreTheDistinctFacilityNamesOfItsJobs(): void
    {
        $this->link($this->product, 'delivered', self::TEN_RUNS, 'Ostingele - Sotiyo');
        $this->link($this->product, 'active', self::TEN_RUNS, 'Jita IV - Azbel');
        $this->link($this->product, 'active', self::TEN_RUNS, 'Ostingele - Sotiyo');
        $this->link($this->product, 'ready', self::TEN_RUNS);

        $structures = $this->node($this->chainOfHammerheads(), self::PRODUCT_LINE_ID)->structures;

        self::assertEqualsCanonicalizing(['Ostingele - Sotiyo', 'Jita IV - Azbel'], $structures);
        self::assertCount(2, $structures);
    }

    public function testJobWithoutFacilityNameHasNoStructureButKeepsItsPlannedStructureNamedFromTheOwnerConfiguration(): void
    {
        $this->link($this->product, 'active', self::TEN_RUNS);
        $this->product->setPlannedStructureId(self::OSTINGELE_RAITARU_ID)->setPlannedStructureStatus('best_in_favorite_system');

        $product = $this->node($this->chainOfHammerheads(structureNamesById: [self::OSTINGELE_RAITARU_ID => self::OSTINGELE_RAITARU_NAME]), self::PRODUCT_LINE_ID);

        self::assertSame(
            [[], ['name' => self::OSTINGELE_RAITARU_NAME, 'status' => 'best_in_favorite_system']],
            [$product->structures, $product->plannedStructure],
        );
    }

    public function testPlannedStructureWhoseConfigurationWasDeletedKeepsItsStatusWithoutName(): void
    {
        $this->component->setPlannedStructureId(self::DELETED_STRUCTURE_ID)->setPlannedStructureStatus('best_outside_favorite_system');

        $component = $this->node($this->chainOfHammerheads(structureNamesById: [self::OSTINGELE_RAITARU_ID => self::OSTINGELE_RAITARU_NAME]), self::COMPONENT_LINE_ID);

        self::assertSame(['name' => null, 'status' => 'best_outside_favorite_system'], $component->plannedStructure);
    }

    public function testLineWithoutPlannedStructureAndCopyLineHaveNoPlannedStructure(): void
    {
        $chain = $this->chainOfHammerheads(structureNamesById: [self::OSTINGELE_RAITARU_ID => self::OSTINGELE_RAITARU_NAME]);

        self::assertSame([null, null], [$this->node($chain, self::PRODUCT_LINE_ID)->plannedStructure, $this->node($chain, self::COPY_LINE_ID)->plannedStructure]);
    }

    public function testLinePlannedWithoutConfiguredStructureHasNoPlannedStructure(): void
    {
        $this->product->setPlannedStructureId(null)->setPlannedStructureStatus('not_configured');

        self::assertNull($this->node($this->chainOfHammerheads(), self::PRODUCT_LINE_ID)->plannedStructure);
    }

    public function testPlannedStructureStatusOutsideTheListIsAnError(): void
    {
        $this->product->setPlannedStructureId(self::OSTINGELE_RAITARU_ID)->setPlannedStructureStatus('best_guess');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('best_guess');

        $this->chainOfHammerheads(structureNamesById: [self::OSTINGELE_RAITARU_ID => self::OSTINGELE_RAITARU_NAME]);
    }

    public function testCopyAndInventionHangUnderTheJobTheyFeedAndComponentUnderTheProductWhileProductAndMaterialsAreRoots(): void
    {
        $chain = $this->chainOfHammerheads();

        self::assertSame(
            [self::COMPONENT_LINE_ID, self::PRODUCT_LINE_ID, self::PRODUCT_LINE_ID, null, null, null],
            [
                $this->node($chain, self::COPY_LINE_ID)->parentId,
                $this->node($chain, self::INVENTION_LINE_ID)->parentId,
                $this->node($chain, self::COMPONENT_LINE_ID)->parentId,
                $this->node($chain, self::PRODUCT_LINE_ID)->parentId,
                $this->node($chain, self::TRITANIUM_LINE_ID)->parentId,
                $this->node($chain, self::SALE_NODE_ID)->parentId,
            ],
        );
    }

    public function testComponentWhoseParentTypeHasNoJobInTheBomHasNoParent(): void
    {
        $orphan = $this->jobLine(self::ORPHAN_COMPONENT_LINE_ID, self::ORPHAN_COMPONENT, 'Orphan Component', 'component', 'reaction', 3, 0, 0, self::TYPE_WITHOUT_JOB);

        $chain = $this->chainOf([...$this->hammerheadLines(), $orphan]);

        self::assertNull($this->node($chain, self::ORPHAN_COMPONENT_LINE_ID)->parentId);
    }

    public function testNodesAreOrderedByStageThenTypeNameThenIdentifierWithTheSaleLast(): void
    {
        $secondComponent = $this->jobLine(self::SECOND_COMPONENT_LINE_ID, self::HAMMERHEAD_I, 'Hammerhead I', 'component', 'manufacturing', 30, 10, 20, self::HAMMERHEAD_II);

        $chain = $this->chainOf([$this->product, $this->tritanium, $this->component, $secondComponent, $this->invention, $this->particleAcceleratorUnit, $this->copy]);

        self::assertSame(
            [
                ['blueprint', self::COPY_LINE_ID],
                ['blueprint', self::INVENTION_LINE_ID],
                ['material', self::PARTICLE_ACCELERATOR_UNIT_LINE_ID],
                ['material', self::TRITANIUM_LINE_ID],
                ['component', self::SECOND_COMPONENT_LINE_ID],
                ['component', self::COMPONENT_LINE_ID],
                ['product', self::PRODUCT_LINE_ID],
                ['sale', self::SALE_NODE_ID],
            ],
            array_map(static fn (ProjectChainNode $node): array => [$node->stage, $node->id], $chain->nodes),
        );
    }

    public function testProjectWithoutBomLineHasOnlyItsSaleNodeToDo(): void
    {
        $chain = $this->chainOf([]);

        self::assertSame(
            [[self::SALE_NODE_ID, 'sale', 'todo', 0, 0.0]],
            array_map(static fn (ProjectChainNode $node): array => [$node->id, $node->stage, $node->status, $node->soldQuantity, $node->grossRevenue], $chain->nodes),
        );
    }

    public function testSaleNodeSumsTheQuantitiesAndRevenueOfTheSalesAndIsActive(): void
    {
        $sale = $this->node($this->chainOfHammerheads(sales: [$this->sale(3, 700_000.0), $this->sale(2, 750_000.0)]), self::SALE_NODE_ID);

        self::assertSame([5, 3_600_000.0, 'active'], [$sale->soldQuantity, $sale->grossRevenue, $sale->status]);
    }

    public function testSaleNodeOfACompletedProjectIsDone(): void
    {
        $this->project->setStatus(ProjectStatus::Completed);

        $sale = $this->node($this->chainOfHammerheads(sales: [$this->sale(3, 700_000.0), $this->sale(2, 750_000.0)]), self::SALE_NODE_ID);

        self::assertSame('done', $sale->status);
    }

    public function testComponentWhoseMaterialsAreFullyFulfilledIsToLaunch(): void
    {
        $this->tritanium->setFulfilledQuantity(1_000);

        $chain = $this->chainWithNeeds($this->hammerheadLines(), $this->hammerheadNeeds());

        self::assertSame('todo', $this->node($chain, self::COMPONENT_LINE_ID)->status);
    }

    public function testComponentMissingOneUnitOfItsMaterialsIsMissingMaterialsWhileTheProductStaysBlocked(): void
    {
        $this->tritanium->setFulfilledQuantity(999);

        $chain = $this->chainWithNeeds($this->hammerheadLines(), $this->hammerheadNeeds());

        self::assertSame(
            ['missing_materials', 'blocked'],
            [$this->node($chain, self::COMPONENT_LINE_ID)->status, $this->node($chain, self::PRODUCT_LINE_ID)->status],
        );
    }

    public function testProductWithItsComponentsDoneAndItsOwnMaterialsMissingIsMissingMaterials(): void
    {
        $this->link($this->component, 'delivered', self::COMPONENT_RUNS);
        $this->particleAcceleratorUnit->setFulfilledQuantity(3_999);

        $chain = $this->chainWithNeeds($this->hammerheadLines(), [
            self::PRODUCT_LINE_ID => [self::PARTICLE_ACCELERATOR_UNIT => 4_000],
            self::COMPONENT_LINE_ID => [],
        ]);

        self::assertSame('missing_materials', $this->node($chain, self::PRODUCT_LINE_ID)->status);
    }

    public function testJobWithItsSubComponentsUnfinishedStaysBlockedWhateverItsMaterials(): void
    {
        $chain = $this->chainWithNeeds($this->hammerheadLines(), [
            self::PRODUCT_LINE_ID => [self::PARTICLE_ACCELERATOR_UNIT => 4_000],
            self::COMPONENT_LINE_ID => [],
        ]);

        self::assertSame('blocked', $this->node($chain, self::PRODUCT_LINE_ID)->status);
    }

    public function testComponentAlreadyRunningStaysActiveWhenItsMaterialsAreMissing(): void
    {
        $this->link($this->component, 'active', self::COMPONENT_RUNS);

        $chain = $this->chainWithNeeds($this->hammerheadLines(), $this->hammerheadNeeds());

        self::assertSame('active', $this->node($chain, self::COMPONENT_LINE_ID)->status);
    }

    public function testJobsThatWouldBeToLaunchAreUnknownWhenThePlanCouldNotBeComputedButCopyAndInventionAreNot(): void
    {
        $chain = $this->chainWithNeeds($this->hammerheadLines(), null);

        self::assertSame(
            ['unknown', 'blocked', 'todo', 'todo'],
            [
                $this->node($chain, self::COMPONENT_LINE_ID)->status,
                $this->node($chain, self::PRODUCT_LINE_ID)->status,
                $this->node($chain, self::COPY_LINE_ID)->status,
                $this->node($chain, self::INVENTION_LINE_ID)->status,
            ],
        );
    }

    public function testJobAbsentFromTheNeedsIsUnknown(): void
    {
        $chain = $this->chainWithNeeds($this->hammerheadLines(), [self::PRODUCT_LINE_ID => []]);

        self::assertSame('unknown', $this->node($chain, self::COMPONENT_LINE_ID)->status);
    }

    public function testNodesCarryTheManufacturingLevelOfTheirJobAndTheLevelOfTheFirstConsumerOfTheirMaterial(): void
    {
        $chain = $this->chainWithNeeds($this->hammerheadLines(), [
            self::PRODUCT_LINE_ID => [self::PARTICLE_ACCELERATOR_UNIT => 4_000, self::TRITANIUM => 10],
            self::COMPONENT_LINE_ID => [self::TRITANIUM => 1_000],
        ]);

        self::assertEquals(
            [self::COMPONENT_LINE_ID => 1, self::PRODUCT_LINE_ID => 2, self::COPY_LINE_ID => null, self::INVENTION_LINE_ID => null, self::TRITANIUM_LINE_ID => 1, self::PARTICLE_ACCELERATOR_UNIT_LINE_ID => 2, self::SALE_NODE_ID => null],
            $this->levelsByNodeId($chain),
        );
    }

    public function testMaterialsHaveNoLevelWhenThePlanCouldNotBeComputed(): void
    {
        $chain = $this->chainWithNeeds($this->hammerheadLines(), null);

        self::assertNull($this->node($chain, self::TRITANIUM_LINE_ID)->level);
    }

    public function testComponentColumnIsSortedByStatusThenLevelThenName(): void
    {
        $product = $this->jobLine(self::PRODUCT_LINE_ID, self::HAMMERHEAD_II, 'Hammerhead II', 'final', 'manufacturing', 10, 2, 4, null);
        $alpha = $this->jobLine('01970000-0000-7000-8000-0000000000b1', 449_011, 'Alpha', 'component', 'manufacturing', 10, 10, 20, self::HAMMERHEAD_II);
        $bravo = $this->jobLine('01970000-0000-7000-8000-0000000000b2', 449_012, 'Bravo', 'component', 'manufacturing', 10, 10, 20, self::HAMMERHEAD_II);
        $charlie = $this->jobLine('01970000-0000-7000-8000-0000000000b3', 449_013, 'Charlie', 'component', 'manufacturing', 10, 10, 20, self::HAMMERHEAD_II);
        $delta = $this->jobLine('01970000-0000-7000-8000-0000000000b4', 449_014, 'Delta', 'component', 'manufacturing', 10, 10, 20, self::HAMMERHEAD_II);
        $echo = $this->jobLine('01970000-0000-7000-8000-0000000000b5', 449_015, 'Echo', 'component', 'manufacturing', 10, 10, 20, 449_014);
        $foxtrot = $this->jobLine('01970000-0000-7000-8000-0000000000b6', 449_016, 'Foxtrot', 'component', 'manufacturing', 10, 10, 20, self::HAMMERHEAD_II);
        $golf = $this->jobLine('01970000-0000-7000-8000-0000000000b7', 449_017, 'Golf', 'component', 'manufacturing', 10, 10, 20, self::HAMMERHEAD_II);
        $hotel = $this->jobLine('01970000-0000-7000-8000-0000000000b8', 449_018, 'Hotel', 'component', 'manufacturing', 10, 10, 20, 449_017);
        $india = $this->jobLine('01970000-0000-7000-8000-0000000000b9', 449_019, 'India', 'component', 'manufacturing', 10, 10, 20, self::HAMMERHEAD_II);
        $this->link($alpha, 'active', 10);
        $this->link($foxtrot, 'delivered', 10);
        $this->link($hotel, 'delivered', 10);
        $this->link($india, 'unrecognised', 10);
        $lines = [$product, $alpha, $bravo, $charlie, $delta, $echo, $foxtrot, $golf, $hotel, $india, $this->tritanium];
        $needs = $this->noMaterialNeedsOf($lines);
        $needs[self::lineIdOf($bravo)] = [self::TRITANIUM => 100];

        $chain = $this->chainWithNeeds($lines, $needs);

        self::assertSame(
            ['Charlie', 'Echo', 'Golf', 'Bravo', 'India', 'Alpha', 'Delta', 'Foxtrot', 'Hotel'],
            $this->typeNamesOfStage($chain, 'component'),
        );
    }

    public function testMaterialColumnIsSortedByFulfilmentThenConsumerLevelThenRemainingShareThenName(): void
    {
        $product = $this->jobLine(self::PRODUCT_LINE_ID, self::HAMMERHEAD_II, 'Hammerhead II', 'final', 'manufacturing', 10, 2, 4, null);
        $component = $this->jobLine(self::COMPONENT_LINE_ID, self::HAMMERHEAD_I, 'Hammerhead I', 'component', 'manufacturing', 10, 10, 20, self::HAMMERHEAD_II);
        $materials = [
            ...array_map(fn (array $spec): IndustryProjectBomLine => $this->materialLine(...$spec), [
                ['01970000-0000-7000-8000-0000000000c1', 449_021, 'Material A', 100, 0],
                ['01970000-0000-7000-8000-0000000000c2', 449_022, 'Material B', 100, 30],
                ['01970000-0000-7000-8000-0000000000c3', 449_023, 'Material C', 100, 10],
                ['01970000-0000-7000-8000-0000000000c4', 449_024, 'Material D', 100, 40],
                ['01970000-0000-7000-8000-0000000000c5', 449_025, 'Material E', 100, 0],
                ['01970000-0000-7000-8000-0000000000c6', 449_026, 'Material F', 100, 20],
                ['01970000-0000-7000-8000-0000000000c7', 449_027, 'Material G', 50, 0],
                ['01970000-0000-7000-8000-0000000000c8', 449_028, 'Material H', 100, 100],
            ]),
        ];

        $chain = $this->chainWithNeeds([$product, $component, ...array_reverse($materials)], [
            self::PRODUCT_LINE_ID => [449_021 => 100, 449_026 => 100, 449_028 => 100],
            self::COMPONENT_LINE_ID => [449_022 => 100, 449_023 => 100, 449_024 => 100, 449_025 => 100],
        ]);

        self::assertSame(
            ['Material E', 'Material A', 'Material G', 'Material C', 'Material B', 'Material D', 'Material F', 'Material H'],
            $this->typeNamesOfStage($chain, 'material'),
        );
    }

    public function testMaterialColumnFallsBackToTheRemainingShareWhenThePlanCouldNotBeComputed(): void
    {
        $lines = [
            $this->materialLine('01970000-0000-7000-8000-0000000000d1', 449_031, 'Material A', 100, 0),
            $this->materialLine('01970000-0000-7000-8000-0000000000d2', 449_032, 'Material B', 100, 80),
            $this->materialLine('01970000-0000-7000-8000-0000000000d3', 449_033, 'Material C', 100, 10),
        ];

        $chain = $this->chainWithNeeds($lines, null);

        self::assertSame(['Material A', 'Material C', 'Material B'], $this->typeNamesOfStage($chain, 'material'));
    }

    private function hammerheadNeeds(): array
    {
        return [
            self::PRODUCT_LINE_ID => [],
            self::COMPONENT_LINE_ID => [self::TRITANIUM => 1_000],
        ];
    }

    private function levelsByNodeId(ProjectChain $chain): array
    {
        $levels = [];
        foreach ($chain->nodes as $node) {
            $levels[$node->id] = $node->level;
        }

        return $levels;
    }

    private function typeNamesOfStage(ProjectChain $chain, string $stage): array
    {
        return array_values(array_map(
            static fn (ProjectChainNode $node): ?string => $node->typeName,
            array_filter($chain->nodes, static fn (ProjectChainNode $node): bool => $node->stage === $stage),
        ));
    }

    private static function lineIdOf(IndustryProjectBomLine $line): string
    {
        return $line->getId()->toRfc4122();
    }

    private function chainOfHammerheads(array $contributions = [], array $sales = [], array $structureNamesById = []): ProjectChain
    {
        return $this->chainOf($this->hammerheadLines(), $contributions, $sales, $structureNamesById);
    }

    private function chainOf(array $bomLines, array $contributions = [], array $sales = [], array $structureNamesById = []): ProjectChain
    {
        return $this->chainWithNeeds($bomLines, $this->noMaterialNeedsOf($bomLines), $contributions, $sales, $structureNamesById);
    }

    private function chainWithNeeds(array $bomLines, ?array $jobMaterialNeeds, array $contributions = [], array $sales = [], array $structureNamesById = []): ProjectChain
    {
        return (new ProjectChainBuilder())->build($this->project, $bomLines, $contributions, $sales, $structureNamesById, $jobMaterialNeeds);
    }

    private function noMaterialNeedsOf(array $bomLines): array
    {
        $needs = [];
        foreach ($bomLines as $line) {
            if ($line->isJob() && 'blueprint' !== $line->getJobGroup()) {
                $needs[$line->getId()->toRfc4122()] = [];
            }
        }

        return $needs;
    }

    private function hammerheadLines(): array
    {
        return [$this->product, $this->component, $this->copy, $this->invention, $this->tritanium, $this->particleAcceleratorUnit];
    }

    private function node(ProjectChain $chain, string $id): ProjectChainNode
    {
        foreach ($chain->nodes as $node) {
            if ($node->id === $id) {
                return $node;
            }
        }

        self::fail(\sprintf('The chain has no node %s.', $id));
    }

    private function suppliersByCharacterName(ProjectChainNode $node): array
    {
        $suppliers = $node->suppliers;
        usort($suppliers, static fn (array $left, array $right): int => $left['characterName'] <=> $right['characterName']);

        return $suppliers;
    }

    private function pilot(string $name): User
    {
        $character = (new Character())->setName($name);
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);

        return $user;
    }

    private function participant(User $user, ParticipantRole $role, ParticipantStatus $status): IndustryProjectParticipant
    {
        $participant = (new IndustryProjectParticipant())->setUser($user)->setRole($role)->setStatus($status);
        $this->project->addMember($participant);

        return $participant;
    }

    private function jobLine(string $id, int $typeId, string $typeName, string $jobGroup, string $activityType, int $runs, ?int $meLevel, ?int $teLevel, ?int $parentTypeId): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity($runs)
            ->setIsJob(true)
            ->setJobGroup($jobGroup)
            ->setActivityType($activityType)
            ->setRuns($runs)
            ->setMeLevel($meLevel)
            ->setTeLevel($teLevel)
            ->setParentTypeId($parentTypeId);

        return $this->withIdentifier($line, $id);
    }

    private function materialLine(string $id, int $typeId, string $typeName, int $requiredQuantity, int $fulfilledQuantity): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity($requiredQuantity)
            ->setFulfilledQuantity($fulfilledQuantity)
            ->setIsJob(false);

        return $this->withIdentifier($line, $id);
    }

    private function withIdentifier(IndustryProjectBomLine $line, string $id): IndustryProjectBomLine
    {
        (new \ReflectionProperty(IndustryProjectBomLine::class, 'id'))->setValue($line, Uuid::fromString($id));
        $line->setProject($this->project);

        return $line;
    }

    private function link(
        IndustryProjectBomLine $line,
        string $status,
        int $runs,
        ?string $facilityName = null,
        string $characterName = 'Aria Solberg',
        string $endDate = '2026-11-10 09:00:00',
        ?float $cost = 42_000.0,
    ): IndustryProjectJobMatch {
        $jobMatch = (new IndustryProjectJobMatch())
            ->setEsiJobId($this->nextEsiJobId++)
            ->setStatus($status)
            ->setRuns($runs)
            ->setFacilityName($facilityName)
            ->setCharacterName($characterName)
            ->setEndDate(new \DateTimeImmutable($endDate, new \DateTimeZone('UTC')))
            ->setCost($cost);
        $line->addJobMatch($jobMatch);

        return $jobMatch;
    }

    private function contribution(IndustryProjectParticipant $participant, IndustryProjectBomLine $line, ContributionType $type, int $quantity, ?float $value, ContributionStatus $status): IndustryProjectContribution
    {
        return (new IndustryProjectContribution())
            ->setProject($this->project)
            ->setMember($participant)
            ->setBomItem($line)
            ->setType($type)
            ->setQuantity($quantity)
            ->setEstimatedValue($value)
            ->setStatus($status);
    }

    private function sale(int $quantity, float $unitPrice): IndustryProjectSale
    {
        return (new IndustryProjectSale())
            ->setProject($this->project)
            ->setTypeId(self::HAMMERHEAD_II)
            ->setTypeName('Hammerhead II')
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setTotalPrice($quantity * $unitPrice);
    }
}
