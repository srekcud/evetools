<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\StructureAccess;

use App\Entity\Character;
use App\Entity\CharacterStructureAccess;
use App\Entity\EveToken;
use App\Entity\User;
use App\Enum\StructureAccessSource;
use App\Enum\StructureAccessStatus;
use App\Repository\CachedIndustryJobRepository;
use App\Exception\EveAuthRequiredException;
use App\Repository\CharacterStructureAccessRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\Service\Industry\Project\StructureAccess\CharacterStructureAccessReader;
use App\Service\Industry\Project\StructureAccess\StructureAccessProbe;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

trait BuildsStructureAccess
{
    private const int KEEPSTAR_STRUCTURE_ID = 1_000_000_000_005;
    private const int AZBEL_STRUCTURE_ID = 1_040_000_001_000;
    private const int JITA_FORTIZAR_STATION_ID = 60_003_760;

    private const string ESI_BASE_URL = 'https://esi.test/latest';
    private const string ACCESS_TOKEN = 'access-token-of-the-prober';
    private const string PROBE_NOW = '2026-11-22T12:00:00+00:00';

    private static int $nextEveCharacterId = 2_112_299_001;

    private array $requestedUrls = [];
    private array $authorizations = [];

    private function characterOf(bool $withScope = true, bool $withToken = true): Character
    {
        $character = (new Character())->setEveCharacterId(self::$nextEveCharacterId++)->setName('Pilot '.self::$nextEveCharacterId);
        if ($withToken) {
            $character->setEveToken((new EveToken())->setScopes($withScope ? [StructureAccessProbe::REQUIRED_SCOPE] : ['esi-location.read_location.v1']));
        }

        return $character;
    }

    private function pilotOf(Character ...$characters): User
    {
        $pilot = new User();
        foreach ($characters as $character) {
            $pilot->addCharacter($character);
        }

        return $pilot;
    }

    private function accessRowOf(Character $character, int $structureId, StructureAccessStatus $status, StructureAccessSource $source, string $checkedAt): CharacterStructureAccess
    {
        return new CharacterStructureAccess($character, $structureId, $status, $source, new \DateTimeImmutable($checkedAt));
    }

    private function proofKeyOf(Character $character, int $structureId): string
    {
        return $character->getEveCharacterId().':'.$structureId;
    }

    private function accessRepositoryHolding(CharacterStructureAccess ...$rows): CharacterStructureAccessRepository
    {
        $rowsByKey = [];
        foreach ($rows as $row) {
            $rowsByKey[$this->proofKeyOf($row->getCharacter(), $row->getStructureId())] = $row;
        }
        $repository = $this->createStub(CharacterStructureAccessRepository::class);
        $repository->method('findOneByCharacterAndStructure')->willReturnCallback(
            fn (Character $character, int $structureId): ?CharacterStructureAccess => $rowsByKey[$this->proofKeyOf($character, $structureId)] ?? null,
        );

        return $repository;
    }

    /**
     * @param array<string, \DateTimeImmutable> $endDatesByProofKey
     */
    private function jobRepositoryHolding(array $endDatesByProofKey = []): CachedIndustryJobRepository
    {
        $repository = $this->createStub(CachedIndustryJobRepository::class);
        $repository->method('latestEndDateAtStructure')->willReturnCallback(
            fn (Character $character, int $structureId): ?\DateTimeImmutable => $endDatesByProofKey[$this->proofKeyOf($character, $structureId)] ?? null,
        );

        return $repository;
    }

    /**
     * @param array<string, \DateTimeImmutable> $endDatesByProofKey
     */
    private function readerHolding(array $rows = [], array $endDatesByProofKey = []): CharacterStructureAccessReader
    {
        return new CharacterStructureAccessReader($this->accessRepositoryHolding(...$rows), $this->jobRepositoryHolding($endDatesByProofKey));
    }

    private function probeAnswering(\Closure $answer, bool $refusedToken = false): StructureAccessProbe
    {
        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturnCallback(static function () use ($refusedToken): string {
            if ($refusedToken) {
                throw new EveAuthRequiredException('2112299001');
            }

            return self::ACCESS_TOKEN;
        });

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($answer): MockResponse {
            $this->requestedUrls[] = $url;
            $this->authorizations[] = current(array_filter($options['headers'] ?? [], static fn (string $header): bool => str_starts_with($header, 'Authorization:')));

            return $answer();
        });

        return new StructureAccessProbe(
            new EsiClient($httpClient, new TagAwareAdapter(new ArrayAdapter()), $tokenManager, self::ESI_BASE_URL, new NullLogger(), new MockClock(self::PROBE_NOW)),
        );
    }
}
