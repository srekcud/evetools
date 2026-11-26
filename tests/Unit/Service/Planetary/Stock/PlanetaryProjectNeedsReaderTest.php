<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary\Stock;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\Planetary\Stock\PlanetaryProjectNeed;
use App\Service\Planetary\Stock\PlanetaryProjectNeedsBuilder;
use App\Service\Planetary\Stock\PlanetaryProjectNeedsReader;
use App\Tests\Unit\Service\Industry\Stock\StagesCorporationAssets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetaryProjectNeedsReader::class)]
#[Group('issue-1398')]
final class PlanetaryProjectNeedsReaderTest extends TestCase
{
    use StagesCorporationAssets;

    private const int STATION_JITA = 60_003_760;

    private const int BROADCAST_NODE = 2867;
    private const int ROBOTICS = 9848;
    private const int COOLANT = 9832;
    private const int BACTERIA = 2393;
    private const int TRITANIUM = 34;

    private User $user;
    private bool $personalAssetsSynchronized = true;

    /** @var list<IndustryProject> */
    private array $openProjectsOfTheUser = [];

    protected function setUp(): void
    {
        $this->stageCorporationAssets();
        $this->connectDirector();
        $this->user = $this->userInTheCorporation();
        $this->corporationAssetRepository->method('hasPersonalAssetsOf')->willReturnCallback(fn (): bool => $this->personalAssetsSynchronized);
        $this->hangarQuantities = [];
        $this->hangarLocations = [];
    }

    public function testOnlyMaterialLinesOfPlanetaryProductsWithSomethingLeftToProvideAreAskedAndTheirRemainingQuantitiesAreSummedOverTheVisibleProjects(): void
    {
        $ishtarFleet = $this->openProject('Ishtar fleet');
        $this->materialLine($ishtarFleet, self::BROADCAST_NODE, 'Broadcast Node', 10, 4);
        $this->jobLine($ishtarFleet, self::ROBOTICS, 'Robotics', 5);
        $this->materialLine($ishtarFleet, self::TRITANIUM, 'Tritanium', 100, 0);
        $this->materialLine($ishtarFleet, self::COOLANT, 'Coolant', 8, 8);
        $this->materialLine($ishtarFleet, self::BACTERIA, 'Bacteria', 3, 2);
        $rattlesnakeBatch = $this->openProject('Rattlesnake batch');
        $this->materialLine($rattlesnakeBatch, self::BROADCAST_NODE, 'Broadcast Node', 5, 0);
        $this->materialLine($rattlesnakeBatch, self::COOLANT, 'Coolant', 6, 6);
        $this->projectUnseenByTheUser('Foreign fleet', self::BROADCAST_NODE, 'Broadcast Node', 40);
        $this->hangarQuantities = [self::BROADCAST_NODE => 7];

        $needs = $this->reader()->read($this->user);

        self::assertSame(
            [
                [self::BACTERIA, 'Bacteria', 1, 0, 1],
                [self::BROADCAST_NODE, 'Broadcast Node', 11, 7, 4],
            ],
            array_map(static fn (PlanetaryProjectNeed $need): array => [$need->typeId, $need->typeName, $need->required, $need->stock, $need->missing], $needs),
        );
        self::assertSame(
            [['projectId' => $ishtarFleet->getId()->toRfc4122(), 'projectName' => 'Ishtar fleet', 'remaining' => 1]],
            $needs[0]->projects,
        );
        self::assertSame(
            [
                ['projectId' => $ishtarFleet->getId()->toRfc4122(), 'projectName' => 'Ishtar fleet', 'remaining' => 6],
                ['projectId' => $rattlesnakeBatch->getId()->toRfc4122(), 'projectName' => 'Rattlesnake batch', 'remaining' => 5],
            ],
            $needs[1]->projects,
        );
    }

    public function testTheStockIsThePersonalStockAtEveryLocationThatIsTheHangarsPlusTheDeclaredContainers(): void
    {
        $this->materialLine($this->openProject('Ishtar fleet'), self::BROADCAST_NODE, 'Broadcast Node', 30, 0);
        $this->hangarQuantities = [self::BROADCAST_NODE => 7];
        $office = $this->cacheOffice(self::STATION_JITA, 'Jita IV', 'Jita');
        $container = $this->cacheContainer('Planetary hangar', $office);
        $this->cacheItem(self::BROADCAST_NODE, 20, $container, 'Broadcast Node');
        $this->declareContainer($this->user, 'Planetary hangar', $container, 'Division 2', self::STATION_JITA, 'Jita IV');

        $needs = $this->reader()->read($this->user);

        self::assertCount(1, $needs);
        self::assertSame([30, 27, 3], [$needs[0]->required, $needs[0]->stock, $needs[0]->missing]);
    }

