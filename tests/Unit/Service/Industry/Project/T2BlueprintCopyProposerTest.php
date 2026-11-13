<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectDeclaredBlueprintCopy;
use App\Entity\IndustryProjectParticipant;
use App\Entity\OwnedBlueprint;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Repository\OwnedBlueprintRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\InventionService;
use App\Service\Industry\Project\InventedProductsReader;
use App\Service\Industry\Project\ProjectContainerService;
use App\Service\Industry\Project\ProjectContainerStock;
use App\Service\Industry\Project\T2BlueprintCopyProposal;
use App\Service\Industry\Project\T2BlueprintCopyProposalLine;
use App\Service\Industry\Project\T2BlueprintCopyProposer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(T2BlueprintCopyProposer::class)]
#[Group('issue-804')]
final class T2BlueprintCopyProposerTest extends TestCase
{
    use BuildsChainBomLines;

    private const string CONTAINER_NAME = 'Build 804';
    private const int OWNER_CORPORATION_ID = 98_000_804;
    private const int BLUEPRINT_COPY_QUANTITY = -2;
    private const int BLUEPRINT_ORIGINAL_QUANTITY = -1;

    private const int ISHTAR = 12_005;
    private const int ISHTAR_BLUEPRINT = 12_006;
    private const int HAIL_L = 12_779;
    private const int HAIL_L_BLUEPRINT = 12_780;
    private const int RIFTER = 587;
    private const int RIFTER_BLUEPRINT = 786;

    private User $owner;
    private IndustryProject $project;
    private T2BlueprintCopyProposer $proposer;

    private array $personalBlueprints = [];
    private array $corporationBlueprints = [];
    private ProjectContainerStock $containerStock;
    private int $nextItemId = 1_080_400_000_001;

    protected function setUp(): void
    {
        $character = (new Character())->setEveCharacterId(2_112_804_001)->setName('Aria Solberg')->setCorporationId(self::OWNER_CORPORATION_ID);
        $this->owner = (new User())->addCharacter($character);
        $this->owner->setMainCharacter($character);
        $this->project = (new IndustryProject())->setOwner($this->owner)->setStatus(ProjectStatus::Draft);
        $this->containerStock = new ProjectContainerStock('no_container_name');

        $blueprintRepository = $this->createStub(OwnedBlueprintRepository::class);
        $blueprintRepository->method('findByCharacters')->willReturnCallback(fn (): array => $this->personalBlueprints);
        $blueprintRepository->method('findByCorporationId')->willReturnCallback(
            fn (int $corporationId): array => self::OWNER_CORPORATION_ID === $corporationId ? $this->corporationBlueprints : [],
        );

        $containerService = $this->createStub(ProjectContainerService::class);
        $containerService->method('containerStockOf')->willReturnCallback(fn (): ProjectContainerStock => $this->containerStock);

        $inventionService = $this->createStub(InventionService::class);
        $inventionService->method('getInventionData')->willReturnCallback(static fn (int $productTypeId): ?array => match ($productTypeId) {
            self::ISHTAR => ['t1BlueprintTypeId' => 11_000, 't2BlueprintTypeId' => self::ISHTAR_BLUEPRINT, 'probability' => 0.3, 'baseRuns' => 10, 'materials' => []],
            self::HAIL_L => ['t1BlueprintTypeId' => 898, 't2BlueprintTypeId' => self::HAIL_L_BLUEPRINT, 'probability' => 0.4, 'baseRuns' => 4, 'materials' => []],
            default => null,
        });

        $activityProductRepository = $this->createStub(IndustryActivityProductRepository::class);
        $activityProductRepository->method('producerOf')->willReturnCallback(static fn (int $productTypeId): IndustryActivityProduct => (new IndustryActivityProduct())
            ->setTypeId(match ($productTypeId) {
                self::ISHTAR => self::ISHTAR_BLUEPRINT,
                self::HAIL_L => self::HAIL_L_BLUEPRINT,
                self::RIFTER => self::RIFTER_BLUEPRINT,
            })
            ->setProductTypeId($productTypeId));

        $this->proposer = new T2BlueprintCopyProposer($blueprintRepository, $containerService, new InventedProductsReader($inventionService), $activityProductRepository);
    }

