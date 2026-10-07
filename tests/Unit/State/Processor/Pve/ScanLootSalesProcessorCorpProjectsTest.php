<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Pve;

use ApiPlatform\Metadata\Post;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Entity\UserPveSettings;
use App\Enum\PveIncomeType;
use App\Repository\PveIncomeRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\UserPveSettingsRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\State\Processor\Pve\ScanLootSalesProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Guard rails for the corporation projects part of the loot sales scan (issue #26).
 *
 * Transport-agnostic: the simulated ESI answers on the path only, whatever the host or the
 * version prefix, and serves both the EsiClient and a direct HttpClientInterface if the
 * processor still takes one. These tests must stay green before and after the move to EsiClient.
 */
#[CoversClass(ScanLootSalesProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
class ScanLootSalesProcessorCorpProjectsTest extends TestCase
{
    private const EVE_CHARACTER_ID = 2112000001;
    private const CORPORATION_ID = 98000001;
    private const PROJECT_ID = 'a1b2c3d4-project';
    private const PROJECT_NAME = 'Tritanium Drive';
    private const TRITANIUM = 34;
    private const REWARD_PER_CONTRIBUTION = 12.5;
    private const CONTRIBUTED_QUANTITY = 1000;
    private const ACCESS_TOKEN = 'access-token-2112000001';
    private const COMPATIBILITY_DATE = '2025-12-16';

    /** @var list<array{url: string, headers: array<string, list<string>>}> */
    private array $corporationProjectRequests = [];

    public function testCorpProjectContributionDetectedAsSaleWorthQuantityTimesRewardPerContribution(): void
    {
        $contributedAt = new \DateTimeImmutable('-2 days');
        $processor = $this->createProcessor($this->corpProjectResponses($contributedAt));

        $result = $processor->process(null, new Post());

        $this->assertSame(1, $result->scannedProjects);
        $this->assertCount(1, $result->detectedSales);
        $sale = $result->detectedSales[0];
        $this->assertSame(-abs(crc32(self::PROJECT_ID . '_' . self::EVE_CHARACTER_ID)), $sale->transactionId);
        $this->assertSame(0, $sale->contractId);
        $this->assertSame(self::PROJECT_ID, $sale->projectId);
        $this->assertSame(PveIncomeType::CorpProject->value, $sale->type);
        $this->assertSame(self::TRITANIUM, $sale->typeId);
        $this->assertSame('1000x Tritanium', $sale->typeName);
        $this->assertSame(1000, $sale->quantity);
        $this->assertSame(12500.0, $sale->price);
        $this->assertSame($contributedAt->format('Y-m-d'), $sale->dateIssued);
        $this->assertSame('Pilot One', $sale->characterName);
        $this->assertSame('corp_project', $sale->source);
        $this->assertSame(self::PROJECT_NAME, $sale->projectName);
    }

    public function testCorpProjectRequestsSendCompatibilityDateAndCharacterBearerToken(): void
    {
        $processor = $this->createProcessor($this->corpProjectResponses(new \DateTimeImmutable('-2 days')));

        $processor->process(null, new Post());

        $requestedPaths = array_map(
            static fn (array $request): string => (string) preg_replace('#^https://[^/]+(/latest)?#', '', $request['url']),
            $this->corporationProjectRequests,
        );
        $this->assertSame([
            '/corporations/98000001/projects',
            '/corporations/98000001/projects/a1b2c3d4-project/contributors',
            '/corporations/98000001/projects/a1b2c3d4-project',
            '/corporations/98000001/projects/a1b2c3d4-project/contribution/2112000001',
        ], $requestedPaths);
        foreach ($this->corporationProjectRequests as $request) {
            $this->assertSame(['X-Compatibility-Date: ' . self::COMPATIBILITY_DATE], $request['headers']['x-compatibility-date'] ?? null, $request['url']);
            $this->assertSame(['Authorization: Bearer ' . self::ACCESS_TOKEN], $request['headers']['authorization'] ?? null, $request['url']);
        }
    }

    // ---------------------------------------------------------------
    // RED: issue #26, corporation projects go through EsiClient::getUnversioned()
    // ---------------------------------------------------------------

    public function testConstructorNoLongerTakesAnHttpClient(): void
    {
        $constructor = (new \ReflectionClass(ScanLootSalesProcessor::class))->getConstructor();
        $this->assertNotNull($constructor);

        $httpClientParameters = array_filter(
            $constructor->getParameters(),
            static fn (\ReflectionParameter $parameter): bool => (string) $parameter->getType() === HttpClientInterface::class,
        );
        $this->assertSame([], array_values(array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $httpClientParameters,
        )));
    }

    public function testCorpProjectRequestsTargetUnversionedOriginOfConfiguredEsiBaseUrl(): void
    {
        $processor = $this->createProcessor($this->corpProjectResponses(new \DateTimeImmutable('-2 days')));

        $processor->process(null, new Post());

        // EsiClient is configured with https://esi.test/latest: same host, no /latest prefix.
        $this->assertSame([
            'https://esi.test/corporations/98000001/projects',
            'https://esi.test/corporations/98000001/projects/a1b2c3d4-project/contributors',
            'https://esi.test/corporations/98000001/projects/a1b2c3d4-project',
            'https://esi.test/corporations/98000001/projects/a1b2c3d4-project/contribution/2112000001',
        ], array_column($this->corporationProjectRequests, 'url'));
    }

    public function testRateLimitedCorpProjectsListIsReplayedOnceAndContributionStillDetected(): void
    {
        $responses = $this->corpProjectResponses(new \DateTimeImmutable('-2 days'));
        $responses['/corporations/98000001/projects'] = [
            $this->rateLimitedResponse(),
            $responses['/corporations/98000001/projects'],
        ];
        $processor = $this->createProcessor($responses);

        $result = $processor->process(null, new Post());

        $this->assertSame(1, $result->scannedProjects);
        $this->assertCount(1, $result->detectedSales);
        $this->assertSame(12500.0, $result->detectedSales[0]->price);
        $this->assertSame(1000, $result->detectedSales[0]->quantity);
        $requestedPaths = array_map(
            static fn (array $request): string => (string) preg_replace('#^https://[^/]+(/latest)?#', '', $request['url']),
            $this->corporationProjectRequests,
        );
        $this->assertSame([
            '/corporations/98000001/projects',
            '/corporations/98000001/projects',
            '/corporations/98000001/projects/a1b2c3d4-project/contributors',
            '/corporations/98000001/projects/a1b2c3d4-project',
            '/corporations/98000001/projects/a1b2c3d4-project/contribution/2112000001',
        ], $requestedPaths);
    }

    public function testUnavailableCorpProjectsListYieldsNoProjectSaleAndNoScannedProject(): void
    {
        $processor = $this->createProcessor([
            '/corporations/98000001/projects' => new MockResponse('{"error":"Internal error"}', ['http_code' => 500]),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertSame(0, $result->scannedProjects);
        $this->assertSame([], $result->detectedSales);
    }

    /**
     * @return array<string, MockResponse>
     */
    private function corpProjectResponses(\DateTimeImmutable $contributedAt): array
    {
        $projectPath = '/corporations/98000001/projects/a1b2c3d4-project';

        return [
            '/corporations/98000001/projects' => $this->jsonResponse(['projects' => [
                ['id' => self::PROJECT_ID, 'name' => self::PROJECT_NAME, 'state' => 'Active'],
            ]]),
            $projectPath . '/contributors' => $this->jsonResponse(['contributors' => [
                ['character_id' => self::EVE_CHARACTER_ID],
            ]]),
            $projectPath => $this->jsonResponse([
                'configuration' => ['deliver_item' => ['items' => [['type_id' => self::TRITANIUM]]]],
                'contribution' => ['reward_per_contribution' => self::REWARD_PER_CONTRIBUTION],
            ]),
            $projectPath . '/contribution/2112000001' => $this->jsonResponse([
                'contributed' => self::CONTRIBUTED_QUANTITY,
                'last_modified' => $contributedAt->format('c'),
            ]),
        ];
    }

    /**
     * @param array<string, MockResponse|list<MockResponse>> $corpProjectResponsesByPath
     */
    private function createProcessor(array $corpProjectResponsesByPath): ScanLootSalesProcessor
    {
        $simulatedEsi = $this->createSimulatedEsi($corpProjectResponsesByPath);

        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn(self::ACCESS_TOKEN);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createUserWithCorporationCharacter());

        $settings = new UserPveSettings();
        $settings->setLootTypeIds([self::TRITANIUM]);
        $settingsRepository = $this->createStub(UserPveSettingsRepository::class);
        $settingsRepository->method('getOrCreate')->willReturn($settings);

        $incomeRepository = $this->createStub(PveIncomeRepository::class);
        $incomeRepository->method('getImportedTransactionIds')->willReturn([]);

        $tritanium = $this->createStub(InvType::class);
        $tritanium->method('getTypeName')->willReturn('Tritanium');
        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('find')->willReturn($tritanium);

        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        $arguments = [
            'security' => $security,
            'esiClient' => new EsiClient($simulatedEsi, new ArrayAdapter(), $tokenManager, 'https://esi.test/latest', new NullLogger()),
            'tokenManager' => $tokenManager,
            'incomeRepository' => $incomeRepository,
            'settingsRepository' => $settingsRepository,
            'invTypeRepository' => $invTypeRepository,
            'requestStack' => $requestStack,
            'logger' => new NullLogger(),
            'httpClient' => $simulatedEsi,
            'cache' => new ArrayAdapter(),
        ];

        // The constructor loses its HttpClientInterface with issue #26: pass only what it declares.
        $constructor = (new \ReflectionClass(ScanLootSalesProcessor::class))->getConstructor();
        $this->assertNotNull($constructor);
        $declaredNames = array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $constructor->getParameters());

        return new ScanLootSalesProcessor(...array_intersect_key($arguments, array_flip($declaredNames)));
    }

    /**
     * Wallet transactions and contracts answer empty; corporation project paths answer from the map,
     * a list of responses being served in order, one per request.
     *
     * @param array<string, MockResponse|list<MockResponse>> $corpProjectResponsesByPath
     */
    private function createSimulatedEsi(array $corpProjectResponsesByPath): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use (&$corpProjectResponsesByPath): MockResponse {
            $path = (string) preg_replace('#^https://[^/]+(/latest)?#', '', (string) strtok($url, '?'));

            if (str_starts_with($path, '/corporations/')) {
                $this->corporationProjectRequests[] = ['url' => $url, 'headers' => $options['normalized_headers'] ?? []];
                $response = $corpProjectResponsesByPath[$path] ?? null;
                if (is_array($response)) {
                    $response = array_shift($corpProjectResponsesByPath[$path]);
                }
                if ($response === null) {
                    $this->fail(sprintf('Unexpected ESI request: %s %s', $method, $url));
                }

                return $response;
            }

            if (str_starts_with($path, '/characters/')) {
                return $this->jsonResponse([]);
            }

            $this->fail(sprintf('Unexpected ESI request: %s %s', $method, $url));
        });
    }

    /**
     * @param array<int|string, mixed> $body
     */
    private function jsonResponse(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code' => 200,
            'response_headers' => ['Content-Type' => 'application/json'],
        ]);
    }

    private function rateLimitedResponse(): MockResponse
    {
        return new MockResponse('{"error":"rate limited"}', [
            'http_code' => 429,
            'response_headers' => [
                'Content-Type' => 'application/json',
                'Retry-After' => '0',
                'X-Esi-Error-Limit-Remain' => '100',
                'X-Esi-Error-Limit-Reset' => '0',
            ],
        ]);
    }

    private function createUserWithCorporationCharacter(): User
    {
        $token = $this->createStub(EveToken::class);

        $character = $this->createStub(Character::class);
        $character->method('getEveCharacterId')->willReturn(self::EVE_CHARACTER_ID);
        $character->method('getCorporationId')->willReturn(self::CORPORATION_ID);
        $character->method('getEveToken')->willReturn($token);
        $character->method('getName')->willReturn('Pilot One');

        $user = $this->createStub(User::class);
        $user->method('getCharacters')->willReturn(new ArrayCollection([$character]));

        return $user;
    }
}
