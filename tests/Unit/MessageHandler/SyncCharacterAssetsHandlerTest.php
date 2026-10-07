<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Enum\AuthStatus;
use App\Message\SyncCharacterAssets;
use App\MessageHandler\SyncCharacterAssetsHandler;
use App\Repository\CachedAssetRepository;
use App\Repository\CachedStructureRepository;
use App\Repository\CharacterRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\ESI\AssetsService;
use App\Service\ESI\CorporationService;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Sync\AssetsSyncService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Whole chain from the handler down to EVE SSO: only the boundaries (HTTP, database, Mercure hub, bus) are doubled.
 */
#[CoversClass(SyncCharacterAssetsHandler::class)]
#[AllowMockObjectsWithoutExpectations]
final class SyncCharacterAssetsHandlerTest extends TestCase
{
    private const string SSO_TOKEN_URL = 'https://login.eveonline.com/v2/oauth/token';
    private const string CHARACTER_UUID = '0199a1f0-0000-7000-8000-000000000001';
    private const int EVE_CHARACTER_ID = 2_112_000_001;
    private const string INVALID_GRANT_BODY = '{"error":"invalid_grant","error_description":"Invalid refresh token. Character grant missing/expired."}';

    /** @var list<string> */
    private array $requestedUrls = [];
    /** @var list<AuthStatus> */
    private array $authStatusesAtFlush = [];

    public function testRevokedEveGrantDuringSyncIsNotRetriedByMessenger(): void
    {
        $user = new User();
        $message = new SyncCharacterAssets(self::CHARACTER_UUID);

        $failure = $this->handle($message, $this->characterWithExpiredAccessToken($user));

        self::assertSame([self::SSO_TOKEN_URL], $this->requestedUrls, 'no ESI call must be attempted without a fresh token');
        if ($failure !== null) {
            self::assertFalse(
                MessengerRetryProbe::wouldRetry($message, $failure),
                'a revoked grant must not be retried, got ' . $failure::class . ': ' . $failure->getMessage(),
            );
        }
    }

    public function testRevokedEveGrantDuringSyncPersistsTheUserAuthStatusAsInvalid(): void
    {
        $user = new User();

        $this->handle(new SyncCharacterAssets(self::CHARACTER_UUID), $this->characterWithExpiredAccessToken($user));

        self::assertSame(AuthStatus::Invalid, $user->getAuthStatus());
        self::assertSame(AuthStatus::Invalid, end($this->authStatusesAtFlush), 'the invalid auth status must be flushed');
    }

    private function handle(SyncCharacterAssets $message, Character $character): ?\Throwable
    {
        $user = $character->getUser();
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willReturnCallback(function () use ($user): void {
            $this->authStatusesAtFlush[] = $user->getAuthStatus();
        });

        $httpClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->requestedUrls[] = $url;

            return $url === self::SSO_TOKEN_URL
                ? new MockResponse(self::INVALID_GRANT_BODY, ['http_code' => 400])
                : new MockResponse('[]', ['http_code' => 200]);
        });
        $tokenManager = new TokenManager(self::encryptionKey(), $httpClient, $entityManager, 'test_client_id', 'test_client_secret');
        $character->getEveToken()->setRefreshTokenEncrypted($tokenManager->encryptRefreshToken('revoked-refresh-token'));

        $esiClient = new EsiClient($httpClient, new ArrayAdapter(), $tokenManager, 'https://esi.evetech.net/latest', new NullLogger());
        $assetsService = new AssetsService(
            $esiClient,
            $this->createStub(MapSolarSystemRepository::class),
            $this->createStub(CachedStructureRepository::class),
            $entityManager,
            new NullLogger(),
        );
        $characterRepository = $this->createStub(CharacterRepository::class);
        $characterRepository->method('find')->willReturn($character);
        $assetsSyncService = new AssetsSyncService(
            $assetsService,
            $this->createStub(CorporationService::class),
            $this->createStub(CachedAssetRepository::class),
            $characterRepository,
            $this->createStub(CorpAssetVisibilityRepository::class),
            $entityManager,
            new NullLogger(),
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
        );
        $handler = new SyncCharacterAssetsHandler($characterRepository, $assetsSyncService, $this->createStub(MessageBusInterface::class), new NullLogger());

        try {
            $handler($message);
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    private function characterWithExpiredAccessToken(User $user): Character
    {
        $character = (new Character())
            ->setEveCharacterId(self::EVE_CHARACTER_ID)
            ->setName('Test Pilot');
        $user->addCharacter($character);
        $character->setEveToken((new EveToken())
            ->setAccessToken('expired-access-token')
            ->setRefreshTokenEncrypted('set-in-handle')
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('-1 minute'))
            ->setScopes(['esi-assets.read_assets.v1']));

        return $character;
    }

    private static function encryptionKey(): string
    {
        return base64_encode(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }
}
