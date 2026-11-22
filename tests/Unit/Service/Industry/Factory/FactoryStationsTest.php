<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Factory;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Enum\StructureAccessStatus;
use App\Industry\Application\SystemCostIndices;
use App\Industry\Domain\ActivityKind;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\Industry\Factory\FactoryStation;
use App\Service\Industry\Factory\FactoryStationAccess;
use App\Service\Industry\Factory\FactoryStationProject;
use App\Service\Industry\Factory\FactoryStations;
use App\Service\Industry\Project\StructureAccess\CharacterStructureAccess;
use App\Tests\Unit\Support\StubsSdeStructureBonuses;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(FactoryStations::class)]
#[Group('issue-1314')]
final class FactoryStationsTest extends TestCase
{
    use StubsSdeStructureBonuses;

    private const int RAITARU_STRUCTURE_ID = 1_031_000_000_100;
    private const int ATHANOR_STRUCTURE_ID = 1_031_000_000_200;
    private const int JITA_SYSTEM_ID = 30_000_142;
    private const int PERIMETER_SYSTEM_ID = 30_000_144;
    private const int ARIA_EVE_ID = 2_131_400_001;
    private const int BRANN_EVE_ID = 2_131_400_002;
    private const string MANUFACTURING_ME_RIG = self::SDE_M_SET_BASIC_LARGE_SHIP_ME_I;
    private const string REACTOR_ME_RIG = self::SDE_COMPOSITE_REACTOR_ME_II;
    private const string NULLSEC = 'nullsec';

    private User $user;
    private Character $aria;
    private Character $brann;
    private IndustryStructureConfig $raitaru;

    /** @var list<IndustryStructureConfig> */
    private array $configs = [];

    /** @var array<int, ActivityKind[]> */
    private array $missingIndices = [];

    /** @var array<string, StructureAccessStatus> */
    private array $accessByCharacterAndStructure = [];

    /** @var array<int, list<CachedIndustryJob>> */
    private array $activeJobsByEveCharacterId = [];

    /** @var list<IndustryProjectParticipant> */
    private array $memberships = [];

    protected function setUp(): void
    {
        $this->user = new User();
        $this->aria = $this->character(self::ARIA_EVE_ID, 'Aria Solberg');
        $this->brann = $this->character(self::BRANN_EVE_ID, 'Brann Holt');
        $this->raitaru = $this->config('Raitaru Alpha', 'raitaru', self::RAITARU_STRUCTURE_ID, self::JITA_SYSTEM_ID, [self::MANUFACTURING_ME_RIG]);
    }

    public function testManufacturingRigGivesTheManufacturingSpecialisation(): void
    {
        $station = $this->onlyStation($this->factoryStations()->of($this->user));

        self::assertSame(['manufacturing'], $station->specialisations);
    }

    public function testManufacturingAndReactionRigsGiveBothSpecialisationsInOrder(): void
    {
        $this->raitaru->setRigs([self::REACTOR_ME_RIG, self::MANUFACTURING_ME_RIG])->setSecurityType(self::NULLSEC);

        $station = $this->onlyStation($this->factoryStations()->of($this->user));

        self::assertSame(['manufacturing', 'reaction'], $station->specialisations);
    }

    public function testReactionRigAloneGivesOnlyTheReactionSpecialisation(): void
    {
        $this->configs = [];
        $this->config('Athanor Beta', 'athanor', self::ATHANOR_STRUCTURE_ID, self::PERIMETER_SYSTEM_ID, [self::REACTOR_ME_RIG])->setSecurityType(self::NULLSEC);

        $station = $this->onlyStation($this->factoryStations()->of($this->user));

        self::assertSame(['reaction'], $station->specialisations);
    }

    #[Group('issue-1331')]
    public function testReactionRigWithoutModifierInTheSecurityOfTheStationGivesNoSpecialisation(): void
    {
        $this->configs = [];
        $this->config('Athanor Beta', 'athanor', self::ATHANOR_STRUCTURE_ID, self::PERIMETER_SYSTEM_ID, [self::REACTOR_ME_RIG]);

        $station = $this->onlyStation($this->factoryStations()->of($this->user));

        self::assertSame([], $station->specialisations);
    }

    #[Group('issue-1331')]
    public function testRaitaruWithoutRigIsSpecialisedInManufacturingByItsRoleTimeBonusAlone(): void
    {
        $this->raitaru->setRigs([]);

        $station = $this->onlyStation($this->factoryStations()->of($this->user));

        self::assertSame(['manufacturing'], $station->specialisations);
    }

