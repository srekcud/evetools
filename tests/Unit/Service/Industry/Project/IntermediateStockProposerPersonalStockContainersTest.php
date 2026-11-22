<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Service\Industry\Project\ChainRecipeIngredients;
use App\Service\Industry\Project\IntermediateStockProposal;
use App\Service\Industry\Project\IntermediateStockProposer;
use App\Service\Industry\Project\ProjectContainerService;
use App\Tests\Unit\Service\Industry\Stock\StagesCorporationAssets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IntermediateStockProposer::class)]
#[Group('issue-1324')]
final class IntermediateStockProposerPersonalStockContainersTest extends TestCase
{
    use BuildsChainBomLines;
    use StagesCorporationAssets;

    private const int STATION_JITA = 100;
    private const int FINAL_PRODUCT = 16_673;
    private const int HYDROGEN_FUEL_BLOCK = 4_246;
    private const int HANGAR_HYDROGEN_FUEL_BLOCK = 3;
    private const int CONTAINER_HYDROGEN_FUEL_BLOCK = 5;
    private const int REQUIRED_HYDROGEN_FUEL_BLOCK = 100;

    private User $owner;
    private IndustryProject $project;
    private IntermediateStockProposer $proposer;
    private int $officeAtJita;

    protected function setUp(): void
    {
        $this->stageCorporationAssets();
        $this->connectDirector();
        $this->owner = $this->userInTheCorporation();
        $this->project = (new IndustryProject())->setOwner($this->owner)->setStatus(ProjectStatus::Draft);
        $this->officeAtJita = $this->cacheOffice(self::STATION_JITA, 'Jita IV', 'Jita');
        $this->hangarQuantities = [self::HYDROGEN_FUEL_BLOCK => self::HANGAR_HYDROGEN_FUEL_BLOCK];

        $assetRepository = $this->createStub(CachedAssetRepository::class);
        $assetRepository->method('hasPersonalAssetsOf')->willReturn(true);

        $chainRecipeIngredients = $this->createStub(ChainRecipeIngredients::class);
        $chainRecipeIngredients->method('producedIngredientsOf')->willReturnCallback(
            fn (array $productTypeIds): array => (new InMemoryChainRecipeIngredients([]))->producedIngredientsOf($productTypeIds),
        );

        $structureConfigRepository = $this->createStub(IndustryStructureConfigRepository::class);
        $structureConfigRepository->method('findByUser')->willReturn([]);

        $this->proposer = new IntermediateStockProposer(
            $assetRepository,
            $this->createStub(ProjectContainerService::class),
            $this->createStub(IndustryProjectContributionRepository::class),
            $chainRecipeIngredients,
            $this->personalStockReader,
            $structureConfigRepository,
        );
    }

    public function testUnknownPersonalStockContainerMakesTheProposalAssetsUnknownWithItsReason(): void
    {
        $this->component();
        $persoA = $this->cacheContainer('Perso A', $this->officeAtJita);
        $this->declareContainer($this->owner, 'Perso A', $persoA);
        $this->removeFromAssets($persoA);

        $proposal = $this->proposer->proposalOf($this->project, $this->owner);

        self::assertSame([IntermediateStockProposal::STATE_ASSETS_UNKNOWN, 'container_not_found', []], [$proposal->state, $proposal->unknownReason, $proposal->lines]);
    }

    public function testKnownPersonalStockContainerContentsEnterTheProposedStock(): void
    {
        $hydrogenFuelBlock = $this->component();
        $persoA = $this->cacheContainer('Perso A', $this->officeAtJita);
        $this->cacheItem(self::HYDROGEN_FUEL_BLOCK, self::CONTAINER_HYDROGEN_FUEL_BLOCK, $persoA, 'Hydrogen Fuel Block');
        $this->declareContainer($this->owner, 'Perso A', $persoA);

        $proposal = $this->proposer->proposalOf($this->project, $this->owner);

        self::assertSame([IntermediateStockProposal::STATE_READY, null], [$proposal->state, $proposal->unknownReason]);
        self::assertSame(
            [[self::lineId($hydrogenFuelBlock), self::HYDROGEN_FUEL_BLOCK, 'Hydrogen Fuel Block', 100, 100, 8, null, 8, 8]],
            array_map(
                static fn ($line): array => [$line->bomItemId, $line->typeId, $line->typeName, $line->demandQuantity, $line->remainingQuantity, $line->personalQuantity, $line->containerQuantity, $line->stockQuantity, $line->proposedQuantity],
                $proposal->lines,
            ),
        );
    }

    private function component(): IndustryProjectBomLine
    {
        $line = $this->jobLine(self::HYDROGEN_FUEL_BLOCK, 'Hydrogen Fuel Block', 'component', self::FINAL_PRODUCT, 10)
            ->setRequiredQuantity(self::REQUIRED_HYDROGEN_FUEL_BLOCK)
            ->setFulfilledQuantity(0);
        $this->project->addBomItem($line);

        return $line;
    }
}
