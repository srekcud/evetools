<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Industry\StructureSearchListResource;
use App\ApiResource\Industry\StructureSearchResource;
use App\Entity\CachedStructure;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\ESI\EsiClient;
use App\State\Provider\Industry\StructureSearchProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Issue #47: an ESI error while resolving a structure must not look like "no result".
 * A 403 on one structure is the normal ACL case (structure simply not offered);
 * a transient error (5xx, 420, network) flags the result as incomplete;
 * a transient error on the search call itself is an upstream failure (503), not a bad request.
 */
#[CoversClass(StructureSearchProvider::class)]
class StructureSearchProviderTest extends TestCase
{
    private const int EVE_CHARACTER_ID = 2112000001;
    private const int CORPORATION_ID = 98000001;
    private const int ACCESSIBLE_STRUCTURE_ID = 1035466617947;
    private const int OTHER_STRUCTURE_ID = 1035466617948;
    private const int AZBEL_TYPE_ID = 35826;
    private const int SOLAR_SYSTEM_ID = 30000142;
    private const string SEARCH_QUERY = 'Azbel';

    private EsiClient&Stub $esiClient;
    private IndustryStructureConfigRepository&Stub $structureConfigRepository;
    private CachedStructureRepository&Stub $cachedStructureRepository;
    private MapSolarSystemRepository&Stub $solarSystemRepository;
    private EntityManagerInterface $entityManager;
    private Character&Stub $mainCharacter;
    private User&Stub $user;
    private StructureSearchProvider $provider;

    protected function setUp(): void
    {
        $this->esiClient = $this->createStub(EsiClient::class);

        $this->mainCharacter = $this->createStub(Character::class);
        $this->mainCharacter->method('getEveCharacterId')->willReturn(self::EVE_CHARACTER_ID);
        $this->mainCharacter->method('getEveToken')->willReturn($this->createStub(EveToken::class));

        $this->user = $this->createStub(User::class);
        $this->user->method('getMainCharacter')->willReturn($this->mainCharacter);
        $this->user->method('getCorporationId')->willReturn(self::CORPORATION_ID);

        $this->structureConfigRepository = $this->createStub(IndustryStructureConfigRepository::class);

        $this->cachedStructureRepository = $this->createStub(CachedStructureRepository::class);
        $this->solarSystemRepository = $this->createStub(MapSolarSystemRepository::class);
        $this->entityManager = $this->createStub(EntityManagerInterface::class);

        $this->provider = $this->providerFor($this->user, self::SEARCH_QUERY);
    }

    private function providerFor(?User $user, ?string $searchQuery): StructureSearchProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $requestStack = new RequestStack();
        if ($searchQuery !== null) {
            $requestStack->push(new Request(['q' => $searchQuery]));
        }

