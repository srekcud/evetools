<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Service\Industry\Project\ChainRecipeIngredients;
use App\Service\Industry\Project\IntermediateStockProposal;
use App\Service\Industry\Project\IntermediateStockProposalLine;
use App\Service\Industry\Project\IntermediateStockProposer;
use App\Service\Industry\Project\ProjectContainerService;
use App\Service\Industry\Project\ProjectContainerStock;
use App\Tests\Unit\Support\StubsPersonalStockReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IntermediateStockProposer::class)]
#[Group('issue-708')]
final class IntermediateStockProposerTest extends TestCase
{
    use BuildsChainBomLines;
    use StubsPersonalStockReader;

    private const string CONTAINER_NAME = 'Build 708';

    private const int FINAL_PRODUCT = 16_673;
    private const int HYDROGEN_FUEL_BLOCK = 4_246;
    private const int FERNITE_ALLOY = 16_656;
    private const int CERAMIC_POWDER = 16_660;
    private const int NITROGEN_FUEL_BLOCK = 4_051;
    private const int ROBOTICS = 11_542;
    private const int CRYSTALLINE_CARBONIDE = 1_153_001;
    private const int PHOTONIC_METAMATERIALS = 1_153_002;
    private const int CRYSTALLITE_ALLOY = 1_153_003;
    private const int CARBON_POLYMERS = 1_153_004;

    private User $owner;
    private User $member;
    private IndustryProject $project;
    private IntermediateStockProposer $proposer;

    private bool $personalAssetsSynced = true;
    private array $personalQuantities = [];
    private array $memberPersonalQuantities = [];
    private array $approvedStockOfMemberByTypeId = [];
    private ProjectContainerStock $containerStock;
    private array $approvedStockByTypeId = [];
    private array $contributedBomItemIds = [];
    private array $producedIngredientsByTypeId = [];

    protected function setUp(): void
    {
        $this->owner = new User();
        $this->member = new User();
        $this->project = (new IndustryProject())->setOwner($this->owner)->setStatus(ProjectStatus::Draft);
        $this->containerStock = new ProjectContainerStock('no_container_name');

        $assetRepository = $this->createStub(CachedAssetRepository::class);
        $assetRepository->method('hasPersonalAssetsOf')->willReturnCallback(fn (): bool => $this->personalAssetsSynced);
        $assetRepository->method('getAggregatedQuantitiesByUser')->willReturnCallback(fn (User $user): array => $user === $this->member ? $this->memberPersonalQuantities : $this->personalQuantities);

        $containerService = $this->createStub(ProjectContainerService::class);
        $containerService->method('containerStockOf')->willReturnCallback(fn (): ProjectContainerStock => $this->containerStock);

        $contributionRepository = $this->createStub(IndustryProjectContributionRepository::class);
        $contributionRepository->method('findIntermediateStockByTypeId')->willReturnCallback(fn (IndustryProject $project, ?User $contributor = null): array => $contributor === $this->member ? $this->approvedStockOfMemberByTypeId : $this->approvedStockByTypeId);
        $contributionRepository->method('findBomItemIdsWithMaterialContributionOf')->willReturnCallback(fn (): array => $this->contributedBomItemIds);

        $chainRecipeIngredients = $this->createStub(ChainRecipeIngredients::class);
        $chainRecipeIngredients->method('producedIngredientsOf')->willReturnCallback(
            fn (array $productTypeIds): array => (new InMemoryChainRecipeIngredients($this->producedIngredientsByTypeId))->producedIngredientsOf($productTypeIds),
        );

        $structureConfigRepository = $this->createStub(IndustryStructureConfigRepository::class);
        $structureConfigRepository->method('findByUser')->willReturn([]);

        $this->proposer = new IntermediateStockProposer($assetRepository, $containerService, $contributionRepository, $chainRecipeIngredients, $this->personalStockReaderOn($assetRepository), $structureConfigRepository);
    }