    #[Group('issue-1331')]
    public function testTataraWithoutRigIsSpecialisedInReactionByItsRoleTimeBonusAlone(): void
    {
        $this->configs = [];
        $this->config('Tatara Gamma', 'tatara', self::ATHANOR_STRUCTURE_ID, self::PERIMETER_SYSTEM_ID, []);

        $station = $this->onlyStation($this->factoryStations()->of($this->user));

        self::assertSame(['reaction'], $station->specialisations);
    }

    public function testStationWithoutAnyBonusHasNoSpecialisation(): void
    {
        $this->configs = [];
        $this->config('Athanor Beta', 'athanor', self::ATHANOR_STRUCTURE_ID, self::PERIMETER_SYSTEM_ID, []);

        $station = $this->onlyStation($this->factoryStations()->of($this->user));

        self::assertSame([], $station->specialisations);
    }

    public function testCostIndicesAreTheOnesOfTheSystemOfTheStation(): void
    {
        $station = $this->onlyStation($this->factoryStations()->of($this->user));

        self::assertSame(0.0412, $station->manufacturingCostIndex);
        self::assertSame(0.0187, $station->reactionCostIndex);
        self::assertSame(self::JITA_SYSTEM_ID, $station->solarSystemId);
        self::assertSame('Jita', $station->solarSystemName);
    }

    public function testMissingCostIndexIsNullAndNeverZero(): void
    {
        $this->missingIndices[self::JITA_SYSTEM_ID] = [ActivityKind::Manufacturing];

        $station = $this->onlyStation($this->factoryStations()->of($this->user));

        self::assertNull($station->manufacturingCostIndex);
        self::assertSame(0.0187, $station->reactionCostIndex);
    }

    public function testStationWithoutSolarSystemHasNullIndicesAndNullSystemName(): void
    {
        $this->configs = [];
        $this->config('Nowhere', 'raitaru', self::RAITARU_STRUCTURE_ID, null, [self::MANUFACTURING_ME_RIG]);

        $station = $this->onlyStation($this->factoryStations()->of($this->user));

        self::assertNull($station->solarSystemId);
        self::assertNull($station->solarSystemName);
        self::assertNull($station->manufacturingCostIndex);
        self::assertNull($station->reactionCostIndex);
    }

    public function testStationWithoutLocationIsUnknownForEveryCharacterAndHasUnknownLinesInUse(): void
    {
        $this->configs = [];
        $this->config('Unlocated', 'raitaru', null, self::JITA_SYSTEM_ID, [self::MANUFACTURING_ME_RIG]);
        $this->accessByCharacterAndStructure[self::ARIA_EVE_ID . ':' . self::RAITARU_STRUCTURE_ID] = StructureAccessStatus::Granted;
        $this->activeJobsByEveCharacterId[self::ARIA_EVE_ID] = [$this->job(self::RAITARU_STRUCTURE_ID, CachedIndustryJob::STATUS_ACTIVE)];

        $station = $this->onlyStation($this->factoryStations()->of($this->user));

        self::assertNull($station->locationId);
        self::assertNull($station->linesInUse);
        self::assertEquals(
            [
                new FactoryStationAccess(self::ARIA_EVE_ID, 'Aria Solberg', StructureAccessStatus::Unknown),
                new FactoryStationAccess(self::BRANN_EVE_ID, 'Brann Holt', StructureAccessStatus::Unknown),
            ],
            $station->access,
        );
    }

    public function testAccessIsReadPerCharacterOfTheUserOnTheStationLocation(): void
    {
        $this->accessByCharacterAndStructure[self::ARIA_EVE_ID . ':' . self::RAITARU_STRUCTURE_ID] = StructureAccessStatus::Granted;
        $this->accessByCharacterAndStructure[self::BRANN_EVE_ID . ':' . self::ATHANOR_STRUCTURE_ID] = StructureAccessStatus::Denied;

        $station = $this->onlyStation($this->factoryStations()->of($this->user));

        self::assertEquals(
            [
                new FactoryStationAccess(self::ARIA_EVE_ID, 'Aria Solberg', StructureAccessStatus::Granted),
                new FactoryStationAccess(self::BRANN_EVE_ID, 'Brann Holt', StructureAccessStatus::Unknown),
            ],
            $station->access,
        );
    }