    public function testSynchronizedPersonalAssetsWithoutTheProductGiveAZeroStockAndEverythingMissing(): void
    {
        $this->materialLine($this->openProject('Ishtar fleet'), self::ROBOTICS, 'Robotics', 4, 0);
        $this->hangarQuantities = [];

        $needs = $this->reader()->read($this->user);

        self::assertCount(1, $needs);
        self::assertSame([4, 0, 4], [$needs[0]->required, $needs[0]->stock, $needs[0]->missing]);
    }

    public function testPersonalAssetsNeverSynchronizedMakeTheStockAndTheMissingQuantityUnknownWhateverTheHangarsHold(): void
    {
        $this->materialLine($this->openProject('Ishtar fleet'), self::BROADCAST_NODE, 'Broadcast Node', 10, 4);
        $this->personalAssetsSynchronized = false;
        $this->hangarQuantities = [self::BROADCAST_NODE => 7];

        $needs = $this->reader()->read($this->user);

        self::assertCount(1, $needs);
        self::assertSame([6, null, null], [$needs[0]->required, $needs[0]->stock, $needs[0]->missing]);
    }

    public function testAnUnknownDeclaredContainerMakesTheStockAndTheMissingQuantityUnknown(): void
    {
        $this->materialLine($this->openProject('Ishtar fleet'), self::COOLANT, 'Coolant', 9, 1);
        $this->hangarQuantities = [self::COOLANT => 50];
        $office = $this->cacheOffice(self::STATION_JITA, 'Jita IV', 'Jita');
        $container = $this->cacheContainer('Planetary hangar', $office);
        $this->declareContainer($this->user, 'Planetary hangar', $container, 'Division 2', self::STATION_JITA, 'Jita IV');
        $this->removeFromAssets($container);

        $needs = $this->reader()->read($this->user);

        self::assertCount(1, $needs);
        self::assertSame([8, null, null], [$needs[0]->required, $needs[0]->stock, $needs[0]->missing]);
    }

    public function testWithoutAnyProjectOrWithoutAnyPlanetaryLineNothingIsAsked(): void
    {
        self::assertSame([], $this->reader()->read($this->user));

        $ishtarFleet = $this->openProject('Ishtar fleet');
        $this->materialLine($ishtarFleet, self::TRITANIUM, 'Tritanium', 100, 0);
        $this->jobLine($ishtarFleet, self::ROBOTICS, 'Robotics', 5);

        self::assertSame([], $this->reader()->read($this->user));
    }

    private function reader(): PlanetaryProjectNeedsReader
    {
        $user = $this->user;
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('findOpenOfAcceptedParticipant')->willReturnCallback(
            fn (User $asked): array => $asked === $user ? $this->openProjectsOfTheUser : throw new \LogicException('The projects of the connected user are expected'),
        );

        $schematicRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $schematicRepository->method('getSchematicMap')->willReturn([
            1 => ['inputs' => [self::TRITANIUM => 40], 'output' => ['typeId' => self::BROADCAST_NODE, 'quantity' => 1]],
            2 => ['inputs' => [self::BROADCAST_NODE => 10], 'output' => ['typeId' => self::ROBOTICS, 'quantity' => 1]],
            3 => ['inputs' => [self::BACTERIA => 20], 'output' => ['typeId' => self::COOLANT, 'quantity' => 1]],
            4 => ['inputs' => [self::COOLANT => 20], 'output' => ['typeId' => self::BACTERIA, 'quantity' => 1]],
        ]);

        return new PlanetaryProjectNeedsReader(
            $projectRepository,
            $schematicRepository,
            $this->corporationAssetRepository,
            $this->personalStockReader,
            new PlanetaryProjectNeedsBuilder(),
        );
    }

    private function openProject(string $name): IndustryProject
    {
        $project = (new IndustryProject())->setName($name);
        $this->openProjectsOfTheUser[] = $project;

        return $project;
    }

    private function projectUnseenByTheUser(string $name, int $typeId, string $typeName, int $requiredQuantity): void
    {
        $this->materialLine((new IndustryProject())->setName($name), $typeId, $typeName, $requiredQuantity, 0);
    }

    private function materialLine(IndustryProject $project, int $typeId, string $typeName, int $requiredQuantity, int $fulfilledQuantity): void
    {
        $project->addBomItem(
            (new IndustryProjectBomLine())
                ->setTypeId($typeId)
                ->setTypeName($typeName)
                ->setRequiredQuantity($requiredQuantity)
                ->setFulfilledQuantity($fulfilledQuantity)
                ->setIsJob(false),
        );
    }

    private function jobLine(IndustryProject $project, int $typeId, string $typeName, int $requiredQuantity): void
    {
        $project->addBomItem(
            (new IndustryProjectBomLine())
                ->setTypeId($typeId)
                ->setTypeName($typeName)
                ->setRequiredQuantity($requiredQuantity)
                ->setIsJob(true)
                ->setJobGroup('final'),
        );
    }
}
