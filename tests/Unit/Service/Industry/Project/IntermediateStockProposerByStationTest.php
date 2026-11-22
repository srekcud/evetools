<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Service\Industry\Project\ChainRecipeIngredients;
use App\Service\Industry\Project\IntermediateStockProposalLine;
use App\Service\Industry\Project\IntermediateStockProposer;
use App\Service\Industry\Project\ProjectContainerService;
use App\Service\Industry\Project\ProjectContainerStock;
use App\Tests\Unit\Service\Industry\Stock\StagesCorporationAssets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(IntermediateStockProposer::class)]
#[Group('issue-1166')]
final class IntermediateStockProposerByStationTest extends TestCase
{
    use BuildsChainBomLines;
    use StagesCorporationAssets;

    private const int STATION_JITA = 100;
    private const int STATION_ISHTAR = 200;
    private const int FINAL_PRODUCT = 16_673;
    private const int HYDROGEN_FUEL_BLOCK = 4_246;
    private const int FERNITE_ALLOY = 16_656;
    private const int CERAMIC_POWDER = 16_660;
    private const int NITROGEN_FUEL_BLOCK = 4_051;
    private const int ROBOTICS = 11_542;
    private const int CRYSTALLINE_CARBONIDE = 1_153_001;

    private User $owner;
    private IndustryProject $project;
    private IntermediateStockProposer $proposer;
    private ?ProjectContainerStock $projectContainerStock = null;
    private array $ownerStructureConfigs = [];

    protected function setUp(): void
    {
        $this->stageCorporationAssets();
        $this->connectDirector();
        $this->owner = $this->userInTheCorporation();
        $this->project = (new IndustryProject())->setOwner($this->owner)->setStatus(ProjectStatus::Draft);

        $assetRepository = $this->createStub(CachedAssetRepository::class);
        $assetRepository->method('hasPersonalAssetsOf')->willReturn(true);

        $containerService = $this->createStub(ProjectContainerService::class);
        $containerService->method('containerStockOf')->willReturnCallback(fn (): ProjectContainerStock => $this->projectContainerStock ?? new ProjectContainerStock('no_container_name'));

        $chainRecipeIngredients = $this->createStub(ChainRecipeIngredients::class);
        $chainRecipeIngredients->method('producedIngredientsOf')->willReturnCallback(
            fn (array $productTypeIds): array => (new InMemoryChainRecipeIngredients([]))->producedIngredientsOf($productTypeIds),
        );

        $structureConfigRepository = $this->createStub(IndustryStructureConfigRepository::class);
        $structureConfigRepository->method('findByUser')->willReturnCallback(fn (): array => $this->ownerStructureConfigs);

        $this->proposer = new IntermediateStockProposer(
            $assetRepository,
            $containerService,
            $this->createStub(IndustryProjectContributionRepository::class),
            $chainRecipeIngredients,
            $this->personalStockReader,
            $structureConfigRepository,
        );
    }

    public function testStationOfThePlanComesFirstAndIsOnSiteWhileOtherStationsAreOffSite(): void
    {
        $ishtar = $this->ownerStructure('Ishtar', self::STATION_ISHTAR);
        $line = $this->component(self::HYDROGEN_FUEL_BLOCK, 'Hydrogen Fuel Block', 100, plannedStructure: $ishtar);
        $this->hangarStock(self::HYDROGEN_FUEL_BLOCK, [
            [self::STATION_JITA, 'Jita IV - Moon 4', 'Jita', 40],
            [self::STATION_ISHTAR, 'Ishtar Citadel', 'Gamis', 15],
        ]);

        $proposalLine = $this->theOnlyLine();

        self::assertSame(self::lineId($line), $proposalLine->bomItemId);
        self::assertSame('Ishtar', $proposalLine->plannedStationName);
        self::assertSame(
            [
                [self::STATION_ISHTAR, 'Ishtar Citadel', 'Gamis', 15, true],
                [self::STATION_JITA, 'Jita IV - Moon 4', 'Jita', 40, false],
            ],
            $this->stationsOf($proposalLine),
        );
        self::assertSame([55, null, 55, 55], [$proposalLine->personalQuantity, $proposalLine->containerQuantity, $proposalLine->stockQuantity, $proposalLine->proposedQuantity]);
    }