    public function testDeniedAccessIsReportedAsDenied(): void
    {
        $this->accessByCharacterAndStructure[self::BRANN_EVE_ID . ':' . self::RAITARU_STRUCTURE_ID] = StructureAccessStatus::Denied;

        $station = $this->onlyStation($this->factoryStations()->of($this->user));

        self::assertEquals(StructureAccessStatus::Unknown, $station->access[0]->status);
        self::assertEquals(StructureAccessStatus::Denied, $station->access[1]->status);
    }

    public function testLinesInUseCountsTheActiveJobsOfAllCharactersInTheStationOnly(): void
    {
        $this->activeJobsByEveCharacterId[self::ARIA_EVE_ID] = [
            $this->job(self::RAITARU_STRUCTURE_ID, CachedIndustryJob::STATUS_ACTIVE),
            $this->job(self::RAITARU_STRUCTURE_ID, CachedIndustryJob::STATUS_PAUSED),
            $this->job(self::ATHANOR_STRUCTURE_ID, CachedIndustryJob::STATUS_ACTIVE),
        ];
        $this->activeJobsByEveCharacterId[self::BRANN_EVE_ID] = [
            $this->job(self::RAITARU_STRUCTURE_ID, CachedIndustryJob::STATUS_READY),
        ];

        $station = $this->onlyStation($this->factoryStations()->of($this->user));

        self::assertSame(3, $station->linesInUse);
    }

    public function testStationWithoutActiveJobHasZeroLinesInUseAsAKnownValue(): void
    {
        $station = $this->onlyStation($this->factoryStations()->of($this->user));

        self::assertSame(0, $station->linesInUse);
    }

    public function testProjectsListsTheUnfinishedProjectsOfTheUserPlannedInTheStationByName(): void
    {
        $zeta = $this->project('Zeta batch', ProjectStatus::InProgress, [$this->raitaru]);
        $alpha = $this->project('Alpha batch', ProjectStatus::Published, [$this->raitaru, $this->raitaru]);

        $station = $this->onlyStation($this->factoryStations()->of($this->user));

        self::assertEquals(
            [
                new FactoryStationProject($alpha->getId()->toRfc4122(), 'Alpha batch'),
                new FactoryStationProject($zeta->getId()->toRfc4122(), 'Zeta batch'),
            ],
            $station->projects,
        );
    }

    public function testCompletedProjectIsNotListedInTheStation(): void
    {
        $this->project('Ishtar batch', ProjectStatus::Completed, [$this->raitaru]);

        $station = $this->onlyStation($this->factoryStations()->of($this->user));

        self::assertSame([], $station->projects);
    }

    public function testProjectPlannedInAnotherStationIsNotListed(): void
    {
        $athanor = $this->config('Athanor Beta', 'athanor', self::ATHANOR_STRUCTURE_ID, self::PERIMETER_SYSTEM_ID, [self::REACTOR_ME_RIG]);
        $this->project('Moon cracking', ProjectStatus::InProgress, [$athanor]);

        $stations = $this->factoryStations()->of($this->user);

        self::assertCount(2, $stations);
        self::assertSame('Athanor Beta', $stations[0]->name);
        self::assertSame(['Moon cracking'], array_map(static fn (FactoryStationProject $project): string => $project->name, $stations[0]->projects));
        self::assertSame([], $stations[1]->projects);
    }

    public function testStationsAreOrderedByName(): void
    {
        $this->config('Athanor Beta', 'athanor', self::ATHANOR_STRUCTURE_ID, self::PERIMETER_SYSTEM_ID, []);

        $stations = $this->factoryStations()->of($this->user);

        self::assertSame(['Athanor Beta', 'Raitaru Alpha'], array_map(static fn (FactoryStation $station): string => $station->name, $stations));
    }

    public function testStationExposesTheConfigurationIdentity(): void
    {
        $station = $this->onlyStation($this->factoryStations()->of($this->user));

        self::assertSame($this->raitaru->getId()->toRfc4122(), $station->id);
        self::assertSame('Raitaru Alpha', $station->name);
        self::assertSame('raitaru', $station->structureType);
        self::assertSame('highsec', $station->securityType);
        self::assertSame(self::RAITARU_STRUCTURE_ID, $station->locationId);
    }