    public function testPersonalStockAloneIsProposedForTheRemainingQuantityWithoutContainer(): void
    {
        $hydrogenFuelBlock = $this->component(self::HYDROGEN_FUEL_BLOCK, 'Hydrogen Fuel Block', 100);
        $this->personalQuantities = [self::HYDROGEN_FUEL_BLOCK => 30];

        $proposal = $this->proposalOf();

        self::assertSame(['ready', null], [$proposal->state, $proposal->unknownReason]);
        self::assertSame(
            [[self::lineId($hydrogenFuelBlock), self::HYDROGEN_FUEL_BLOCK, 'Hydrogen Fuel Block', 100, 100, 30, null, 30, 30]],
            $this->linesOf($proposal),
        );
    }

    public function testPersonalAndContainerStockAddUp(): void
    {
        $fernite = $this->component(self::FERNITE_ALLOY, 'Fernite Alloy', 100);
        $this->project->setContainerNames([self::CONTAINER_NAME]);
        $this->personalQuantities = [self::FERNITE_ALLOY => 30];
        $this->containerStock = new ProjectContainerStock(null, [self::FERNITE_ALLOY => 50]);

        self::assertSame(
            [[self::lineId($fernite), self::FERNITE_ALLOY, 'Fernite Alloy', 100, 100, 30, 50, 80, 80]],
            $this->linesOf($this->proposalOf()),
        );
    }

    public function testProposedQuantityIsCappedByTheRemainingQuantityOfTheLine(): void
    {
        $ceramic = $this->component(self::CERAMIC_POWDER, 'Ceramic Powder', 120, 20);
        $this->project->setContainerNames([self::CONTAINER_NAME]);
        $this->personalQuantities = [self::CERAMIC_POWDER => 90];
        $this->containerStock = new ProjectContainerStock(null, [self::CERAMIC_POWDER => 60]);

        self::assertSame(
            [[self::lineId($ceramic), self::CERAMIC_POWDER, 'Ceramic Powder', 120, 100, 90, 60, 150, 100]],
            $this->linesOf($this->proposalOf()),
        );
    }

    public function testUnitsAlreadyDeclaredAsApprovedIntermediateStockAreNotCountedTwice(): void
    {
        $nitrogen = $this->component(self::NITROGEN_FUEL_BLOCK, 'Nitrogen Fuel Block', 110, 30);
        $this->personalQuantities = [self::NITROGEN_FUEL_BLOCK => 80];
        $this->approvedStockByTypeId = [self::NITROGEN_FUEL_BLOCK => 30];

        self::assertSame(
            [[self::lineId($nitrogen), self::NITROGEN_FUEL_BLOCK, 'Nitrogen Fuel Block', 110, 80, 80, null, 50, 50]],
            $this->linesOf($this->proposalOf()),
        );
    }

    public function testLineWhereTheOwnerAlreadyContributedMaterialIsNotListed(): void
    {
        $contributed = $this->component(self::HYDROGEN_FUEL_BLOCK, 'Hydrogen Fuel Block', 70);
        $witness = $this->component(self::FERNITE_ALLOY, 'Fernite Alloy', 40);
        $this->personalQuantities = [self::HYDROGEN_FUEL_BLOCK => 80, self::FERNITE_ALLOY => 25];
        $this->approvedStockByTypeId = [self::HYDROGEN_FUEL_BLOCK => 30];
        $this->contributedBomItemIds = [self::lineId($contributed)];

        self::assertSame([self::lineId($witness)], $this->bomItemIdsOf($this->proposalOf()));
    }

    public function testLineWithoutRemainingQuantityIsNotListed(): void
    {
        $this->component(self::HYDROGEN_FUEL_BLOCK, 'Hydrogen Fuel Block', 100, 100)->setRuns(0);
        $witness = $this->component(self::FERNITE_ALLOY, 'Fernite Alloy', 40);
        $this->personalQuantities = [self::HYDROGEN_FUEL_BLOCK => 60, self::FERNITE_ALLOY => 25];

        self::assertSame([self::lineId($witness)], $this->bomItemIdsOf($this->proposalOf()));
    }

