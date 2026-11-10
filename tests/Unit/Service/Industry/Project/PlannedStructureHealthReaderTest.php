<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Industry\Application\StructureHealth;
use App\Industry\Application\StructureHealthAssessor;
use App\Industry\Application\StructureHealthState;
use App\Repository\CharacterRepository;
use App\Service\ESI\CharacterService;
use App\Service\ESI\CorporationRoleReader;
use App\Service\ESI\EsiClient;
use App\Service\Industry\Project\PlannedStructureHealthReader;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

#[CoversClass(PlannedStructureHealthReader::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-342')]
final class PlannedStructureHealthReaderTest extends TestCase
{
    private const string NOW = '2026-11-07 12:00:00';
    private const string STATION_MANAGER = 'Station_Manager';
    private const string STRUCTURES_SCOPE = 'esi-corporations.read_structures.v1';
    private const string ROLES_SCOPE = 'esi-characters.read_corporation_roles.v1';
    private const int CORPORATION_A = 98_342_001;
    private const int CORPORATION_B = 98_342_002;
    private const int RAITARU_ID = 1_035_342_000_001;
    private const int ATHANOR_ID = 1_035_342_000_002;
    private const int TATARA_ID = 1_035_342_000_003;

    private EsiClient&MockObject $esiClient;
    private CharacterRepository&MockObject $characterRepository;
    private User $reader;
    private PlannedStructureHealthReader $healthReader;

    protected function setUp(): void
    {
        $this->esiClient = $this->createMock(EsiClient::class);
        $characterService = new CharacterService($this->esiClient);
        $this->characterRepository = $this->createMock(CharacterRepository::class);
        $this->reader = new User();
        $this->healthReader = new PlannedStructureHealthReader(
            $this->esiClient,
            new CorporationRoleReader($characterService, new ArrayAdapter(), new NullLogger()),
            $this->characterRepository,
            new StructureHealthAssessor(),
            new MockClock(self::NOW, 'UTC'),
            new NullLogger(),
        );
    }

    public function testStructureReadWithTheStationManagerOfItsCorporationIsAssessedFromFuelAndServices(): void
    {
        $raitaru = $this->structure(self::RAITARU_ID, self::CORPORATION_A);
        $manager = $this->character(1, self::CORPORATION_A);
        $this->charactersOfTheReader($manager);
        $this->respondWithRoles(static fn (int $eveCharacterId): array => [self::STATION_MANAGER]);
        $this->esiClient->expects(self::once())
            ->method('getPaginated')
            ->with('/corporations/'.self::CORPORATION_A.'/structures/', $manager->getEveToken())
            ->willReturn([$this->esiStructure(self::RAITARU_ID, '2026-11-09T23:12:00Z', ['Manufacturing' => 'online', 'Invention' => 'offline'])]);

        $health = $this->healthReader->read($this->reader, [$raitaru]);

        $row = $health[$raitaru->getId()->toRfc4122()];
        self::assertSame(StructureHealthState::OfflineService, $row->state);
        self::assertSame(2.5, $row->fuelDays);
        self::assertSame(['Invention'], $row->offlineServices);
    }

    public function testStructureWithoutEnoughFuelDaysIsLowFuelWhileTheOtherOneIsOk(): void
    {
        $raitaru = $this->structure(self::RAITARU_ID, self::CORPORATION_A);
        $athanor = $this->structure(self::ATHANOR_ID, self::CORPORATION_A);
        $this->charactersOfTheReader($this->character(1, self::CORPORATION_A));
        $this->respondWithRoles(static fn (int $eveCharacterId): array => [self::STATION_MANAGER]);
        $this->esiClient->method('getPaginated')->willReturn([
            $this->esiStructure(self::RAITARU_ID, '2026-11-09T23:12:00Z', ['Manufacturing' => 'online']),
            $this->esiStructure(self::ATHANOR_ID, '2026-12-18T12:00:00Z', ['Reactor' => 'online']),
        ]);

        $health = $this->healthReader->read($this->reader, [$raitaru, $athanor]);

        self::assertSame(
            [StructureHealthState::LowFuel, StructureHealthState::Ok],
            [$health[$raitaru->getId()->toRfc4122()]->state, $health[$athanor->getId()->toRfc4122()]->state],
        );
    }

