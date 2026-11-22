<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Industry\Project\ProjectMaterialSourcingResource;
use App\Entity\CachedStructure;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Industry\Application\CourierHub;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\MaterialSourcingComparator;
use App\Industry\Application\PlannedProduction;
use App\Enum\SourcingMarket;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\Plan;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectLineReservationRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\ProjectContainerFulfillment;
use App\Service\Industry\Project\ProjectLineFulfillments;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use App\State\Provider\Industry\Project\ProjectMaterialSourcingProvider;
use App\Tests\Unit\Industry\Application\Double\InMemoryItemVolumes;
use App\Tests\Unit\Industry\Application\Double\InMemoryJumpDistances;
use App\Tests\Unit\Industry\Application\Double\InMemoryMarketPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryStructureMarketPrices;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectMaterialSourcingProvider::class)]
#[Group('issue-1140')]
final class ProjectMaterialSourcingProviderTest extends TestCase
{
    private const int HAMMERHEAD_II = 449_001;
    private const int CADMIUM = 16_647;
    private const int CADMIUM_REQUIRED = 38_200;
    private const int REACTION_RUNS = 33;
    private const int CADMIUM_NEED = 3_213;
    private const int STRUCTURE_ID = 1_035_466_543_993;
    private const int WORKSHOP_SYSTEM_ID = 30_002_187;
    private const int HUB_SYSTEM_ID = 30_000_772;

    public function testJobMaterialNeedsAreMappedFromThePlanAlreadyComputedAndHandedToTheContainerFulfillment(): void
    {
        $owner = $this->pilot();
        $project = (new IndustryProject())->setOwner($owner)->setName('Cadmium batch');
        $project->addMember((new IndustryProjectParticipant())->setUser(new User())->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Accepted));
        $job = $this->reactionLine();
        $cadmium = (new IndustryProjectBomLine())
            ->setTypeId(self::CADMIUM)
            ->setTypeName('Cadmium')
            ->setRequiredQuantity(self::CADMIUM_REQUIRED)
            ->setIsJob(false);
        $bomLines = [$job, $cadmium];
        $planJob = new Job(self::HAMMERHEAD_II, ActivityKind::Reaction, new Runs(self::REACTION_RUNS), new MaterialEfficiency(0), new TimeEfficiency(0), [self::CADMIUM => new Quantity(self::CADMIUM_NEED)]);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($owner);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($project);
        $bomLineRepository = $this->createStub(IndustryProjectBomLineRepository::class);
        $bomLineRepository->method('findBy')->willReturn($bomLines);
        $projectService = $this->createMock(IndustryProjectService::class);
        $projectService->expects(self::once())->method('planOf')->with($project, $owner)->willReturn(new PlannedProduction(new Plan([$planJob], [], []), []));
        $projectService->method('favoriteSystemsOf')->willReturn(FavoriteSystems::none());
        $containerFulfillment = $this->createMock(ProjectContainerFulfillment::class);
        $containerFulfillment->expects(self::never())->method('of');
        $containerFulfillment->expects(self::once())->method('ofWithJobMaterialNeeds')
            ->with($project, self::identicalTo($bomLines), [$job->getId()->toRfc4122() => [self::CADMIUM => self::CADMIUM_NEED]])
            ->willReturn(ProjectLineFulfillments::none());
        $provider = new ProjectMaterialSourcingProvider(
            $security,
            $projectRepository,
            $bomLineRepository,
            new ProjectAccessChecker($this->createStub(IndustryProjectParticipantRepository::class)),
            $projectService,
            new MaterialSourcingComparator(new InMemoryMarketPrices([]), new InMemoryStructureMarketPrices(), new InMemoryItemVolumes(), new InMemoryJumpDistances()),
            new InMemoryStructureMarketPrices(),
            $containerFulfillment,
            $this->createStub(IndustryProjectParticipantRepository::class),
            $this->createStub(IndustryProjectLineReservationRepository::class),
            $this->createStub(IndustryProjectContributionRepository::class),
            cachedStructureRepository: $this->createStub(CachedStructureRepository::class),
        );

        $sourcing = $provider->provide(new Get(), ['projectId' => $project->getId()->toRfc4122()]);

