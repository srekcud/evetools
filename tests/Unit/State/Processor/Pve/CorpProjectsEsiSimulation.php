<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Pve;

use App\ApiResource\Pve\CorporationProjectErrorResource;
use App\ApiResource\Pve\DetectedSaleResource;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Entity\UserPveSettings;
use App\Exception\EveAuthRequiredException;
use App\Repository\PveIncomeRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\UserPveSettingsRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\Service\Pve\CorpProjectContributionReader;
use App\State\Processor\Pve\ScanLootSalesProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

trait CorpProjectsEsiSimulation
{
    private const EVE_CHARACTER_ID = 2112000001;
    private const PILOT_TWO_ID = 2112000002;
    private const PILOT_THREE_ID = 2112000003;
    private const PILOT_FOUR_ID = 2112000004;
    private const CORPORATION_ID = 98000001;
    private const CORPORATION_NAME = 'Fictional Mining Corp';
    private const SECOND_CORPORATION_ID = 98000002;
    private const SECOND_CORPORATION_NAME = 'Second Fictional Corp';
    private const PROJECT_ID = 'a1b2c3d4-project';
    private const PROJECT_NAME = 'Tritanium Drive';
    private const TRITANIUM = 34;
    private const VELDSPAR = 1230;
    private const ROGUE_DRONE_INFESTATION_DATA = 60459;
    private const REWARD_PER_CONTRIBUTION = 12.5;
    private const PROJECTS_PATH = '/corporations/98000001/projects';
    private const PROJECTS_SCOPE = 'esi-corporations.read_projects.v1';

    private array $corporationProjectRequests = [];

    private array $characterRequests = [];

    private array $tokenOwners = [];

    private array $accessTokenRequests = [];

