<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Industry\StructureSearchListResource;
use App\ApiResource\Industry\StructureSearchResource;
use App\Entity\Character;
use App\Entity\EveToken;
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
    private StructureSearchProvider $provider;

    protected function setUp(): void
    {
        $this->esiClient = $this->createStub(EsiClient::class);

        $mainCharacter = $this->createStub(Character::class);
        $mainCharacter->method('getEveCharacterId')->willReturn(self::EVE_CHARACTER_ID);
        $mainCharacter->method('getEveToken')->willReturn($this->createStub(EveToken::class));

        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn($mainCharacter);
        $user->method('getCorporationId')->willReturn(self::CORPORATION_ID);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $structureConfigRepository = $this->createStub(IndustryStructureConfigRepository::class);
        $structureConfigRepository->method('findByUser')->willReturn([]);

        $requestStack = new RequestStack();
        $requestStack->push(new Request(['q' => self::SEARCH_QUERY]));

        $this->provider = new StructureSearchProvider(
            $security,
            $this->esiClient,
            $structureConfigRepository,
            $this->createStub(CachedStructureRepository::class),
            $this->createStub(MapSolarSystemRepository::class),
            $this->createStub(EntityManagerInterface::class),
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
    private function givenEsiResolves(array $structureResponses): void
    {
        $searchEndpoint = sprintf(
            '/characters/%d/search/?categories=structure&search=%s&strict=false',
            self::EVE_CHARACTER_ID,
            self::SEARCH_QUERY,
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
        try {
            $this->provider->provide(new GetCollection());
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