    public function testTwoStructuresOfTheSameCorporationCostASingleEsiRead(): void
    {
        $this->charactersOfTheReader($this->character(1, self::CORPORATION_A));
        $this->respondWithRoles(static fn (int $eveCharacterId): array => [self::STATION_MANAGER]);
        $this->esiClient->expects(self::once())->method('getPaginated')->willReturn([]);

        $this->healthReader->read($this->reader, [
            $this->structure(self::RAITARU_ID, self::CORPORATION_A),
            $this->structure(self::ATHANOR_ID, self::CORPORATION_A),
        ]);
    }

    public function testCorporationWithoutStationManagerIsUnknownAndNeverReadFromEsi(): void
    {
        $raitaru = $this->structure(self::RAITARU_ID, self::CORPORATION_A);
        $this->charactersOfTheReader($this->character(1, self::CORPORATION_A));
        $this->respondWithRoles(static fn (int $eveCharacterId): array => ['Accountant']);
        $this->esiClient->expects(self::never())->method('getPaginated');

        $health = $this->healthReader->read($this->reader, [$raitaru]);

        self::assertEquals(StructureHealth::unknown(), $health[$raitaru->getId()->toRfc4122()]);
    }

    public function testCharactersOfAnotherCorporationWithoutTheScopesOrWithInvalidAuthAreNotReaders(): void
    {
        $raitaru = $this->structure(self::RAITARU_ID, self::CORPORATION_A);
        $otherCorporation = $this->character(1, self::CORPORATION_B);
        $withoutScope = $this->character(2, self::CORPORATION_A, [self::ROLES_SCOPE]);
        $invalidAuth = $this->character(3, self::CORPORATION_A)->markAuthInvalid();
        $this->charactersOfTheReader($otherCorporation, $withoutScope, $invalidAuth);
        $this->esiClient->expects(self::never())->method('get');
        $this->esiClient->expects(self::never())->method('getPaginated');

        $health = $this->healthReader->read($this->reader, [$raitaru]);

        self::assertSame(StructureHealthState::Unknown, $health[$raitaru->getId()->toRfc4122()]->state);
    }

    public function testFirstCharacterByEveIdHoldingTheRoleIsTheOnlyOneRead(): void
    {
        $raitaru = $this->structure(self::RAITARU_ID, self::CORPORATION_A);
        $withoutRole = $this->character(10, self::CORPORATION_A);
        $firstManager = $this->character(20, self::CORPORATION_A);
        $secondManager = $this->character(30, self::CORPORATION_A);
        $this->charactersOfTheReader($secondManager, $firstManager, $withoutRole);
        $this->respondWithRoles(
            static fn (int $eveCharacterId): array => 10 !== $eveCharacterId ? [self::STATION_MANAGER] : ['Accountant'],
        );
        $this->esiClient->expects(self::once())
            ->method('getPaginated')
            ->with(self::anything(), $firstManager->getEveToken())
            ->willReturn([$this->esiStructure(self::RAITARU_ID, '2026-12-18T12:00:00Z', [])]);

        $health = $this->healthReader->read($this->reader, [$raitaru]);

        self::assertSame(StructureHealthState::Ok, $health[$raitaru->getId()->toRfc4122()]->state);
    }