    public function testPersonalCopiesAndContainerCopiesAddUpToTheAvailableRunsAndTheProposalIsTheManufacturingRunsAtMost(): void
    {
        $this->manufacturing(self::ISHTAR, 'Ishtar', 15);
        $this->project->addBomItem($this->jobLine(self::ISHTAR, 'Ishtar', 'blueprint', null, 10, 'invention'));
        $this->project->setContainerName(self::CONTAINER_NAME);
        $this->personalBlueprints = [$this->personalCopy(self::ISHTAR_BLUEPRINT, 4), $this->personalCopy(self::ISHTAR_BLUEPRINT, 6)];
        $inContainer = $this->corporationCopy(self::ISHTAR_BLUEPRINT, 3);
        $this->containerStock = new ProjectContainerStock(null, [], [$inContainer->getItemId()]);

        $proposal = $this->proposalOf();

        self::assertSame(['ready', null, [[self::ISHTAR, 'Ishtar', 15, 10, 3, 13, 13, 0]]], [$proposal->state, $proposal->unknownReason, $this->linesOf($proposal)]);
    }

    public function testProposedRunsAreCappedByTheManufacturingRunsWhileTheAvailableRunsAreNot(): void
    {
        $this->manufacturing(self::HAIL_L, 'Hail L', 15);
        $this->personalBlueprints = [$this->personalCopy(self::HAIL_L_BLUEPRINT, 25), $this->personalCopy(self::HAIL_L_BLUEPRINT, 15)];

        self::assertSame([[self::HAIL_L, 'Hail L', 15, 40, null, 40, 15, 0]], $this->linesOf($this->proposalOf()));
    }

    public function testManufacturingRunsAddUpAcrossTheManufacturingLinesOfTheProduct(): void
    {
        $this->manufacturing(self::HAIL_L, 'Hail L', 10);
        $this->manufacturing(self::HAIL_L, 'Hail L', 7);
        $this->personalBlueprints = [$this->personalCopy(self::HAIL_L_BLUEPRINT, 30)];

        self::assertSame([[self::HAIL_L, 'Hail L', 17, 30, null, 30, 17, 0]], $this->linesOf($this->proposalOf()));
    }

    public function testOriginalBlueprintsAndCopiesOfOtherProductsAreNotCounted(): void
    {
        $this->manufacturing(self::ISHTAR, 'Ishtar', 15);
        $this->manufacturing(self::HAIL_L, 'Hail L', 15);
        $this->personalBlueprints = [
            $this->personalCopy(self::ISHTAR_BLUEPRINT, 7),
            $this->personalOriginal(self::ISHTAR_BLUEPRINT),
            $this->personalOriginal(self::HAIL_L_BLUEPRINT),
        ];

        self::assertSame([[self::ISHTAR, 'Ishtar', 15, 7, null, 7, 7, 0]], $this->linesOf($this->proposalOf()));
    }

    public function testCorporationCopyOutsideTheContainerOfTheProjectIsNotCounted(): void
    {
        $this->manufacturing(self::ISHTAR, 'Ishtar', 15);
        $this->project->setContainerName(self::CONTAINER_NAME);
        $this->personalBlueprints = [$this->personalCopy(self::ISHTAR_BLUEPRINT, 2)];
        $inContainer = $this->corporationCopy(self::ISHTAR_BLUEPRINT, 3);
        $this->corporationCopy(self::ISHTAR_BLUEPRINT, 9);
        $this->containerStock = new ProjectContainerStock(null, [], [$inContainer->getItemId()]);

        self::assertSame([[self::ISHTAR, 'Ishtar', 15, 2, 3, 5, 5, 0]], $this->linesOf($this->proposalOf()));
    }

    public function testContainerWithoutAnyCopyGivesZeroContainerRunsAndTheProposalIsNotUnknown(): void
    {
        $this->manufacturing(self::ISHTAR, 'Ishtar', 15);
        $this->project->setContainerName(self::CONTAINER_NAME);
        $this->personalBlueprints = [$this->personalCopy(self::ISHTAR_BLUEPRINT, 5)];
        $this->corporationCopy(self::ISHTAR_BLUEPRINT, 9);
        $this->containerStock = new ProjectContainerStock(null, [], []);

        $proposal = $this->proposalOf();

        self::assertSame(['ready', null, [[self::ISHTAR, 'Ishtar', 15, 5, 0, 5, 5, 0]]], [$proposal->state, $proposal->unknownReason, $this->linesOf($proposal)]);
    }

    public function testProjectWithoutContainerNameHasNoContainerRunsAndIgnoresTheCorporationCopies(): void
    {
        $this->manufacturing(self::ISHTAR, 'Ishtar', 15);
        $this->personalBlueprints = [$this->personalCopy(self::ISHTAR_BLUEPRINT, 5)];
        $corporationCopy = $this->corporationCopy(self::ISHTAR_BLUEPRINT, 9);
        $this->containerStock = new ProjectContainerStock('no_container_name', [], [$corporationCopy->getItemId()]);

        self::assertSame([[self::ISHTAR, 'Ishtar', 15, 5, null, 5, 5, 0]], $this->linesOf($this->proposalOf()));
    }