    private function factoryStations(): FactoryStations
    {
        $structureConfigRepository = $this->createStub(IndustryStructureConfigRepository::class);
        $structureConfigRepository->method('findByUser')->willReturnCallback(fn (): array => $this->configs);

        $costIndices = new class($this->missingIndices) implements SystemCostIndices {
            /**
             * @param array<int, ActivityKind[]> $missingIndices
             */
            public function __construct(private array $missingIndices)
            {
            }

            public function costIndexOf(int $solarSystemId, ActivityKind $activity): ?float
            {
                if (\in_array($activity, $this->missingIndices[$solarSystemId] ?? [], true)) {
                    return null;
                }

                return match ($activity) {
                    ActivityKind::Manufacturing => 0.0412,
                    ActivityKind::Reaction => 0.0187,
                    default => null,
                };
            }
        };

        $structureAccess = $this->createStub(CharacterStructureAccess::class);
        $structureAccess->method('statusOf')->willReturnCallback(
            fn (Character $character, int $structureId): StructureAccessStatus => $this->accessByCharacterAndStructure[$character->getEveCharacterId() . ':' . $structureId] ?? StructureAccessStatus::Unknown,
        );

        $jobRepository = $this->createStub(CachedIndustryJobRepository::class);
        $jobRepository->method('findActiveJobsByCharacter')->willReturnCallback(
            fn (Character $character): array => $this->activeJobsByEveCharacterId[$character->getEveCharacterId()] ?? [],
        );

        $participantRepository = $this->createStub(IndustryProjectParticipantRepository::class);
        $participantRepository->method('findAcceptedWithProjectsByUser')->willReturnCallback(
            fn (User $user): array => $user === $this->user ? $this->memberships : [],
        );

        $solarSystemRepository = $this->createStub(MapSolarSystemRepository::class);
        $solarSystemRepository->method('findBySolarSystemId')->willReturnCallback(
            static fn (int $solarSystemId): ?MapSolarSystem => match ($solarSystemId) {
                self::JITA_SYSTEM_ID => (new MapSolarSystem())->setSolarSystemId(self::JITA_SYSTEM_ID)->setSolarSystemName('Jita'),
                self::PERIMETER_SYSTEM_ID => (new MapSolarSystem())->setSolarSystemId(self::PERIMETER_SYSTEM_ID)->setSolarSystemName('Perimeter'),
                default => null,
            },
        );

        return new FactoryStations($structureConfigRepository, $costIndices, $structureAccess, $jobRepository, $participantRepository, $solarSystemRepository, $this->sdeStructureBonusSummary());
    }

    /**
     * @param list<FactoryStation> $stations
     */
    private function onlyStation(array $stations): FactoryStation
    {
        self::assertCount(1, $stations);

        return $stations[0];
    }

    private function character(int $eveCharacterId, string $name): Character
    {
        $character = (new Character())->setEveCharacterId($eveCharacterId)->setName($name);
        $this->user->addCharacter($character);

        return $character;
    }

    /**
     * @param list<string> $rigs
     */
    private function config(string $name, string $structureType, ?int $locationId, ?int $solarSystemId, array $rigs): IndustryStructureConfig
    {
        $config = (new IndustryStructureConfig())
            ->setUser($this->user)
            ->setName($name)
            ->setStructureType($structureType)
            ->setSecurityType('highsec')
            ->setLocationId($locationId)
            ->setSolarSystemId($solarSystemId)
            ->setRigs($rigs);
        $this->configs[] = $config;

        return $config;
    }

    private function job(int $stationId, string $status): CachedIndustryJob
    {
        return (new CachedIndustryJob())->setStationId($stationId)->setStatus($status);
    }

    /**
     * @param list<IndustryStructureConfig> $plannedIn
     */
    private function project(string $name, ProjectStatus $status, array $plannedIn): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($this->user)->setName($name)->setStatus($status);
        $membership = (new IndustryProjectParticipant())->setUser($this->user)->setRole(ParticipantRole::Owner)->setStatus(ParticipantStatus::Accepted);
        $project->addMember($membership);
        foreach ($plannedIn as $index => $config) {
            $project->addBomItem(
                (new IndustryProjectBomLine())
                    ->setTypeId(587 + $index)
                    ->setTypeName('Rifter')
                    ->setRequiredQuantity(10)
                    ->setIsJob(true)
                    ->setJobGroup('final')
                    ->setPlannedStructureId($config->getId()->toRfc4122()),
            );
        }
        $this->memberships[] = $membership;

        return $project;
    }
}