    private function createProcessor(
        array $corpProjectResponsesByKey,
        array $knownTypeNamesById = [self::TRITANIUM => 'Tritanium', self::ROGUE_DRONE_INFESTATION_DATA => 'Rogue Drone Infestation Data', self::VELDSPAR => 'Veldspar'],
        ?array $characters = null,
        array $refusedCharacterIds = [],
        ArrayAdapter $readerCache = new ArrayAdapter(),
        array $lootTypeIds = [self::TRITANIUM, self::ROGUE_DRONE_INFESTATION_DATA],
        LoggerInterface $readerLogger = new NullLogger(),
    ): ScanLootSalesProcessor {
        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturnCallback(
            fn (EveToken $token): string => $this->accessTokenOf($token, $refusedCharacterIds),
        );

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createUser(
            $characters ?? [$this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME)],
        ));

        $settings = new UserPveSettings();
        $settings->setLootTypeIds($lootTypeIds);
        $settingsRepository = $this->createStub(UserPveSettingsRepository::class);
        $settingsRepository->method('getOrCreate')->willReturn($settings);

        $incomeRepository = $this->createStub(PveIncomeRepository::class);
        $incomeRepository->method('getImportedTransactionIds')->willReturn([]);

        $typesById = array_map(fn (string $typeName): InvType => $this->createInvType($typeName), $knownTypeNamesById);
        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('find')->willReturnCallback(static fn (mixed $typeId): ?InvType => $typesById[$typeId] ?? null);

        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        $esiClient = new EsiClient($this->createSimulatedEsi($corpProjectResponsesByKey), new TagAwareAdapter(new ArrayAdapter()), $tokenManager, 'https://esi.test/latest', new NullLogger());

        return new ScanLootSalesProcessor(
            security: $security,
            esiClient: $esiClient,
            tokenManager: $tokenManager,
            incomeRepository: $incomeRepository,
            settingsRepository: $settingsRepository,
            invTypeRepository: $invTypeRepository,
            requestStack: $requestStack,
            logger: new NullLogger(),
            corpProjectContributionReader: new CorpProjectContributionReader(
                esiClient: $esiClient,
                tokenManager: $tokenManager,
                cache: $readerCache,
                logger: $readerLogger,
            ),
        );
    }

    private function createInvType(string $typeName): InvType
    {
        $invType = $this->createStub(InvType::class);
        $invType->method('getTypeName')->willReturn($typeName);

        return $invType;
    }

    private function createSimulatedEsi(array $corpProjectResponsesByKey): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use (&$corpProjectResponsesByKey): MockResponse {
            $origin = (string) preg_replace('#^(https://[^/]+).*$#', '$1', $url);
            $key = $this->requestKey($url);

            if (str_starts_with($key, '/corporations/')) {
                $this->corporationProjectRequests[] = [
                    'url' => $url,
                    'normalizedUrl' => $origin . $key,
                    'key' => $key,
                    'headers' => $options['normalized_headers'] ?? [],
                ];
                $plannedResponse = $corpProjectResponsesByKey[$key] ?? null;
                if (is_array($plannedResponse)) {
                    $response = array_shift($corpProjectResponsesByKey[$key]);
                } else {
                    $response = $plannedResponse;
                    unset($corpProjectResponsesByKey[$key]);
                }
                if ($response === null) {
                    $this->fail(sprintf('Unexpected ESI request: %s %s', $method, $url));
                }

                return $response;
            }

            if (str_starts_with($key, '/characters/')) {
                $this->characterRequests[] = $key;

                return $this->jsonResponse([]);
            }

            $this->fail(sprintf('Unexpected ESI request: %s %s', $method, $url));
        });
    }

    private function requestKey(string $url): string
    {
        $path = (string) preg_replace('#^/latest#', '', (string) parse_url($url, PHP_URL_PATH));
        $query = (string) parse_url($url, PHP_URL_QUERY);
        if ($query === '') {
            return $path;
        }
        parse_str($query, $parameters);
        ksort($parameters);

        return $path . '?' . http_build_query($parameters);
    }

    private function jsonResponse(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code' => 200,
            'response_headers' => ['Content-Type' => 'application/json'],
        ]);
    }

    private function esiErrorResponse(int $httpCode): MockResponse
    {
        return new MockResponse('{"error":"esi error ' . $httpCode . '"}', [
            'http_code' => $httpCode,
            'response_headers' => ['Content-Type' => 'application/json'],
        ]);
    }

    private function networkFailure(): MockResponse
    {
        return new MockResponse('', ['error' => 'Connection refused by the simulated ESI']);
    }

    private function projectListPages(array ...$pages): array
    {
        $responses = [];
        $before = null;
        foreach ($pages as $pageIndex => $projects) {
            $nextBefore = 'projects-cursor-' . ($pageIndex + 1);
            $responses[$this->projectsPageKey($before)] = $this->jsonResponse([
                'projects' => $projects,
                'cursor' => ['before' => $nextBefore, 'after' => 'projects-after-' . ($pageIndex + 1)],
            ]);
            $before = $nextBefore;
        }
        $responses[$this->projectsPageKey($before)] = $this->jsonResponse([
            'projects' => [],
            'cursor' => ['after' => 'projects-after-end'],
        ]);

        return $responses;
    }

    private function contributorPages(string $projectId, array ...$pages): array
    {
        $responses = [];
        $before = null;
        foreach ($pages as $pageIndex => $contributors) {
            $nextBefore = 'contributors-cursor-' . ($pageIndex + 1);
            $responses[$this->contributorsPageKey($projectId, $before)] = $this->jsonResponse([
                'contributors' => $contributors,
                'cursor' => ['before' => $nextBefore, 'after' => 'contributors-after-' . ($pageIndex + 1)],
            ]);
            $before = $nextBefore;
        }
        $responses[$this->contributorsPageKey($projectId, $before)] = $this->jsonResponse([
            'contributors' => [],
            'cursor' => ['after' => 'contributors-after-end'],
        ]);

        return $responses;
    }

    private function projectDetails(string $projectId, int $deliveredTypeId, float $rewardPerContribution): array
    {
        return [
            self::PROJECTS_PATH . '/' . $projectId => $this->jsonResponse([
                'configuration' => ['deliver_item' => ['items' => [['type_id' => $deliveredTypeId]]]],
                'contribution' => ['reward_per_contribution' => $rewardPerContribution],
            ]),
        ];
    }

    private function contribution(string $projectId, int $characterId, int $contributedQuantity, \DateTimeImmutable $contributedAt): array
    {
        return [
            self::PROJECTS_PATH . '/' . $projectId . '/contribution/' . $characterId => $this->jsonResponse([
                'contributed' => $contributedQuantity,
                'last_modified' => $contributedAt->format('c'),
            ]),
        ];
    }

    private function projectRecord(string $projectId, string $projectName, string $state, \DateTimeImmutable $lastModified): array
    {
        return [
            'id' => $projectId,
            'name' => $projectName,
            'state' => $state,
            'last_modified' => $lastModified->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function contributorRecord(int $characterId, string $characterName, int $contributedQuantity): array
    {
        return ['id' => $characterId, 'name' => $characterName, 'contributed' => $contributedQuantity];
    }

    private function projectsPageKey(?string $before): string
    {
        return self::PROJECTS_PATH . '?' . ($before !== null ? 'before=' . $before . '&' : '') . 'limit=100&state=All';
    }

    private function contributorsPageKey(string $projectId, ?string $before): string
    {
        return self::PROJECTS_PATH . '/' . $projectId . '/contributors?' . ($before !== null ? 'before=' . $before . '&' : '') . 'limit=100';
    }

    private function requestedPaths(): array
    {
        return array_column($this->corporationProjectRequests, 'key');
    }

    private function requestedAuthorizations(): array
    {
        return array_map(
            static fn (array $request): ?string => $request['headers']['authorization'][0] ?? null,
            $this->corporationProjectRequests,
        );
    }

    private function saleSummaries(array $detectedSales): array
    {
        return array_map(
            static fn (DetectedSaleResource $sale): array => [$sale->transactionId, $sale->characterName, $sale->quantity, $sale->price],
            $detectedSales,
        );
    }

    private function errorSummaries(array $corporationProjectErrors): array
    {
        return array_map(
            static fn (CorporationProjectErrorResource $error): array => [
                $error->corporationName,
                $error->projectName,
                $error->characterName,
                $error->reason,
                $error->httpStatus,
            ],
            $corporationProjectErrors,
        );
    }

    private function tritaniumDrivePaths(int ...$contributionCharacterIds): array
    {
        return [
            '/corporations/98000001/projects?limit=100&state=All',
            '/corporations/98000001/projects?before=projects-cursor-1&limit=100&state=All',
            '/corporations/98000001/projects/a1b2c3d4-project/contributors?limit=100',
            '/corporations/98000001/projects/a1b2c3d4-project/contributors?before=contributors-cursor-1&limit=100',
            '/corporations/98000001/projects/a1b2c3d4-project',
            ...array_map(
                static fn (int $characterId): string => '/corporations/98000001/projects/a1b2c3d4-project/contribution/' . $characterId,
                $contributionCharacterIds,
            ),
        ];
    }

    private function tritaniumDriveResponses(array $contributorRecords, array $contributedQuantityByCharacterId, \DateTimeImmutable $contributedAt): array
    {
        $responses = [
            ...$this->projectListPages([
                $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day')),
            ]),
            ...$this->contributorPages(self::PROJECT_ID, $contributorRecords),
            ...$this->projectDetails(self::PROJECT_ID, self::TRITANIUM, self::REWARD_PER_CONTRIBUTION),
        ];
        foreach ($contributedQuantityByCharacterId as $characterId => $contributedQuantity) {
            $responses = [...$responses, ...$this->contribution(self::PROJECT_ID, $characterId, $contributedQuantity, $contributedAt)];
        }

        return $responses;
    }

    private function accessTokenOf(EveToken $token, array $refusedCharacterIds): string
    {
        $owner = $this->tokenOwners[spl_object_id($token)];
        $this->accessTokenRequests[] = $owner->getEveCharacterId();
        if (!$owner->isAuthValid() || in_array($owner->getEveCharacterId(), $refusedCharacterIds, true)) {
            throw new EveAuthRequiredException((string) $owner->getEveCharacterId());
        }

        return 'access-token-' . $owner->getEveCharacterId();
    }

    private function character(
        int $eveCharacterId,
        string $name,
        int $corporationId,
        string $corporationName,
        bool $authValid = true,
        bool $hasToken = true,
        bool $hasProjectsScope = true,
    ): Character {
        $token = null;
        if ($hasToken) {
            $token = $this->createStub(EveToken::class);
            $token->method('hasScope')->willReturnCallback(
                static fn (string $scope): bool => $scope !== self::PROJECTS_SCOPE || $hasProjectsScope,
            );
        }

        $character = $this->createStub(Character::class);
        $character->method('getEveCharacterId')->willReturn($eveCharacterId);
        $character->method('getCorporationId')->willReturn($corporationId);
        $character->method('getCorporationName')->willReturn($corporationName);
        $character->method('getEveToken')->willReturn($token);
        $character->method('getName')->willReturn($name);
        $character->method('isAuthValid')->willReturn($authValid);

        if ($token !== null) {
            $this->tokenOwners[spl_object_id($token)] = $character;
        }

        return $character;
    }

    private function createUser(array $characters): User
    {
        $user = $this->createStub(User::class);
        $user->method('getCharacters')->willReturn(new ArrayCollection($characters));

        return $user;
    }
}