    public function testProductWithoutAvailableRunsIsNotListed(): void
    {
        $this->manufacturing(self::HAIL_L, 'Hail L', 15);
        $this->manufacturing(self::ISHTAR, 'Ishtar', 15);
        $this->personalBlueprints = [$this->personalCopy(self::ISHTAR_BLUEPRINT, 8)];

        self::assertSame([self::ISHTAR], array_column($this->linesOf($this->proposalOf()), 0));
    }

    public function testProductManufacturedWithoutInventionIsNeverListedEvenWithACopyOfItsBlueprint(): void
    {
        $this->manufacturing(self::RIFTER, 'Rifter', 20);
        $this->manufacturing(self::ISHTAR, 'Ishtar', 15);
        $this->personalBlueprints = [$this->personalCopy(self::RIFTER_BLUEPRINT, 20), $this->personalCopy(self::ISHTAR_BLUEPRINT, 8)];

        self::assertSame([self::ISHTAR], array_column($this->linesOf($this->proposalOf()), 0));
    }

    public function testLinesAreSortedByTypeName(): void
    {
        $this->manufacturing(self::ISHTAR, 'Ishtar', 15);
        $this->manufacturing(self::HAIL_L, 'Hail L', 15);
        $this->personalBlueprints = [$this->personalCopy(self::ISHTAR_BLUEPRINT, 8), $this->personalCopy(self::HAIL_L_BLUEPRINT, 9)];

        self::assertSame(['Hail L', 'Ishtar'], array_column($this->linesOf($this->proposalOf()), 1));
    }

    public function testDeclaredRunsAreReportedWithoutBeingSubtractedFromTheAvailableRuns(): void
    {
        $this->manufacturing(self::ISHTAR, 'Ishtar', 15);
        $this->personalBlueprints = [$this->personalCopy(self::ISHTAR_BLUEPRINT, 10)];
        $this->project->replaceDeclaredBlueprintCopies([new IndustryProjectDeclaredBlueprintCopy(self::ISHTAR, 6)]);

        self::assertSame([[self::ISHTAR, 'Ishtar', 15, 10, null, 10, 10, 6]], $this->linesOf($this->proposalOf()));
    }

    public function testProductFullyDeclaredIsStillListedAfterItsInventionLeftThePlan(): void
    {
        $this->manufacturing(self::ISHTAR, 'Ishtar', 15);
        $this->personalBlueprints = [$this->personalCopy(self::ISHTAR_BLUEPRINT, 15)];
        $this->project->replaceDeclaredBlueprintCopies([new IndustryProjectDeclaredBlueprintCopy(self::ISHTAR, 15)]);

        self::assertSame([[self::ISHTAR, 'Ishtar', 15, 15, null, 15, 15, 15]], $this->linesOf($this->proposalOf()));
    }

    public function testBlueprintsNeverSyncedMakeTheProposalUnknownWithoutLines(): void
    {
        $this->manufacturing(self::ISHTAR, 'Ishtar', 15);

        $proposal = $this->proposalOf();

        self::assertSame(['assets_unknown', 'blueprints_not_synced', []], [$proposal->state, $proposal->unknownReason, $proposal->lines]);
    }

    public function testBlueprintsNeverSyncedComeBeforeTheReasonOfTheContainer(): void
    {
        $this->manufacturing(self::ISHTAR, 'Ishtar', 15);
        $this->project->setContainerName(self::CONTAINER_NAME);
        $this->containerStock = new ProjectContainerStock('container_not_found');

        $proposal = $this->proposalOf();

        self::assertSame(['assets_unknown', 'blueprints_not_synced', []], [$proposal->state, $proposal->unknownReason, $proposal->lines]);
    }

    public function testNamedContainerThatCannotBeFoundMakesTheProposalUnknownWithoutLines(): void
    {
        $this->manufacturing(self::ISHTAR, 'Ishtar', 15);
        $this->project->setContainerName(self::CONTAINER_NAME);
        $this->personalBlueprints = [$this->personalCopy(self::ISHTAR_BLUEPRINT, 8)];
        $this->containerStock = new ProjectContainerStock('container_not_found');

        $proposal = $this->proposalOf();

        self::assertSame(['assets_unknown', 'container_not_found', []], [$proposal->state, $proposal->unknownReason, $proposal->lines]);
    }