    public function testNothingAtTheStationOfThePlanLeavesOnlyOffSiteEntriesAndKeepsTheStationName(): void
    {
        $ishtar = $this->ownerStructure('Ishtar', self::STATION_ISHTAR);
        $this->component(self::FERNITE_ALLOY, 'Fernite Alloy', 90, plannedStructure: $ishtar);
        $this->hangarStock(self::FERNITE_ALLOY, [
            [self::STATION_JITA, 'Jita IV - Moon 4', 'Jita', 33],
        ]);

        $proposalLine = $this->theOnlyLine();

        self::assertSame('Ishtar', $proposalLine->plannedStationName);
        self::assertSame([[self::STATION_JITA, 'Jita IV - Moon 4', 'Jita', 33, false]], $this->stationsOf($proposalLine));
        self::assertSame([33, 33, 33], [$proposalLine->personalQuantity, $proposalLine->stockQuantity, $proposalLine->proposedQuantity]);
    }

    public function testLineWithoutPlannedStructureHasNoStationNameAndNoOnSiteFlagInTheOrderOfTheReader(): void
    {
        $this->ownerStructure('Ishtar', self::STATION_ISHTAR);
        $this->component(self::CERAMIC_POWDER, 'Ceramic Powder', 80);
        $this->hangarStock(self::CERAMIC_POWDER, [
            [self::STATION_ISHTAR, 'Ishtar Citadel', 'Gamis', 12],
            [self::STATION_JITA, 'Jita IV - Moon 4', 'Jita', 45],
        ]);

        $proposalLine = $this->theOnlyLine();

        self::assertNull($proposalLine->plannedStationName);
        self::assertSame(
            [
                [self::STATION_JITA, 'Jita IV - Moon 4', 'Jita', 45, null],
                [self::STATION_ISHTAR, 'Ishtar Citadel', 'Gamis', 12, null],
            ],
            $this->stationsOf($proposalLine),
        );
    }

    public function testStructureWithoutLocationIdGivesNoStationNameAndNoOnSiteFlag(): void
    {
        $unlocated = $this->ownerStructure('Unlocated', null);
        $this->component(self::NITROGEN_FUEL_BLOCK, 'Nitrogen Fuel Block', 70, plannedStructure: $unlocated);
        $this->hangarStock(self::NITROGEN_FUEL_BLOCK, [
            [self::STATION_JITA, 'Jita IV - Moon 4', 'Jita', 28],
            [self::STATION_ISHTAR, 'Ishtar Citadel', 'Gamis', 9],
        ]);

        $proposalLine = $this->theOnlyLine();

        self::assertNull($proposalLine->plannedStationName);
        self::assertSame(
            [
                [self::STATION_JITA, 'Jita IV - Moon 4', 'Jita', 28, null],
                [self::STATION_ISHTAR, 'Ishtar Citadel', 'Gamis', 9, null],
            ],
            $this->stationsOf($proposalLine),
        );
    }

    public function testStructureOfAnotherUserGivesNoStationNameAndNoOnSiteFlag(): void
    {
        $this->ownerStructure('Ishtar', self::STATION_ISHTAR);
        $this->component(self::ROBOTICS, 'Robotics', 60, plannedStructure: Uuid::v7()->toRfc4122());
        $this->hangarStock(self::ROBOTICS, [
            [self::STATION_JITA, 'Jita IV - Moon 4', 'Jita', 21],
            [self::STATION_ISHTAR, 'Ishtar Citadel', 'Gamis', 6],
        ]);

        $proposalLine = $this->theOnlyLine();

        self::assertNull($proposalLine->plannedStationName);
        self::assertSame(
            [
                [self::STATION_JITA, 'Jita IV - Moon 4', 'Jita', 21, null],
                [self::STATION_ISHTAR, 'Ishtar Citadel', 'Gamis', 6, null],
            ],
            $this->stationsOf($proposalLine),
        );
    }