    public function testFinalProductAndBlueprintLinesAreNeverListed(): void
    {
        $this->project->addBomItem($this->jobLine(self::FINAL_PRODUCT, 'Fernite Carbide', 'final', null, 10));
        $this->project->addBomItem($this->jobLine(self::FERNITE_ALLOY, 'Fernite Alloy Blueprint', 'blueprint', self::FINAL_PRODUCT, 2, 'copy'));
        $witness = $this->component(self::CERAMIC_POWDER, 'Ceramic Powder', 40);
        $this->personalQuantities = [self::FINAL_PRODUCT => 8, self::FERNITE_ALLOY => 5, self::CERAMIC_POWDER => 25];

        self::assertSame([self::lineId($witness)], $this->bomItemIdsOf($this->proposalOf()));
    }

    public function testTypeAbsentFromTheAssetsGivesAReadyProposalWithoutLine(): void
    {
        $this->component(self::HYDROGEN_FUEL_BLOCK, 'Hydrogen Fuel Block', 100);
        $this->personalQuantities = [self::ROBOTICS => 12];

        $proposal = $this->proposalOf();

        self::assertSame(['ready', null, []], [$proposal->state, $proposal->unknownReason, $proposal->lines]);
    }

    public function testPersonalAssetsNeverSyncedMakeTheProposalUnknownWithoutLines(): void
    {
        $this->component(self::HYDROGEN_FUEL_BLOCK, 'Hydrogen Fuel Block', 100);
        $this->personalAssetsSynced = false;
        $this->personalQuantities = [];

        $proposal = $this->proposalOf();

        self::assertSame(['assets_unknown', 'personal_assets_not_synced', []], [$proposal->state, $proposal->unknownReason, $proposal->lines]);
    }

    public function testNamedContainerThatCannotBeFoundMakesTheProposalUnknownWithoutLines(): void
    {
        $this->component(self::HYDROGEN_FUEL_BLOCK, 'Hydrogen Fuel Block', 100);
        $this->project->setContainerNames([self::CONTAINER_NAME]);
        $this->personalQuantities = [self::HYDROGEN_FUEL_BLOCK => 30];
        $this->containerStock = new ProjectContainerStock('container_not_found');

        $proposal = $this->proposalOf();

        self::assertSame(['assets_unknown', 'container_not_found', []], [$proposal->state, $proposal->unknownReason, $proposal->lines]);
    }

    public function testProjectWithoutContainerNameHasNoContainerQuantityAndIsNotUnknown(): void
    {
        $this->component(self::HYDROGEN_FUEL_BLOCK, 'Hydrogen Fuel Block', 100);
        $this->personalQuantities = [self::HYDROGEN_FUEL_BLOCK => 30];
        $this->containerStock = new ProjectContainerStock('no_container_name');

        $proposal = $this->proposalOf();

        self::assertSame(['ready', null, [null]], [$proposal->state, $proposal->unknownReason, array_map(static fn (IntermediateStockProposalLine $line): ?int => $line->containerQuantity, $proposal->lines)]);
    }

    #[Group('issue-1318')]
    public function testMemberOfAGroupProjectIsProposedTheirOwnStockWithoutTheContainerOfTheProject(): void
    {
        $photonic = $this->component(self::PHOTONIC_METAMATERIALS, 'Photonic Metamaterials', 300_000);
        $this->addAcceptedMember();
        $this->project->setContainerNames([self::CONTAINER_NAME]);
        $this->memberPersonalQuantities = [self::PHOTONIC_METAMATERIALS => 90_000];
        $this->containerStock = new ProjectContainerStock(null, [self::PHOTONIC_METAMATERIALS => 40_000]);

        $proposal = $this->proposalOf($this->member);

        self::assertSame(['ready', null], [$proposal->state, $proposal->unknownReason]);
        self::assertSame(
            [[self::lineId($photonic), self::PHOTONIC_METAMATERIALS, 'Photonic Metamaterials', 300_000, 300_000, 90_000, null, 90_000, 90_000]],
            $this->linesOf($proposal),
        );
    }