        return new StructureSearchProvider(
            $security,
            $this->esiClient,
            $this->structureConfigRepository,
            $this->cachedStructureRepository,
            $this->solarSystemRepository,
            $this->entityManager,
            $requestStack,
            new NullLogger(),
        );
    }

    public function testStructureWithoutDockingAccessIsLeftOutOfACompleteResult(): void
    {
        $this->givenEsiResolves([
            self::ACCESSIBLE_STRUCTURE_ID => $this->azbelInfo('Accessible Azbel'),
            self::OTHER_STRUCTURE_ID => EsiApiException::forbidden(),
        ]);

        $result = $this->provider->provide(new GetCollection());

        $this->assertSame([self::ACCESSIBLE_STRUCTURE_ID], $this->locationIdsOf($result));
        $this->assertSame('Accessible Azbel', $result->structures[0]->locationName);
        $this->assertFalse($result->incomplete);
    }

    #[DataProvider('transientEsiErrors')]
    public function testTransientEsiErrorOnOneStructureFlagsTheResultAsIncomplete(EsiApiException $transientError): void
    {
        $this->givenEsiResolves([
            self::ACCESSIBLE_STRUCTURE_ID => $this->azbelInfo('Accessible Azbel'),
            self::OTHER_STRUCTURE_ID => $transientError,
        ]);

        $result = $this->provider->provide(new GetCollection());

        $this->assertSame([self::ACCESSIBLE_STRUCTURE_ID], $this->locationIdsOf($result));
        $this->assertTrue($result->incomplete);
    }

    #[DataProvider('transientEsiErrors')]
    public function testTransientEsiErrorOnEveryStructureIsNotAnEmptyCompleteResult(EsiApiException $transientError): void
    {
        $this->givenEsiResolves([
            self::ACCESSIBLE_STRUCTURE_ID => $transientError,
            self::OTHER_STRUCTURE_ID => $transientError,
        ]);

        $result = $this->provider->provide(new GetCollection());

        $this->assertSame([], $this->locationIdsOf($result));
        $this->assertTrue($result->incomplete);
    }

    #[DataProvider('upstreamFailuresOnSearch')]
    public function testUpstreamFailureOnTheSearchCallIsReportedAsServiceUnavailable(EsiApiException $upstreamFailure): void
    {
        $this->esiClient->method('get')->willThrowException($upstreamFailure);

        $this->assertSame(503, $this->httpStatusCodeOfSearch());
    }

    public function testErrorLimitOnTheSearchCallIsReportedAsTooManyRequests(): void
    {
        $this->esiClient->method('get')->willThrowException(EsiApiException::fromResponse(420, 'Error limited'));

        $this->assertSame(429, $this->httpStatusCodeOfSearch());
    }

    public function testSearchWithoutAuthenticatedUserIsUnauthorized(): void
    {
        $this->assertSame(401, $this->httpStatusCodeOf($this->providerFor(null, self::SEARCH_QUERY)));
    }

    public function testSearchWithoutMainCharacterIsForbidden(): void
    {
        $userWithoutMainCharacter = $this->createStub(User::class);
        $userWithoutMainCharacter->method('getMainCharacter')->willReturn(null);

        $this->assertSame(403, $this->httpStatusCodeOf($this->providerFor($userWithoutMainCharacter, self::SEARCH_QUERY)));
    }

    public function testSearchWithoutEveTokenIsForbidden(): void
    {
        $characterWithoutToken = $this->createStub(Character::class);
        $characterWithoutToken->method('getEveToken')->willReturn(null);
        $userWithoutToken = $this->createStub(User::class);
        $userWithoutToken->method('getMainCharacter')->willReturn($characterWithoutToken);

        $this->assertSame(403, $this->httpStatusCodeOf($this->providerFor($userWithoutToken, self::SEARCH_QUERY)));
    }

    public function testSearchWithoutRequestIsABadRequest(): void
    {
        $this->assertSame(400, $this->httpStatusCodeOf($this->providerFor($this->user, null)));
    }

    public function testSearchTermShorterThanThreeCharactersAfterTrimIsABadRequest(): void
    {
        $this->assertSame(400, $this->httpStatusCodeOf($this->providerFor($this->user, '  Az  ')));
    }

    public function testSearchTermOfThreeCharactersIsSentTrimmedToEsi(): void
    {
        $this->givenEsiResolves([self::ACCESSIBLE_STRUCTURE_ID => $this->azbelInfo('Abc Azbel')], 'Abc');

        $result = $this->providerFor($this->user, '  Abc  ')->provide(new GetCollection());

        $this->assertSame([self::ACCESSIBLE_STRUCTURE_ID], $this->locationIdsOf($result));
    }

    public function testSearchWithoutStructureMatchIsAnEmptyCompleteResult(): void
    {
        $this->givenEsiResolves([]);

        $result = $this->provider->provide(new GetCollection());

        $this->assertSame([], $this->locationIdsOf($result));
        $this->assertFalse($result->incomplete);
    }

    public function testStructureAlreadyConfiguredByTheUserIsLeftOut(): void
    {
        $configuredStructure = new IndustryStructureConfig();
        $configuredStructure->setLocationId(self::OTHER_STRUCTURE_ID);
        $structureWithoutLocation = new IndustryStructureConfig();
        $this->structureConfigRepository->method('findByUser')->willReturn([$structureWithoutLocation, $configuredStructure]);
        $this->givenEsiResolves([
            self::ACCESSIBLE_STRUCTURE_ID => $this->azbelInfo('Accessible Azbel'),
            self::OTHER_STRUCTURE_ID => $this->azbelInfo('Configured Azbel'),
        ]);

        $result = $this->provider->provide(new GetCollection());

        $this->assertSame([self::ACCESSIBLE_STRUCTURE_ID], $this->locationIdsOf($result));
    }

    public function testOnlyTheFirstTenStructureMatchesAreResolved(): void
    {
        $structureResponses = [];
        for ($rank = 1; $rank <= 12; ++$rank) {
            $structureResponses[self::ACCESSIBLE_STRUCTURE_ID + $rank] = $this->azbelInfo('Azbel ' . $rank);
        }
        $this->givenEsiResolves($structureResponses);

        $result = $this->provider->provide(new GetCollection());

        $this->assertSame(array_slice(array_keys($structureResponses), 0, 10), $this->locationIdsOf($result));
    }

    public function testResolvedStructureCarriesItsSolarSystemNameAndCorporationOwnership(): void
    {
        $jita = new MapSolarSystem();
        $jita->setSolarSystemId(self::SOLAR_SYSTEM_ID);
        $jita->setSolarSystemName('Jita');
        $this->solarSystemRepository->method('findBySolarSystemId')->willReturnMap([[self::SOLAR_SYSTEM_ID, $jita]]);
        $this->givenEsiResolves([self::ACCESSIBLE_STRUCTURE_ID => $this->azbelInfo('Corp Azbel')]);

        $structure = $this->provider->provide(new GetCollection())->structures[0];

        $this->assertSame('Corp Azbel', $structure->locationName);
        $this->assertSame(self::SOLAR_SYSTEM_ID, $structure->solarSystemId);
        $this->assertSame('Jita', $structure->solarSystemName);
        $this->assertSame('azbel', $structure->structureType);
        $this->assertSame(self::AZBEL_TYPE_ID, $structure->typeId);
        $this->assertTrue($structure->isCorporationOwned);
    }

    public function testStructureOwnedByAnotherCorporationIsNotCorporationOwned(): void
    {
        $this->givenEsiResolves([self::ACCESSIBLE_STRUCTURE_ID => [
            'name' => 'Foreign Sotiyo',
            'owner_id' => self::CORPORATION_ID + 7,
            'type_id' => 35827,
            'solar_system_id' => self::SOLAR_SYSTEM_ID,
        ]]);

        $structure = $this->provider->provide(new GetCollection())->structures[0];

        $this->assertFalse($structure->isCorporationOwned);
    }

    public function testStructureWithoutOwnerOrSolarSystemIsNotCorporationOwnedEvenForAUserWithoutCorporation(): void
    {
        $userWithoutCorporation = $this->createStub(User::class);
        $userWithoutCorporation->method('getMainCharacter')->willReturn($this->mainCharacter);
        $userWithoutCorporation->method('getCorporationId')->willReturn(null);
        $this->givenEsiResolves([self::ACCESSIBLE_STRUCTURE_ID => ['name' => 'Ownerless Athanor', 'type_id' => 35835]]);

        $structure = $this->providerFor($userWithoutCorporation, self::SEARCH_QUERY)->provide(new GetCollection())->structures[0];

        $this->assertFalse($structure->isCorporationOwned);
        $this->assertNull($structure->solarSystemId);
        $this->assertNull($structure->solarSystemName);
        $this->assertSame('athanor', $structure->structureType);
    }

    #[DataProvider('structureTypes')]
    public function testStructureTypeIsDerivedFromItsTypeId(int $typeId, ?string $expectedStructureType): void
    {
        $this->givenEsiResolves([self::ACCESSIBLE_STRUCTURE_ID => [
            'name' => 'Some structure',
            'owner_id' => self::CORPORATION_ID,
            'type_id' => $typeId,
            'solar_system_id' => self::SOLAR_SYSTEM_ID,
        ]]);

        $structure = $this->provider->provide(new GetCollection())->structures[0];

        $this->assertSame($expectedStructureType, $structure->structureType);
        $this->assertSame($typeId, $structure->typeId);
    }

    public static function structureTypes(): iterable
    {
        yield 'Raitaru' => [35825, 'raitaru'];
        yield 'Azbel' => [35826, 'azbel'];
        yield 'Sotiyo' => [35827, 'sotiyo'];
        yield 'Athanor' => [35835, 'athanor'];
        yield 'Tatara' => [35836, 'tatara'];
        yield 'Fortizar is not an industry structure' => [35833, null];
    }

    public function testNewlyResolvedStructureIsCachedWithItsEsiDetails(): void
    {
        $persistedStructures = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persistedStructures): void {
                $persistedStructures[] = $entity;
            }
        );
        $entityManager->expects($this->once())->method('flush');
        $this->entityManager = $entityManager;
        $this->givenEsiResolves([self::ACCESSIBLE_STRUCTURE_ID => $this->azbelInfo('Cached Azbel')]);

        $this->providerFor($this->user, self::SEARCH_QUERY)->provide(new GetCollection());

        $this->assertCount(1, $persistedStructures);
        $cached = $persistedStructures[0];
        $this->assertInstanceOf(CachedStructure::class, $cached);
        $this->assertSame(self::ACCESSIBLE_STRUCTURE_ID, $cached->getStructureId());
        $this->assertSame('Cached Azbel', $cached->getName());
        $this->assertSame(self::SOLAR_SYSTEM_ID, $cached->getSolarSystemId());
        $this->assertSame(self::CORPORATION_ID, $cached->getOwnerCorporationId());
        $this->assertSame(self::AZBEL_TYPE_ID, $cached->getTypeId());
    }

    public function testAlreadyCachedStructureIsRefreshedWithItsEsiDetails(): void
    {
        $staleResolvedAt = new \DateTimeImmutable('2026-01-15 08:00:00');
        $cached = new CachedStructure();
        $cached->setStructureId(self::ACCESSIBLE_STRUCTURE_ID);
        $cached->setName('Old Raitaru name');
        $cached->setSolarSystemId(30002187);
        $cached->setOwnerCorporationId(98000999);
        $cached->setTypeId(35825);
        $cached->setResolvedAt($staleResolvedAt);
        $this->cachedStructureRepository->method('findByStructureId')->willReturnMap([[self::ACCESSIBLE_STRUCTURE_ID, $cached]]);
        $this->givenEsiResolves([self::ACCESSIBLE_STRUCTURE_ID => $this->azbelInfo('Renamed Azbel')]);

        $this->provider->provide(new GetCollection());

        $this->assertSame('Renamed Azbel', $cached->getName());
        $this->assertSame(self::SOLAR_SYSTEM_ID, $cached->getSolarSystemId());
        $this->assertSame(self::CORPORATION_ID, $cached->getOwnerCorporationId());
        $this->assertSame(self::AZBEL_TYPE_ID, $cached->getTypeId());
        $this->assertGreaterThan($staleResolvedAt, $cached->getResolvedAt());
    }

    public function testServerErrorOnTheSearchCallIsReportedAsServiceUnavailable(): void
    {
        $this->esiClient->method('get')->willThrowException(EsiApiException::fromResponse(500, 'Internal Server Error'));

        $this->assertSame(503, $this->httpStatusCodeOfSearch());
    }

    public function testClientErrorOnTheSearchCallIsReportedAsBadRequest(): void
    {
        $this->esiClient->method('get')->willThrowException(EsiApiException::notFound());

        $this->assertSame(400, $this->httpStatusCodeOfSearch());
    }

    /**
     * @return iterable<string, array{EsiApiException}>
     */
    public static function transientEsiErrors(): iterable
    {
        yield 'ESI 502' => [EsiApiException::fromResponse(502, 'Bad Gateway')];
        yield 'ESI 503' => [EsiApiException::fromResponse(503, 'Service Unavailable')];
        yield 'ESI 420 error limited' => [EsiApiException::fromResponse(420, 'Error limited')];
        yield 'network error' => [EsiApiException::fromResponse(0, 'Network error: timeout')];
    }

    /**
     * @return iterable<string, array{EsiApiException}>
     */
    public static function upstreamFailuresOnSearch(): iterable
    {
        yield 'ESI 502' => [EsiApiException::fromResponse(502, 'Bad Gateway')];
        yield 'ESI 503' => [EsiApiException::fromResponse(503, 'Service Unavailable')];
        yield 'network error' => [EsiApiException::fromResponse(0, 'Network error: timeout')];
    }

    /**
     * @param array<int, array<string, mixed>|EsiApiException> $structureResponses
     */
    private function givenEsiResolves(array $structureResponses, string $searchedTerm = self::SEARCH_QUERY): void
    {
        $searchEndpoint = sprintf(
            '/characters/%d/search/?categories=structure&search=%s&strict=false',
            self::EVE_CHARACTER_ID,
            $searchedTerm,
        );

        $this->esiClient->method('get')->willReturnCallback(
            static function (string $endpoint) use ($searchEndpoint, $structureResponses): array {
                if ($endpoint === $searchEndpoint) {
                    return ['structure' => array_keys($structureResponses)];
                }

                foreach ($structureResponses as $structureId => $response) {
                    if ($endpoint === sprintf('/universe/structures/%d/', $structureId)) {
                        if ($response instanceof EsiApiException) {
                            throw $response;
                        }

                        return $response;
                    }
                }

                throw new \LogicException('Unexpected ESI endpoint ' . $endpoint);
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function azbelInfo(string $name): array
    {
        return [
            'name' => $name,
            'owner_id' => self::CORPORATION_ID,
            'type_id' => self::AZBEL_TYPE_ID,
            'solar_system_id' => self::SOLAR_SYSTEM_ID,
        ];
    }

    private function httpStatusCodeOfSearch(): ?int
    {
        return $this->httpStatusCodeOf($this->provider);
    }

    private function httpStatusCodeOf(StructureSearchProvider $provider): ?int
    {
        try {
            $provider->provide(new GetCollection());
        } catch (HttpExceptionInterface $e) {
            return $e->getStatusCode();
        } catch (RequestExceptionInterface) {
            return 400;
        } catch (\Throwable) {
            return null;
        }

        return 200;
    }

    /**
     * @return int[]
     */
    private function locationIdsOf(StructureSearchListResource $result): array
    {
        return array_map(static fn (StructureSearchResource $structure): int => $structure->locationId, $result->structures);
    }
}