    public function testTypeWithoutPersonalStockButWithProjectContainerStockKeepsItsProposalAndHasNoStationEntry(): void
    {
        $ishtar = $this->ownerStructure('Ishtar', self::STATION_ISHTAR);
        $this->component(self::CRYSTALLINE_CARBONIDE, 'Crystalline Carbonide', 50, plannedStructure: $ishtar);
        $this->project->setContainerNames(['Build 1166']);
        $this->projectContainerStock = new ProjectContainerStock(null, [self::CRYSTALLINE_CARBONIDE => 26]);

        $proposalLine = $this->theOnlyLine();

        self::assertSame('Ishtar', $proposalLine->plannedStationName);
        self::assertSame([], $proposalLine->stockByStation);
        self::assertSame([0, 26, 26, 26], [$proposalLine->personalQuantity, $proposalLine->containerQuantity, $proposalLine->stockQuantity, $proposalLine->proposedQuantity]);
    }

    public function testDeclaredPersonalContainerAtTheStationOfThePlanCountsOnSite(): void
    {
        $ishtar = $this->ownerStructure('Ishtar', self::STATION_ISHTAR);
        $this->component(self::HYDROGEN_FUEL_BLOCK, 'Hydrogen Fuel Block', 100, plannedStructure: $ishtar);
        $this->hangarStock(self::HYDROGEN_FUEL_BLOCK, [
            [self::STATION_JITA, 'Jita IV - Moon 4', 'Jita', 40],
        ]);
        $officeAtIshtar = $this->cacheOffice(self::STATION_ISHTAR, 'Ishtar Citadel', 'Gamis');
        $persoA = $this->cacheContainer('Perso A', $officeAtIshtar);
        $this->cacheItem(self::HYDROGEN_FUEL_BLOCK, 12, $persoA, 'Hydrogen Fuel Block');
        $this->declareContainer($this->owner, 'Perso A', $persoA, 'Division 2', self::STATION_ISHTAR, 'Ishtar Citadel');

        $proposalLine = $this->theOnlyLine();

        self::assertSame('Ishtar', $proposalLine->plannedStationName);
        self::assertSame(
            [
                [self::STATION_ISHTAR, 'Ishtar Citadel', 'Gamis', 12, true],
                [self::STATION_JITA, 'Jita IV - Moon 4', 'Jita', 40, false],
            ],
            $this->stationsOf($proposalLine),
        );
        self::assertSame(52, $proposalLine->personalQuantity);
    }

    private function ownerStructure(string $name, ?int $locationId): string
    {
        $config = (new IndustryStructureConfig())->setName($name)->setLocationId($locationId);
        $this->ownerStructureConfigs[] = $config;

        return $config->getId()->toRfc4122();
    }

    private function hangarStock(int $typeId, array $stations): void
    {
        $locations = array_map(
            static fn (array $station): array => ['locationId' => $station[0], 'locationName' => $station[1], 'systemName' => $station[2], 'quantity' => $station[3]],
            $stations,
        );
        usort($locations, static fn (array $left, array $right): int => $right['quantity'] <=> $left['quantity']);
        $total = array_sum(array_column($locations, 'quantity'));

        $this->hangarQuantities[$typeId] = $total;
        $this->hangarLocations[$typeId] = ['total' => $total, 'locations' => $locations];
    }

    private function component(int $typeId, string $typeName, int $requiredQuantity, ?string $plannedStructure = null): IndustryProjectBomLine
    {
        $line = $this->jobLine($typeId, $typeName, 'component', self::FINAL_PRODUCT, 10)
            ->setRequiredQuantity($requiredQuantity)
            ->setFulfilledQuantity(0)
            ->setPlannedStructureId($plannedStructure);
        $this->project->addBomItem($line);

        return $line;
    }

    private function theOnlyLine(): IntermediateStockProposalLine
    {
        $lines = $this->proposer->proposalOf($this->project, $this->owner)->lines;
        self::assertCount(1, $lines);

        return $lines[0];
    }

    private function stationsOf(IntermediateStockProposalLine $line): array
    {
        return array_map(
            static fn ($station): array => [$station->locationId, $station->locationName, $station->systemName, $station->quantity, $station->onSite],
            $line->stockByStation,
        );
    }
}