    public function testCharacterWhoseRolesCannotBeReadIsSkippedForTheNextOne(): void
    {
        $raitaru = $this->structure(self::RAITARU_ID, self::CORPORATION_A);
        $unreadableRoles = $this->character(1, self::CORPORATION_A);
        $manager = $this->character(2, self::CORPORATION_A);
        $this->charactersOfTheReader($unreadableRoles, $manager);
        $this->respondWithRoles(
            static fn (int $eveCharacterId): array => 1 === $eveCharacterId
                ? throw new EsiApiException('Service unavailable', 503)
                : [self::STATION_MANAGER],
        );
        $this->esiClient->expects(self::once())
            ->method('getPaginated')
            ->with(self::anything(), $manager->getEveToken())
            ->willReturn([$this->esiStructure(self::RAITARU_ID, '2026-12-18T12:00:00Z', [])]);

        $health = $this->healthReader->read($this->reader, [$raitaru]);

        self::assertSame(StructureHealthState::Ok, $health[$raitaru->getId()->toRfc4122()]->state);
    }

    public function testForbiddenReadMakesTheCorporationUnknownNeverOk(): void
    {
        $raitaru = $this->structure(self::RAITARU_ID, self::CORPORATION_A);
        $this->charactersOfTheReader($this->character(1, self::CORPORATION_A));
        $this->respondWithRoles(static fn (int $eveCharacterId): array => [self::STATION_MANAGER]);
        $this->esiClient->method('getPaginated')->willThrowException(new EsiApiException('Forbidden', 403));

        $health = $this->healthReader->read($this->reader, [$raitaru]);

        self::assertEquals(StructureHealth::unknown(), $health[$raitaru->getId()->toRfc4122()]);
    }

    public function testDirectorWithoutTheStationManagerRoleReadsTheCorporationStructures(): void
    {
        $raitaru = $this->structure(self::RAITARU_ID, self::CORPORATION_A);
        $director = $this->character(1, self::CORPORATION_A);
        $this->charactersOfTheReader($director);
        $this->respondWithRoles(static fn (int $eveCharacterId): array => ['Accountant', 'Director']);
        $this->esiClient->expects(self::once())
            ->method('getPaginated')
            ->with('/corporations/'.self::CORPORATION_A.'/structures/', $director->getEveToken())
            ->willReturn([$this->esiStructure(self::RAITARU_ID, '2026-12-18T12:00:00Z', [])]);

        $health = $this->healthReader->read($this->reader, [$raitaru]);

        self::assertSame(StructureHealthState::Ok, $health[$raitaru->getId()->toRfc4122()]->state);
    }

    public function testStructureAbsentFromTheEsiResponseIsUnknown(): void
    {
        $raitaru = $this->structure(self::RAITARU_ID, self::CORPORATION_A);
        $athanor = $this->structure(self::ATHANOR_ID, self::CORPORATION_A);
        $this->charactersOfTheReader($this->character(1, self::CORPORATION_A));
        $this->respondWithRoles(static fn (int $eveCharacterId): array => [self::STATION_MANAGER]);
        $this->esiClient->method('getPaginated')->willReturn([$this->esiStructure(self::RAITARU_ID, '2026-12-18T12:00:00Z', [])]);

        $health = $this->healthReader->read($this->reader, [$raitaru, $athanor]);

        self::assertSame(
            [StructureHealthState::Ok, StructureHealthState::Unknown],
            [$health[$raitaru->getId()->toRfc4122()]->state, $health[$athanor->getId()->toRfc4122()]->state],
        );
    }

    public function testStructureWithoutFuelExpiryInTheResponseIsUnknown(): void
    {
        $raitaru = $this->structure(self::RAITARU_ID, self::CORPORATION_A);
        $this->charactersOfTheReader($this->character(1, self::CORPORATION_A));
        $this->respondWithRoles(static fn (int $eveCharacterId): array => [self::STATION_MANAGER]);
        $this->esiClient->method('getPaginated')->willReturn([['structure_id' => self::RAITARU_ID, 'services' => []]]);

        $health = $this->healthReader->read($this->reader, [$raitaru]);

        self::assertSame(StructureHealthState::Unknown, $health[$raitaru->getId()->toRfc4122()]->state);
    }