    #[Group('issue-1318')]
    public function testStockOfTheOwnerIsProposedToTheOwnerOnlyAndNeverToAMemberOfTheGroupProject(): void
    {
        $crystallite = $this->component(self::CRYSTALLITE_ALLOY, 'Crystallite Alloy', 80_000);
        $this->addAcceptedMember();
        $this->personalQuantities = [self::CRYSTALLITE_ALLOY => 50_000];

        $proposalOfTheMember = $this->proposalOf($this->member);
        $proposalOfTheOwner = $this->proposalOf($this->owner);

        self::assertSame(['ready', []], [$proposalOfTheMember->state, $proposalOfTheMember->lines]);
        self::assertSame('ready', $proposalOfTheOwner->state);
        self::assertSame(
            [[self::lineId($crystallite), self::CRYSTALLITE_ALLOY, 'Crystallite Alloy', 80_000, 80_000, 50_000, null, 50_000, 50_000]],
            $this->linesOf($proposalOfTheOwner),
        );
    }

    #[Group('issue-1318')]
    public function testSupplyApprovedForAnotherMemberOnlyReducesTheRemainingQuantityWhileTheOwnSupplyOfTheReaderIsDeductedFromTheirStock(): void
    {
        $photonic = $this->component(self::PHOTONIC_METAMATERIALS, 'Photonic Metamaterials', 300_000, 200_000);
        $crystallite = $this->component(self::CRYSTALLITE_ALLOY, 'Crystallite Alloy', 80_000, 25_000);
        $this->addAcceptedMember();
        $this->memberPersonalQuantities = [self::PHOTONIC_METAMATERIALS => 150_000, self::CRYSTALLITE_ALLOY => 60_000];
        $this->approvedStockByTypeId = [self::PHOTONIC_METAMATERIALS => 200_000, self::CRYSTALLITE_ALLOY => 35_000];
        $this->approvedStockOfMemberByTypeId = [self::CRYSTALLITE_ALLOY => 25_000];

        self::assertSame(
            [
                [self::lineId($crystallite), self::CRYSTALLITE_ALLOY, 'Crystallite Alloy', 80_000, 55_000, 60_000, null, 35_000, 35_000],
                [self::lineId($photonic), self::PHOTONIC_METAMATERIALS, 'Photonic Metamaterials', 300_000, 100_000, 150_000, null, 150_000, 100_000],
            ],
            $this->linesOf($this->proposalOf($this->member)),
        );
    }

    #[Group('issue-1318')]
    public function testCompletedGroupProjectIsStillNotSoloForAMember(): void
    {
        $this->component(self::HYDROGEN_FUEL_BLOCK, 'Hydrogen Fuel Block', 100);
        $this->addAcceptedMember();
        $this->memberPersonalQuantities = [self::HYDROGEN_FUEL_BLOCK => 30];
        $this->project->setStatus(ProjectStatus::Completed);

        $proposal = $this->proposalOf($this->member);

        self::assertSame(['not_solo', null, []], [$proposal->state, $proposal->unknownReason, $proposal->lines]);
    }

    public function testCompletedProjectIsNotSolo(): void
    {
        $this->component(self::HYDROGEN_FUEL_BLOCK, 'Hydrogen Fuel Block', 100);
        $this->personalQuantities = [self::HYDROGEN_FUEL_BLOCK => 30];
        $this->project->setStatus(ProjectStatus::Completed);

        $proposal = $this->proposalOf();

        self::assertSame(['not_solo', null, []], [$proposal->state, $proposal->unknownReason, $proposal->lines]);
    }

    public function testParentsComeBeforeTheirChildrenThenLinesAreSortedByTypeName(): void
    {
        $this->project->addBomItem($this->jobLine(self::FINAL_PRODUCT, 'Fernite Carbide', 'final', null, 10));
        $robotics = $this->component(self::ROBOTICS, 'Robotics', 20, parentTypeId: self::FINAL_PRODUCT);
        $this->component(self::NITROGEN_FUEL_BLOCK, 'Nitrogen Fuel Block', 30, parentTypeId: self::ROBOTICS);
        $this->component(self::CERAMIC_POWDER, 'Ceramic Powder', 40, parentTypeId: self::ROBOTICS);
        $this->personalQuantities = [self::ROBOTICS => 5, self::NITROGEN_FUEL_BLOCK => 6, self::CERAMIC_POWDER => 7];
        $this->producedIngredientsByTypeId = [
            self::FINAL_PRODUCT => [self::ROBOTICS],
            self::ROBOTICS => [self::NITROGEN_FUEL_BLOCK, self::CERAMIC_POWDER],
        ];

        self::assertSame(
            ['Robotics', 'Ceramic Powder', 'Nitrogen Fuel Block'],
            array_map(static fn (IntermediateStockProposalLine $line): string => $line->typeName, $this->proposalOf()->lines),
        );
        self::assertSame(self::lineId($robotics), $this->proposalOf()->lines[0]->bomItemId);
    }