    public function testOwnerWhoSyncedOnlyOriginalBlueprintsGetsAReadyProposalWithoutLine(): void
    {
        $this->manufacturing(self::ISHTAR, 'Ishtar', 15);
        $this->personalBlueprints = [$this->personalOriginal(self::ISHTAR_BLUEPRINT)];

        $proposal = $this->proposalOf();

        self::assertSame(['ready', null, []], [$proposal->state, $proposal->unknownReason, $proposal->lines]);
    }

    public function testGroupProjectIsNotSoloWhateverTheBlueprints(): void
    {
        $this->manufacturing(self::ISHTAR, 'Ishtar', 15);
        $this->personalBlueprints = [$this->personalCopy(self::ISHTAR_BLUEPRINT, 8)];
        $this->project->addMember(
            (new IndustryProjectParticipant())->setUser(new User())->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Accepted),
        );

        $proposal = $this->proposalOf();

        self::assertSame(['not_solo', null, []], [$proposal->state, $proposal->unknownReason, $proposal->lines]);
    }

    public function testCompletedProjectIsNotSolo(): void
    {
        $this->manufacturing(self::ISHTAR, 'Ishtar', 15);
        $this->personalBlueprints = [$this->personalCopy(self::ISHTAR_BLUEPRINT, 8)];
        $this->project->setStatus(ProjectStatus::Completed);

        $proposal = $this->proposalOf();

        self::assertSame(['not_solo', null, []], [$proposal->state, $proposal->unknownReason, $proposal->lines]);
    }

    #[Group('issue-806')]
    public function testProjectExcludingTheInventionHasAnInventionExcludedProposalWithoutLines(): void
    {
        $this->manufacturing(self::ISHTAR, 'Ishtar', 15);
        $this->personalBlueprints = [$this->personalCopy(self::ISHTAR_BLUEPRINT, 8)];
        $this->project->setExcludesInvention(true);

        $proposal = $this->proposalOf();

        self::assertSame(['invention_excluded', null, []], [$proposal->state, $proposal->unknownReason, $proposal->lines]);
    }

    #[Group('issue-806')]
    public function testGroupProjectExcludingTheInventionIsInventionExcludedRatherThanNotSolo(): void
    {
        $this->manufacturing(self::ISHTAR, 'Ishtar', 15);
        $this->project->setExcludesInvention(true);
        $this->project->addMember(
            (new IndustryProjectParticipant())->setUser(new User())->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Accepted),
        );

        $proposal = $this->proposalOf();

        self::assertSame(['invention_excluded', []], [$proposal->state, $proposal->lines]);
    }

    private function proposalOf(): T2BlueprintCopyProposal
    {
        return $this->proposer->proposalOf($this->project);
    }

    private function manufacturing(int $typeId, string $typeName, int $runs): void
    {
        $this->project->addBomItem($this->jobLine($typeId, $typeName, 'final', null, $runs));
    }

    private function personalCopy(int $blueprintTypeId, int $runs): OwnedBlueprint
    {
        return $this->blueprint($blueprintTypeId, self::BLUEPRINT_COPY_QUANTITY, $runs);
    }

    private function personalOriginal(int $blueprintTypeId): OwnedBlueprint
    {
        return $this->blueprint($blueprintTypeId, self::BLUEPRINT_ORIGINAL_QUANTITY, -1);
    }

    private function corporationCopy(int $blueprintTypeId, int $runs): OwnedBlueprint
    {
        $copy = $this->blueprint($blueprintTypeId, self::BLUEPRINT_COPY_QUANTITY, $runs)->setCorporationId(self::OWNER_CORPORATION_ID);
        $this->corporationBlueprints[] = $copy;

        return $copy;
    }

    private function blueprint(int $blueprintTypeId, int $quantity, int $runs): OwnedBlueprint
    {
        return (new OwnedBlueprint())
            ->setItemId($this->nextItemId++)
            ->setTypeId($blueprintTypeId)
            ->setQuantity($quantity)
            ->setRuns($runs);
    }

    private function linesOf(T2BlueprintCopyProposal $proposal): array
    {
        return array_map(
            static fn (T2BlueprintCopyProposalLine $line): array => [
                $line->productTypeId,
                $line->typeName,
                $line->manufacturingRuns,
                $line->personalRuns,
                $line->containerRuns,
                $line->availableRuns,
                $line->proposedRuns,
                $line->declaredRuns,
            ],
            $proposal->lines,
        );
    }
}