    public function testConfigurationWithoutCorporationOrLocationIsUnknownWithoutAnyEsiRead(): void
    {
        $withoutCorporation = (new IndustryStructureConfig())->setName('Workshop')->setLocationId(self::RAITARU_ID);
        $withoutLocation = (new IndustryStructureConfig())->setName('Workshop')->setCorporationId(self::CORPORATION_A);
        $this->charactersOfTheReader($this->character(1, self::CORPORATION_A));
        $this->respondWithRoles(static fn (int $eveCharacterId): array => [self::STATION_MANAGER]);
        $this->esiClient->expects(self::never())->method('getPaginated');

        $health = $this->healthReader->read($this->reader, [$withoutCorporation, $withoutLocation]);

        self::assertEquals(StructureHealth::unknown(), $health[$withoutCorporation->getId()->toRfc4122()]);
        self::assertEquals(StructureHealth::unknown(), $health[$withoutLocation->getId()->toRfc4122()]);
    }

    public function testEachCorporationHasItsOwnReaderAndItsOwnOutcome(): void
    {
        $raitaru = $this->structure(self::RAITARU_ID, self::CORPORATION_A);
        $tatara = $this->structure(self::TATARA_ID, self::CORPORATION_B);
        $managerA = $this->character(1, self::CORPORATION_A);
        $managerB = $this->character(2, self::CORPORATION_B);
        $this->charactersOfTheReader($managerA, $managerB);
        $this->respondWithRoles(
            static fn (int $eveCharacterId): array => 1 === $eveCharacterId ? [self::STATION_MANAGER] : [],
        );
        $this->esiClient->expects(self::once())
            ->method('getPaginated')
            ->with('/corporations/'.self::CORPORATION_A.'/structures/', $managerA->getEveToken())
            ->willReturn([$this->esiStructure(self::RAITARU_ID, '2026-12-18T12:00:00Z', [])]);

        $health = $this->healthReader->read($this->reader, [$raitaru, $tatara]);

        self::assertSame(
            [StructureHealthState::Ok, StructureHealthState::Unknown],
            [$health[$raitaru->getId()->toRfc4122()]->state, $health[$tatara->getId()->toRfc4122()]->state],
        );
    }

    private function structure(int $locationId, int $corporationId): IndustryStructureConfig
    {
        return (new IndustryStructureConfig())
            ->setName('Structure '.$locationId)
            ->setLocationId($locationId)
            ->setCorporationId($corporationId);
    }

    /**
     * @param list<string> $scopes
     */
    private function character(int $eveCharacterId, int $corporationId, array $scopes = [self::STRUCTURES_SCOPE, self::ROLES_SCOPE]): Character
    {
        $character = (new Character())
            ->setName('Pilot '.$eveCharacterId)
            ->setEveCharacterId($eveCharacterId)
            ->setCorporationId($corporationId);
        $character->setEveToken((new EveToken())->setAccessToken('token-'.$eveCharacterId)->setScopes($scopes));

        return $character;
    }

    /**
     * @param \Closure(int): list<string> $rolesOf
     */
    private function respondWithRoles(\Closure $rolesOf): void
    {
        $this->esiClient->method('get')->willReturnCallback(
            static fn (string $endpoint): array => ['roles' => $rolesOf((int) preg_replace('#\D#', '', $endpoint))],
        );
    }

    private function charactersOfTheReader(Character ...$characters): void
    {
        $this->characterRepository->method('findByUser')->with($this->reader)->willReturn(array_values($characters));
    }

    /**
     * @param array<string, string> $serviceStates
     *
     * @return array<string, mixed>
     */
    private function esiStructure(int $structureId, string $fuelExpires, array $serviceStates): array
    {
        $services = [];
        foreach ($serviceStates as $name => $state) {
            $services[] = ['name' => $name, 'state' => $state];
        }

        return ['structure_id' => $structureId, 'fuel_expires' => $fuelExpires, 'services' => $services];
    }
}
