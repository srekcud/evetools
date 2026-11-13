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
use App\Service\Industry\Project\IntermediateStockProposal;
use App\Service\Industry\Project\IntermediateStockProposalLine;
use App\Service\Industry\Project\IntermediateStockProposer;
use App\Service\Industry\Project\ProjectContainerService;
use App\Service\Industry\Project\ProjectContainerStock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IntermediateStockProposer::class)]
#[Group('issue-708')]
final class IntermediateStockProposerTest extends TestCase
{
    use BuildsChainBomLines;

    private const string CONTAINER_NAME = 'Build 708';

    private const int FINAL_PRODUCT = 16_673;
    private const int HYDROGEN_FUEL_BLOCK = 4_246;
    private const int FERNITE_ALLOY = 16_656;
    private const int CERAMIC_POWDER = 16_660;
    private const int NITROGEN_FUEL_BLOCK = 4_051;
    private const int ROBOTICS = 11_542;

    private User $owner;
    private IndustryProject $project;
    private IntermediateStockProposer $proposer;

    private bool $personalAssetsSynced = true;
    private array $personalQuantities = [];
    private ProjectContainerStock $containerStock;
    private array $approvedStockByTypeId = [];
    private array $contributedBomItemIds = [];

    protected function setUp(): void
    {
        $this->owner = new User();
        $this->project = (new IndustryProject())->setOwner($this->owner)->setStatus(ProjectStatus::Draft);
        $this->containerStock = new ProjectContainerStock('no_container_name');

        $assetRepository = $this->createStub(CachedAssetRepository::class);
        $assetRepository->method('hasPersonalAssetsOf')->willReturnCallback(fn (): bool => $this->personalAssetsSynced);
        $assetRepository->method('getAggregatedQuantitiesByUser')->willReturnCallback(fn (): array => $this->personalQuantities);

        $containerService = $this->createStub(ProjectContainerService::class);
        $containerService->method('containerStockOf')->willReturnCallback(fn (): ProjectContainerStock => $this->containerStock);

        $contributionRepository = $this->createStub(IndustryProjectContributionRepository::class);
        $contributionRepository->method('findIntermediateStockByTypeId')->willReturnCallback(fn (): array => $this->approvedStockByTypeId);
        $contributionRepository->method('findBomItemIdsWithMaterialContributionOf')->willReturnCallback(fn (): array => $this->contributedBomItemIds);

        $this->proposer = new IntermediateStockProposer($assetRepository, $containerService, $contributionRepository);
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

    public function testGroupProjectIsNotSoloWhateverTheAssets(): void
    {
        $this->component(self::HYDROGEN_FUEL_BLOCK, 'Hydrogen Fuel Block', 100);
        $this->personalQuantities = [self::HYDROGEN_FUEL_BLOCK => 30];
        $this->project->addMember(
            (new IndustryProjectParticipant())->setUser(new User())->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Accepted),
        );

        $proposal = $this->proposalOf();

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

        self::assertSame(
            ['Robotics', 'Ceramic Powder', 'Nitrogen Fuel Block'],
            array_map(static fn (IntermediateStockProposalLine $line): string => $line->typeName, $this->proposalOf()->lines),
        );
        self::assertSame(self::lineId($robotics), $this->proposalOf()->lines[0]->bomItemId);
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

    private function proposalOf(): IntermediateStockProposal
    {
        return $this->proposer->proposalOf($this->project);
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