        self::assertInstanceOf(ProjectMaterialSourcingResource::class, $sourcing);
        self::assertSame([], $sourcing->rows);
    }

    #[Group('issue-1158')]
    public function testSoloProjectListsEveryMaterialLineStillToSourceWithoutAnyReservation(): void
    {
        $owner = $this->pilot();
        $project = (new IndustryProject())->setOwner($owner)->setName('Cadmium batch');

        $sourcing = $this->sourcingOf($project, $owner, [$this->cadmiumLine()]);

        self::assertCount(1, $sourcing->rows);
        self::assertSame(self::CADMIUM, $sourcing->rows[0]['typeId']);
        self::assertSame(self::CADMIUM_REQUIRED, $sourcing->rows[0]['quantity']);
    }

    #[Group('issue-1158')]
    public function testSoloProjectNeverReportsMissingReservations(): void
    {
        $owner = $this->pilot();
        $project = (new IndustryProject())->setOwner($owner)->setName('Cadmium batch');

        $sourcing = $this->sourcingOf($project, $owner, [$this->cadmiumLine()]);

        self::assertTrue($sourcing->hasReservations);
    }

    #[Group('issue-1158')]
    public function testGroupProjectKeepsListingOnlyTheLinesTheReaderReserved(): void
    {
        $owner = $this->pilot();
        $project = (new IndustryProject())->setOwner($owner)->setName('Cadmium batch');
        $project->addMember((new IndustryProjectParticipant())->setUser(new User())->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Accepted));

        $sourcing = $this->sourcingOf($project, $owner, [$this->cadmiumLine()]);

        self::assertSame([], $sourcing->rows);
        self::assertFalse($sourcing->hasReservations);
    }

    private function cadmiumLine(): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())
            ->setTypeId(self::CADMIUM)
            ->setTypeName('Cadmium')
            ->setRequiredQuantity(self::CADMIUM_REQUIRED)
            ->setIsJob(false);
    }

    #[Group('issue-1287')]
    public function testStructureSystemIsReadFromTheCachedStructureAndTheBridgeAndTheStockAreCarriedToTheRow(): void
    {
        $owner = $this->pilotWithMarketStructure();
        $project = (new IndustryProject())->setOwner($owner)->setName('Cadmium batch');
        $line = $this->cadmiumLine();

        $sourcing = $this->sourcingOf(
            $project,
            $owner,
            [$line],
            $this->sourcingComparator(),
            $this->structureMarket(),
            $this->cachedStructureIn(self::HUB_SYSTEM_ID),
            $this->favoriteSystemsWithTheHub(),
        );

        self::assertCount(1, $sourcing->rows);
        $row = $sourcing->rows[0];
        self::assertSame(
            [$line->getId()->toRfc4122(), 38_200 * 80.0, 19_100.0, 3_075_100.0, 40_000, 'enough', 'structure', null, 'structure', 3_075_100.0],
            [$row['lineId'], $row['structureTotal'], $row['structureBridgeTotal'], $row['structureDeliveredTotal'], $row['structureAvailableQuantity'], $row['structureStock'], $row['side'], $row['chosenMarket'], $row['market'], $row['selectedTotal']],
        );
        self::assertArrayHasKey('chosenMarket', $row);
    }

    #[Group('issue-1287')]
    public function testStoredSourcingMarketOfTheLineIsPresentedAsTheChosenAndEffectiveMarketOfTheRow(): void
    {
        $owner = $this->pilotWithMarketStructure();
        $project = (new IndustryProject())->setOwner($owner)->setName('Cadmium batch');
        $line = $this->cadmiumLine()->setSourcingMarket(SourcingMarket::Jita);

        $sourcing = $this->sourcingOf(
            $project,
            $owner,
            [$line],
            $this->sourcingComparator(),
            $this->structureMarket(),
            $this->cachedStructureIn(self::HUB_SYSTEM_ID),
            $this->favoriteSystemsWithTheHub(),
        );

        $row = $sourcing->rows[0];
        self::assertSame(['structure', 'jita', 'jita', 4_144_700.0], [$row['side'], $row['chosenMarket'], $row['market'], $row['selectedTotal']]);
    }

    #[Group('issue-1287')]
    public function testStructureNotResolvedInTheCacheHasAnUnknownBridgeAndTheRowFallsBackToJita(): void
    {
        $owner = $this->pilotWithMarketStructure();
        $project = (new IndustryProject())->setOwner($owner)->setName('Cadmium batch');

        $sourcing = $this->sourcingOf(
            $project,
            $owner,
            [$this->cadmiumLine()],
            $this->sourcingComparator(),
            $this->structureMarket(),
            $this->cachedStructureIn(null),
            $this->favoriteSystemsWithTheHub(),
        );

        $row = $sourcing->rows[0];
        self::assertArrayHasKey('structureBridgeTotal', $row);
        self::assertSame([null, null, 'jita', 4_144_700.0], [$row['structureBridgeTotal'], $row['structureDeliveredTotal'], $row['side'], $row['selectedTotal']]);
    }

    #[Group('issue-1287')]
    public function testStructureAbsentFromTheCachedStructuresHasAnUnknownBridge(): void
    {
        $owner = $this->pilotWithMarketStructure();
        $project = (new IndustryProject())->setOwner($owner)->setName('Cadmium batch');
        $cachedStructures = $this->createStub(CachedStructureRepository::class);
        $cachedStructures->method('findByStructureId')->willReturn(null);

        $sourcing = $this->sourcingOf(
            $project,
            $owner,
            [$this->cadmiumLine()],
            $this->sourcingComparator(),
            $this->structureMarket(),
            $cachedStructures,
            $this->favoriteSystemsWithTheHub(),
        );

        $row = $sourcing->rows[0];
        self::assertSame([null, 'jita'], [$row['structureBridgeTotal'], $row['side']]);
    }

    private function pilotWithMarketStructure(): User
    {
        $owner = $this->pilot();
        $owner->setPreferredMarketStructureId(self::STRUCTURE_ID)->setPreferredMarketStructureName('Perimeter - Tranquility Trading Tower');

        return $owner;
    }

    private function sourcingComparator(): MaterialSourcingComparator
    {
        return new MaterialSourcingComparator(
            new InMemoryMarketPrices([self::CADMIUM => 100.0]),
            $this->structureMarket(),
            new InMemoryItemVolumes([self::CADMIUM => 0.01]),
            new InMemoryJumpDistances(),
        );
    }

    private function structureMarket(): InMemoryStructureMarketPrices
    {
        return new InMemoryStructureMarketPrices(
            [self::STRUCTURE_ID => [self::CADMIUM => 80.0]],
            null,
            [self::STRUCTURE_ID => [self::CADMIUM => 40_000]],
        );
    }

    private function cachedStructureIn(?int $solarSystemId): CachedStructureRepository
    {
        $cachedStructure = (new CachedStructure())->setStructureId(self::STRUCTURE_ID)->setName('Perimeter - Tranquility Trading Tower')->setSolarSystemId($solarSystemId);
        $cachedStructures = $this->createStub(CachedStructureRepository::class);
        $cachedStructures->method('findByStructureId')->willReturnMap([[self::STRUCTURE_ID, $cachedStructure]]);

        return $cachedStructures;
    }

    private function favoriteSystemsWithTheHub(): FavoriteSystems
    {
        return new FavoriteSystems(self::WORKSHOP_SYSTEM_ID, self::WORKSHOP_SYSTEM_ID, new CourierHub(self::HUB_SYSTEM_ID, 800.0, 50.0));
    }

    /**
     * @param list<IndustryProjectBomLine> $bomLines
     */
    private function sourcingOf(
        IndustryProject $project,
        User $reader,
        array $bomLines,
        ?MaterialSourcingComparator $comparator = null,
        ?InMemoryStructureMarketPrices $structureMarket = null,
        ?CachedStructureRepository $cachedStructures = null,
        ?FavoriteSystems $favoriteSystems = null,
    ): ProjectMaterialSourcingResource {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($reader);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($project);
        $bomLineRepository = $this->createStub(IndustryProjectBomLineRepository::class);
        $bomLineRepository->method('findBy')->willReturn($bomLines);
        $projectService = $this->createStub(IndustryProjectService::class);
        $projectService->method('planOf')->willReturn(new PlannedProduction(new Plan([], [], []), []));
        $projectService->method('favoriteSystemsOf')->willReturn($favoriteSystems ?? FavoriteSystems::none());
        $containerFulfillment = $this->createStub(ProjectContainerFulfillment::class);
        $containerFulfillment->method('ofWithJobMaterialNeeds')->willReturn(ProjectLineFulfillments::none());
        $provider = new ProjectMaterialSourcingProvider(
            $security,
            $projectRepository,
            $bomLineRepository,
            new ProjectAccessChecker($this->createStub(IndustryProjectParticipantRepository::class)),
            $projectService,
            $comparator ?? new MaterialSourcingComparator(new InMemoryMarketPrices([]), new InMemoryStructureMarketPrices(), new InMemoryItemVolumes(), new InMemoryJumpDistances()),
            $structureMarket ?? new InMemoryStructureMarketPrices(),
            $containerFulfillment,
            $this->createStub(IndustryProjectParticipantRepository::class),
            $this->createStub(IndustryProjectLineReservationRepository::class),
            $this->createStub(IndustryProjectContributionRepository::class),
            cachedStructureRepository: $cachedStructures ?? $this->createStub(CachedStructureRepository::class),
        );

        return $provider->provide(new Get(), ['projectId' => $project->getId()->toRfc4122()]);
    }

    private function pilot(): User
    {
        $character = (new Character())->setName('Aria Solberg')->setEveCharacterId(2_114_000_001);
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);

        return $user;
    }

    private function reactionLine(): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId(self::HAMMERHEAD_II)
            ->setTypeName('Hammerhead II')
            ->setRequiredQuantity(self::REACTION_RUNS)
            ->setIsJob(true)
            ->setJobGroup('final')
            ->setActivityType('reaction')
            ->setRuns(self::REACTION_RUNS)
            ->setMeLevel(0)
            ->setTeLevel(0);
        (new \ReflectionProperty(IndustryProjectBomLine::class, 'id'))->setValue($line, Uuid::fromString('01970000-0000-7000-8000-000000001140'));

        return $line;
    }
}