    #[Group('issue-1153')]
    public function testLinesAreSortedByTheLevelOfTheirRecipeNotByTheConsumerListedOnTheLine(): void
    {
        $this->component(self::CRYSTALLITE_ALLOY, 'Crystallite Alloy', 40, parentTypeId: self::PHOTONIC_METAMATERIALS);
        $this->component(self::CARBON_POLYMERS, 'Carbon Polymers', 60, parentTypeId: self::PHOTONIC_METAMATERIALS);
        $this->component(self::PHOTONIC_METAMATERIALS, 'Photonic Metamaterials', 20);
        $this->component(self::CRYSTALLINE_CARBONIDE, 'Crystalline Carbonide', 30);
        $this->personalQuantities = [
            self::CRYSTALLITE_ALLOY => 5,
            self::CARBON_POLYMERS => 6,
            self::PHOTONIC_METAMATERIALS => 7,
            self::CRYSTALLINE_CARBONIDE => 8,
        ];
        $this->producedIngredientsByTypeId = [
            self::PHOTONIC_METAMATERIALS => [self::CRYSTALLITE_ALLOY, self::CARBON_POLYMERS],
            self::CRYSTALLINE_CARBONIDE => [self::CRYSTALLITE_ALLOY, self::CARBON_POLYMERS],
        ];

        self::assertSame(
            ['Crystalline Carbonide', 'Photonic Metamaterials', 'Carbon Polymers', 'Crystallite Alloy'],
            array_map(static fn (IntermediateStockProposalLine $line): string => $line->typeName, $this->proposalOf()->lines),
        );
    }

    public function testSeveralLinesOfTheSameTypeGetASingleProposalOnTheLineWithTheLargestRequiredQuantity(): void
    {
        $this->component(self::FERNITE_ALLOY, 'Fernite Alloy', 60);
        $largest = $this->component(self::FERNITE_ALLOY, 'Fernite Alloy', 100);
        $this->personalQuantities = [self::FERNITE_ALLOY => 70];

        self::assertSame(
            [[self::lineId($largest), self::FERNITE_ALLOY, 'Fernite Alloy', 100, 100, 70, null, 70, 70]],
            $this->linesOf($this->proposalOf()),
        );
    }

    private function proposalOf(?User $reader = null): IntermediateStockProposal
    {
        return $this->proposer->proposalOf($this->project, $reader ?? $this->owner);
    }

    private function addAcceptedMember(): void
    {
        $this->project->addMember(
            (new IndustryProjectParticipant())->setUser($this->member)->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Accepted),
        );
    }

    private function component(int $typeId, string $typeName, int $requiredQuantity, int $fulfilledQuantity = 0, int $parentTypeId = self::FINAL_PRODUCT): IndustryProjectBomLine
    {
        $line = $this->jobLine($typeId, $typeName, 'component', $parentTypeId, 10)
            ->setRequiredQuantity($requiredQuantity)
            ->setFulfilledQuantity($fulfilledQuantity);
        $this->project->addBomItem($line);

        return $line;
    }

    private function linesOf(IntermediateStockProposal $proposal): array
    {
        return array_map(
            static fn (IntermediateStockProposalLine $line): array => [
                $line->bomItemId,
                $line->typeId,
                $line->typeName,
                $line->demandQuantity,
                $line->remainingQuantity,
                $line->personalQuantity,
                $line->containerQuantity,
                $line->stockQuantity,
                $line->proposedQuantity,
            ],
            $proposal->lines,
        );
    }

    private function bomItemIdsOf(IntermediateStockProposal $proposal): array
    {
        return array_map(static fn (IntermediateStockProposalLine $line): string => $line->bomItemId, $proposal->lines);
    }
}
