<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\EveTokenDto;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Enum\AuthStatus;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;
use App\Service\ESI\TokenManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(TokenManager::class)]
class TokenManagerTest extends TestCase
{
    private const string SSO_TOKEN_URL = 'https://login.eveonline.com/v2/oauth/token';
    private const int EVE_CHARACTER_ID = 2_112_000_001;
    private const string STORED_REFRESH_TOKEN = 'stored-refresh-token';
    private const string STORED_ACCESS_TOKEN = 'stored-access-token';
    /** One refresh lock per character, shared by the app and the worker through LOCK_DSN */
    private const string REFRESH_LOCK_KEY = 'eve_token_refresh_2112000001';
    private const string ACCESS_TOKEN_REFRESHED_BY_ANOTHER_PROCESS = 'access-token-refreshed-by-the-worker';
    /** Body EVE SSO returns when the refresh token was revoked by the player or has expired */
    private const string INVALID_GRANT_BODY = '{"error":"invalid_grant","error_description":"Invalid refresh token. Character grant missing/expired."}';

    private TokenManager $tokenManager;
    private string $encryptionKey;

    protected function setUp(): void
    {
        $this->encryptionKey = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

        $this->tokenManager = new TokenManager(
            $this->encryptionKey,
            $this->createStub(HttpClientInterface::class),
            $this->createStub(EntityManagerInterface::class),
            'test_client_id',
            'test_client_secret',
            new LockFactory(new InMemoryStore()),
        );
    }

    public function testEncryptDecryptRefreshToken(): void
    {
        $original = 'refresh_token_value_12345';

        $encrypted = $this->tokenManager->encryptRefreshToken($original);
        $decrypted = $this->tokenManager->decryptRefreshToken($encrypted);

        $this->assertSame($original, $decrypted);
        $this->assertNotSame($original, $encrypted);
    }

    public function testEncryptedTokenIsDifferentEachTime(): void
    {
        $original = 'refresh_token_value';

        $encrypted1 = $this->tokenManager->encryptRefreshToken($original);
        $encrypted2 = $this->tokenManager->encryptRefreshToken($original);

        // Due to random nonce, encryptions should differ
        $this->assertNotSame($encrypted1, $encrypted2);

        // But both should decrypt to the same value
        $this->assertSame($original, $this->tokenManager->decryptRefreshToken($encrypted1));
        $this->assertSame($original, $this->tokenManager->decryptRefreshToken($encrypted2));
    }

    public function testIsAccessTokenExpired(): void
    {
        $expiredToken = new EveToken();
        $expiredToken->setAccessTokenExpiresAt(new \DateTimeImmutable('-1 hour'));

        $this->assertTrue($this->tokenManager->isAccessTokenExpired($expiredToken));

        $validToken = new EveToken();
        $validToken->setAccessTokenExpiresAt(new \DateTimeImmutable('+1 hour'));

        $this->assertFalse($this->tokenManager->isAccessTokenExpired($validToken));
    }

    public function testIsAccessTokenExpiringSoon(): void
    {
        // Token expiring in 2 minutes (less than default 5 minutes threshold)
        $expiringSoonToken = new EveToken();
        $expiringSoonToken->setAccessTokenExpiresAt(new \DateTimeImmutable('+2 minutes'));

        $this->assertTrue($this->tokenManager->isAccessTokenExpiringSoon($expiringSoonToken));

        // Token expiring in 10 minutes (more than threshold)
        $notExpiringSoonToken = new EveToken();
        $notExpiringSoonToken->setAccessTokenExpiresAt(new \DateTimeImmutable('+10 minutes'));

        $this->assertFalse($this->tokenManager->isAccessTokenExpiringSoon($notExpiringSoonToken));
    }

    public function testDecryptWithInvalidDataThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->tokenManager->decryptRefreshToken('invalid_base64_!@#$');
    }

    public function testRefreshWithRevokedRefreshTokenThrowsEveAuthRequiredForTheCharacter(): void
    {
        $tokenManager = $this->tokenManagerAnsweringWith(
            new MockResponse(self::INVALID_GRANT_BODY, ['http_code' => 400]),
            $this->createStub(EntityManagerInterface::class),
        );

        try {
            $tokenManager->getValidAccessToken($this->storedTokenOf(new User()));
            self::fail('A revoked refresh token must raise EveAuthRequiredException');
        } catch (EveAuthRequiredException $e) {
            self::assertSame('2112000001', $e->characterId);
        }
    }

    public function testRefreshWithRevokedRefreshTokenPersistsTheUserAuthStatusAsInvalid(): void
    {
        $user = new User();
        $authStatusesAtFlush = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::atLeastOnce())->method('flush')
            ->willReturnCallback(function () use ($user, &$authStatusesAtFlush): void {
                $authStatusesAtFlush[] = $user->getAuthStatus();
            });
        $tokenManager = $this->tokenManagerAnsweringWith(
            new MockResponse(self::INVALID_GRANT_BODY, ['http_code' => 400]),
            $entityManager,
        );

        try {
            $tokenManager->getValidAccessToken($this->storedTokenOf($user));
        } catch (\Throwable) {
            // the exception type is covered by the previous test
        }

        self::assertSame(AuthStatus::Invalid, $user->getAuthStatus());
        self::assertSame(AuthStatus::Invalid, end($authStatusesAtFlush), 'the invalid auth status must be flushed');
    }

    public function testRefreshWhenSsoAnswers503IsNotAnAuthErrorAndChainsTheOriginalException(): void
    {
        $user = new User();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');
        $tokenManager = $this->tokenManagerAnsweringWith(
            new MockResponse('Service Unavailable', ['http_code' => 503]),
            $entityManager,
        );

        $exception = $this->refreshFailure($tokenManager, $this->storedTokenOf($user));

        self::assertInstanceOf(EsiApiException::class, $exception);
        // 401 is what EsiAuthFailureListener/front treat as "re-login required": an SSO outage is not that
        self::assertSame(503, $exception->statusCode);
        self::assertInstanceOf(ServerExceptionInterface::class, $exception->getPrevious());
        self::assertSame(AuthStatus::Valid, $user->getAuthStatus());
    }

    public function testRefreshOnNetworkErrorIsNotAnAuthErrorAndChainsTheOriginalException(): void
    {
        $user = new User();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');
        $tokenManager = $this->tokenManagerAnsweringWith(
            new MockResponse('', ['error' => 'Could not resolve host: login.eveonline.com']),
            $entityManager,
        );

        $exception = $this->refreshFailure($tokenManager, $this->storedTokenOf($user));

        self::assertInstanceOf(EsiApiException::class, $exception);
        // 0 = "no HTTP response", same convention as EsiClient network errors (EsiAuthFailureListener maps it to 502)
        self::assertSame(0, $exception->statusCode);
        self::assertInstanceOf(TransportExceptionInterface::class, $exception->getPrevious());
        self::assertSame(AuthStatus::Valid, $user->getAuthStatus());
    }

    public function testSuccessfulRefreshStoresTheNewAccessTokenAndReEncryptsTheRotatedRefreshToken(): void
    {
        $user = new User();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');
        $requests = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options['body']];

            return new MockResponse(json_encode([
                'access_token' => 'new-access-token',
                'expires_in' => 1199,
                'token_type' => 'Bearer',
                'refresh_token' => 'rotated-refresh-token',
                'scope' => 'esi-assets.read_assets.v1 esi-wallet.read_character_wallet.v1',
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $tokenManager = $this->tokenManagerWith($httpClient, $entityManager);
        $token = $this->storedTokenOf($user);

        $accessToken = $tokenManager->getValidAccessToken($token);

        self::assertSame('new-access-token', $accessToken);
        self::assertSame([['POST', self::SSO_TOKEN_URL, 'grant_type=refresh_token&refresh_token=' . self::STORED_REFRESH_TOKEN]], $requests);
        self::assertSame('new-access-token', $token->getAccessToken());
        self::assertSame('rotated-refresh-token', $tokenManager->decryptRefreshToken($token->getRefreshTokenEncrypted()));
        self::assertSame(['esi-assets.read_assets.v1', 'esi-wallet.read_character_wallet.v1'], $token->getScopes());
        self::assertEqualsWithDelta((new \DateTimeImmutable('+1199 seconds'))->getTimestamp(), $token->getAccessTokenExpiresAt()->getTimestamp(), 5);
        self::assertSame(AuthStatus::Valid, $user->getAuthStatus());
    }

    /** invalid_client = our app credentials are wrong: a configuration error, not a revoked grant of this player */
    public function testRefreshWhenSsoAnswers400InvalidClientIsNotAnAuthErrorAndKeepsTheUserValid(): void
    {
        $this->assertRefreshFailureLeavesTheUserValid(
            new MockResponse('{"error":"invalid_client","error_description":"Client could not be authenticated."}', ['http_code' => 400]),
            400,
        );
    }

    /** Even with an invalid_grant body, only a 400 means "grant revoked" */
    public function testRefreshWhenSsoAnswers401IsNotAnAuthErrorAndKeepsTheUserValid(): void
    {
        $this->assertRefreshFailureLeavesTheUserValid(
            new MockResponse(self::INVALID_GRANT_BODY, ['http_code' => 401]),
            401,
        );
    }

    public function testRefreshWhenSsoAnswers400WithANonJsonBodyIsNotAnAuthErrorAndKeepsTheUserValid(): void
    {
        $this->assertRefreshFailureLeavesTheUserValid(
            new MockResponse('<html>Bad Request</html>', ['http_code' => 400]),
            400,
        );
    }

    public function testRefreshWithRevokedRefreshTokenOfACharacterWithoutUserStillThrowsEveAuthRequired(): void
    {
        $token = $this->storedTokenOf(new User());
        $token->getCharacter()->setUser(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');
        $tokenManager = $this->tokenManagerAnsweringWith(
            new MockResponse(self::INVALID_GRANT_BODY, ['http_code' => 400]),
            $entityManager,
        );

        $exception = $this->refreshFailure($tokenManager, $token);

        self::assertInstanceOf(EveAuthRequiredException::class, $exception);
        self::assertSame('2112000001', $exception->characterId);
    }

    public function testSuccessfulRefreshAuthenticatesTheApplicationWithBasicCredentialsAsAFormRequest(): void
    {
        $headers = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$headers): MockResponse {
            $headers = $options['normalized_headers'];

            return $this->ssoTokenResponse(['scope' => 'esi-assets.read_assets.v1']);
        });
        $tokenManager = $this->tokenManagerWith($httpClient, $this->createStub(EntityManagerInterface::class));

        $tokenManager->getValidAccessToken($this->storedTokenOf(new User()));

        self::assertSame(['Content-Type: application/x-www-form-urlencoded'], $headers['content-type']);
        self::assertSame(
            ['Authorization: Basic ' . base64_encode('test_client_id:test_client_secret')],
            $headers['authorization'],
        );
    }

    public function testSuccessfulRefreshWithoutRotatedRefreshTokenKeepsTheStoredOne(): void
    {
        $tokenManager = $this->tokenManagerAnsweringWith(
            $this->ssoTokenResponse(['scope' => 'esi-assets.read_assets.v1']),
            $this->createStub(EntityManagerInterface::class),
        );
        $token = $this->storedTokenOf(new User());

        $tokenManager->getValidAccessToken($token);

        self::assertSame(self::STORED_REFRESH_TOKEN, $tokenManager->decryptRefreshToken($token->getRefreshTokenEncrypted()));
    }

    public function testSuccessfulRefreshWithEmptyScopeTakesTheScopesFromTheAccessTokenJwt(): void
    {
        $accessToken = $this->jwtWithScopes(['esi-industry.read_character_jobs.v1', 'esi-skills.read_skills.v1']);
        $tokenManager = $this->tokenManagerAnsweringWith(
            $this->ssoTokenResponse(['access_token' => $accessToken, 'scope' => '']),
            $this->createStub(EntityManagerInterface::class),
        );
        $token = $this->storedTokenOf(new User())->setScopes(['esi-assets.read_assets.v1']);

        $tokenManager->getValidAccessToken($token);

        self::assertSame(['esi-industry.read_character_jobs.v1', 'esi-skills.read_skills.v1'], $token->getScopes());
    }

    public function testSuccessfulRefreshWithoutScopeAnywhereKeepsTheStoredScopes(): void
    {
        $tokenManager = $this->tokenManagerAnsweringWith(
            $this->ssoTokenResponse(['access_token' => 'opaque-access-token']),
            $this->createStub(EntityManagerInterface::class),
        );
        $token = $this->storedTokenOf(new User())->setScopes(['esi-assets.read_assets.v1']);

        $tokenManager->getValidAccessToken($token);

        self::assertSame(['esi-assets.read_assets.v1'], $token->getScopes());
    }

    public function testRefreshFailureMessagesNameTheSsoCauseAndKeepTheOriginalMessage(): void
    {
        $ssoRejection = $this->refreshFailure(
            $this->tokenManagerAnsweringWith(new MockResponse('{"error":"invalid_client"}', ['http_code' => 400]), $this->createStub(EntityManagerInterface::class)),
            $this->storedTokenOf(new User()),
        );
        $networkError = $this->refreshFailure(
            $this->tokenManagerAnsweringWith(new MockResponse('', ['error' => 'Could not resolve host: login.eveonline.com']), $this->createStub(EntityManagerInterface::class)),
            $this->storedTokenOf(new User()),
        );

        self::assertSame('EVE SSO rejected the token refresh: ' . $ssoRejection->getPrevious()->getMessage(), $ssoRejection->getMessage());
        self::assertStringContainsString('400', $ssoRejection->getPrevious()->getMessage());
        self::assertSame('Network error while refreshing token: ' . $networkError->getPrevious()->getMessage(), $networkError->getMessage());
        self::assertStringContainsString('Could not resolve host', $networkError->getPrevious()->getMessage());
    }

    public function testRefreshWithRevokedRefreshTokenNotAttachedToAnyCharacterIsALogicError(): void
    {
        $token = (new EveToken())
            ->setAccessToken(self::STORED_ACCESS_TOKEN)
            ->setRefreshTokenEncrypted($this->tokenManager->encryptRefreshToken(self::STORED_REFRESH_TOKEN))
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('-1 minute'));
        $tokenManager = $this->tokenManagerAnsweringWith(
            new MockResponse(self::INVALID_GRANT_BODY, ['http_code' => 400]),
            $this->createStub(EntityManagerInterface::class),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Revoked EVE token is not attached to any character');

        $tokenManager->getValidAccessToken($token);
    }

    public function testDecryptWithAnotherEncryptionKeyThrowsException(): void
    {
        $encryptedWithAnotherKey = (new TokenManager(
            base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            $this->createStub(HttpClientInterface::class),
            $this->createStub(EntityManagerInterface::class),
            'test_client_id',
            'test_client_secret',
            new LockFactory(new InMemoryStore()),
        ))->encryptRefreshToken(self::STORED_REFRESH_TOKEN);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to decrypt token');

        $this->tokenManager->decryptRefreshToken($encryptedWithAnotherKey);
    }

    public function testEncryptionKeyOfTheWrongLengthIsRejected(): void
    {
        $tokenManager = new TokenManager(
            base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES - 1)),
            $this->createStub(HttpClientInterface::class),
            $this->createStub(EntityManagerInterface::class),
            'test_client_id',
            'test_client_secret',
            new LockFactory(new InMemoryStore()),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid encryption key');

        $tokenManager->encryptRefreshToken(self::STORED_REFRESH_TOKEN);
    }

    public function testAccessTokenIsExpiringSoonWithinFiveMinutesByDefault(): void
    {
        $expiringIn300Seconds = (new EveToken())->setAccessTokenExpiresAt(new \DateTimeImmutable('+300 seconds'));
        $expiringIn301Seconds = (new EveToken())->setAccessTokenExpiresAt(new \DateTimeImmutable('+301 seconds'));

        self::assertTrue($this->tokenManager->isAccessTokenExpiringSoon($expiringIn300Seconds));
        self::assertFalse($this->tokenManager->isAccessTokenExpiringSoon($expiringIn301Seconds));
    }

    public function testExtractScopesFromJwtWithSpaceSeparatedScpSkipsEmptyEntries(): void
    {
        self::assertSame(
            ['esi-assets.read_assets.v1', 'esi-skills.read_skills.v1'],
            $this->tokenManager->extractScopesFromJwt($this->jwtWithPayload(['scp' => ' esi-assets.read_assets.v1  esi-skills.read_skills.v1'])),
        );
    }

    public function testExtractScopesFromJwtWithScpListSkipsNonStringAndEmptyEntries(): void
    {
        self::assertSame(
            ['esi-assets.read_assets.v1', 'esi-skills.read_skills.v1'],
            $this->tokenManager->extractScopesFromJwt($this->jwtWithPayload(['scp' => ['', 'esi-assets.read_assets.v1', 42, 'esi-skills.read_skills.v1']])),
        );
    }

    public function testExtractScopesFromJwtWithoutReadablePayloadReturnsNoScope(): void
    {
        self::assertSame([], $this->tokenManager->extractScopesFromJwt('header.not-json.signature'));
        self::assertSame([], $this->tokenManager->extractScopesFromJwt('not-a-jwt'));
        self::assertSame([], $this->tokenManager->extractScopesFromJwt($this->jwtWithPayload(['sub' => 'CHARACTER:EVE:2112000001'])));
        self::assertSame([], $this->tokenManager->extractScopesFromJwt($this->jwtWithPayload(['scp' => 42])));
    }

    public function testGetValidAccessTokenReturnsTheStoredAccessTokenWithoutCallingSsoWhileFarFromExpiry(): void
    {
        $httpClient = new MockHttpClient([]);
        $token = $this->storedTokenOf(new User())->setAccessTokenExpiresAt(new \DateTimeImmutable('+1 hour'));

        $accessToken = $this->tokenManagerWith($httpClient, $this->createStub(EntityManagerInterface::class))->getValidAccessToken($token);

        self::assertSame(self::STORED_ACCESS_TOKEN, $accessToken);
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testGetValidAccessTokenRefreshesAnExpiringAccessToken(): void
    {
        $tokenManager = $this->tokenManagerAnsweringWith(
            $this->ssoTokenResponse(['scope' => 'esi-assets.read_assets.v1']),
            $this->createStub(EntityManagerInterface::class),
        );

        self::assertSame('new-access-token', $tokenManager->getValidAccessToken($this->storedTokenOf(new User())));
    }

    public function testGetValidAccessTokenCallsSsoWhileHoldingTheRefreshLockOfTheCharacter(): void
    {
        $lockStore = new InMemoryStore();
        $lockHeldDuringSsoCall = [];
        $httpClient = new MockHttpClient(function () use ($lockStore, &$lockHeldDuringSsoCall): MockResponse {
            $lockHeldDuringSsoCall[] = $this->refreshLockIsHeldIn($lockStore);

            return $this->ssoTokenResponse(['scope' => 'esi-assets.read_assets.v1']);
        });
        $tokenManager = $this->tokenManagerWith($httpClient, $this->createStub(EntityManagerInterface::class), new LockFactory($lockStore));

        $tokenManager->getValidAccessToken($this->storedTokenOf(new User()));

        self::assertSame([true], $lockHeldDuringSsoCall, 'the SSO refresh must run under the lock eve_token_refresh_<eveCharacterId>');
    }

    public function testGetValidAccessTokenReleasesTheRefreshLockOnceTheTokenIsRefreshed(): void
    {
        $lockStore = new InMemoryStore();
        $tokenManager = $this->tokenManagerWith(
            new MockHttpClient($this->ssoTokenResponse(['scope' => 'esi-assets.read_assets.v1'])),
            $this->createStub(EntityManagerInterface::class),
            new LockFactory($lockStore),
        );

        $tokenManager->getValidAccessToken($this->storedTokenOf(new User()));

        self::assertFalse($this->refreshLockIsHeldIn($lockStore));
    }

    public function testGetValidAccessTokenReleasesTheRefreshLockWhenTheAuthorizationIsRevoked(): void
    {
        $lockStore = new InMemoryStore();
        $tokenManager = $this->tokenManagerWith(
            new MockHttpClient(new MockResponse(self::INVALID_GRANT_BODY, ['http_code' => 400])),
            $this->createStub(EntityManagerInterface::class),
            new LockFactory($lockStore),
        );

        $exception = $this->refreshFailure($tokenManager, $this->storedTokenOf(new User()));

        self::assertInstanceOf(EveAuthRequiredException::class, $exception);
        self::assertFalse($this->refreshLockIsHeldIn($lockStore));
    }

    /** A non-blocking acquire would let a second process go on to the SSO with a refresh token already rotated */
    public function testGetValidAccessTokenWaitsForTheRefreshLockAndReleasesItExplicitly(): void
    {
        $tokenManager = $this->tokenManagerWith(
            new MockHttpClient($this->ssoTokenResponse(['scope' => 'esi-assets.read_assets.v1'])),
            $this->createStub(EntityManagerInterface::class),
            $this->lockFactoryExpectingABlockingAcquireAndOneExplicitRelease(),
        );

        self::assertSame('new-access-token', $tokenManager->getValidAccessToken($this->storedTokenOf(new User())));
    }

    public function testGetValidAccessTokenReleasesTheRefreshLockExplicitlyWhenTheAuthorizationIsRevoked(): void
    {
        $tokenManager = $this->tokenManagerWith(
            new MockHttpClient(new MockResponse(self::INVALID_GRANT_BODY, ['http_code' => 400])),
            $this->createStub(EntityManagerInterface::class),
            $this->lockFactoryExpectingABlockingAcquireAndOneExplicitRelease(),
        );

        $exception = $this->refreshFailure($tokenManager, $this->storedTokenOf(new User()));

        self::assertInstanceOf(EveAuthRequiredException::class, $exception);
    }

    public function testForceRefreshCallsSsoEvenWhenTheAccessTokenIsStillValid(): void
    {
        $httpClient = new MockHttpClient($this->ssoTokenResponse(['scope' => 'esi-assets.read_assets.v1 esi-wallet.read_character_wallet.v1']));
        $validToken = $this->storedTokenOf(new User())->setAccessTokenExpiresAt(new \DateTimeImmutable('+1 hour'));

        $this->tokenManagerWith($httpClient, $this->createStub(EntityManagerInterface::class))->forceRefresh($validToken);

        self::assertSame(1, $httpClient->getRequestsCount());
        self::assertSame('new-access-token', $validToken->getAccessToken());
        self::assertSame(['esi-assets.read_assets.v1', 'esi-wallet.read_character_wallet.v1'], $validToken->getScopes());
    }

    public function testForceRefreshCallsSsoWhileHoldingTheRefreshLockOfTheCharacterThenReleasesIt(): void
    {
        $lockStore = new InMemoryStore();
        $lockHeldDuringSsoCall = [];
        $httpClient = new MockHttpClient(function () use ($lockStore, &$lockHeldDuringSsoCall): MockResponse {
            $lockHeldDuringSsoCall[] = $this->refreshLockIsHeldIn($lockStore);

            return $this->ssoTokenResponse(['scope' => 'esi-assets.read_assets.v1']);
        });
        $validToken = $this->storedTokenOf(new User())->setAccessTokenExpiresAt(new \DateTimeImmutable('+1 hour'));

        $this->tokenManagerWith($httpClient, $this->createStub(EntityManagerInterface::class), new LockFactory($lockStore))->forceRefresh($validToken);

        self::assertSame([true], $lockHeldDuringSsoCall);
        self::assertFalse($this->refreshLockIsHeldIn($lockStore));
    }

    public function testForceRefreshWaitsForTheRefreshLockAndReleasesItExplicitly(): void
    {
        $validToken = $this->storedTokenOf(new User())->setAccessTokenExpiresAt(new \DateTimeImmutable('+1 hour'));
        $tokenManager = $this->tokenManagerWith(
            new MockHttpClient($this->ssoTokenResponse(['scope' => 'esi-assets.read_assets.v1'])),
            $this->createStub(EntityManagerInterface::class),
            $this->lockFactoryExpectingABlockingAcquireAndOneExplicitRelease(),
        );

        $tokenManager->forceRefresh($validToken);

        self::assertSame('new-access-token', $validToken->getAccessToken());
    }

    /** The worker refreshed the token while this process was waiting for the lock: the SSO must not be called twice */
    public function testGetValidAccessTokenRereadsTheTokenUnderTheLockAndKeepsTheOneAnotherProcessAlreadyRefreshed(): void
    {
        $lockStore = new InMemoryStore();
        $ssoCallCount = 0;
        $httpClient = new MockHttpClient(function () use (&$ssoCallCount): MockResponse {
            ++$ssoCallCount;

            return $this->ssoTokenResponse(['scope' => 'esi-assets.read_assets.v1']);
        });
        $lockHeldWhenTokenReread = [];
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('refresh')->willReturnCallback(function (object $token) use ($lockStore, &$lockHeldWhenTokenReread): void {
            \assert($token instanceof EveToken);
            $lockHeldWhenTokenReread[] = $this->refreshLockIsHeldIn($lockStore);
            $token->setAccessToken(self::ACCESS_TOKEN_REFRESHED_BY_ANOTHER_PROCESS)
                ->setAccessTokenExpiresAt(new \DateTimeImmutable('+1199 seconds'));
        });
        $staleToken = $this->storedTokenOf(new User());

        $accessToken = $this->tokenManagerWith($httpClient, $entityManager, new LockFactory($lockStore))->getValidAccessToken($staleToken);

        self::assertSame(0, $ssoCallCount);
        self::assertSame(self::ACCESS_TOKEN_REFRESHED_BY_ANOTHER_PROCESS, $accessToken);
        self::assertSame([true], $lockHeldWhenTokenReread, 'the token must be reread once, after acquiring the refresh lock');
    }

    /** Issue #27: the app and the worker both hold a stale copy of the same token */
    public function testTwoProcessesHoldingTheSameStaleTokenCallSsoOnlyOnce(): void
    {
        $lockStore = new InMemoryStore();
        $ssoRequestBodies = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$ssoRequestBodies): MockResponse {
            $ssoRequestBodies[] = $options['body'];

            return $this->ssoTokenResponse(['refresh_token' => 'rotated-refresh-token', 'scope' => 'esi-assets.read_assets.v1']);
        });
        $appTokenCopy = $this->storedTokenOf(new User());
        $workerTokenCopy = $this->storedTokenOf(new User())->setRefreshTokenEncrypted($appTokenCopy->getRefreshTokenEncrypted());
        $databaseRow = [
            'accessToken' => self::STORED_ACCESS_TOKEN,
            'refreshTokenEncrypted' => $appTokenCopy->getRefreshTokenEncrypted(),
            'accessTokenExpiresAt' => $appTokenCopy->getAccessTokenExpiresAt(),
        ];
        $app = $this->processSharingTheTokenRow($databaseRow, $appTokenCopy, $httpClient, $lockStore);
        $worker = $this->processSharingTheTokenRow($databaseRow, $workerTokenCopy, $httpClient, $lockStore);

        $appAccessToken = $app->getValidAccessToken($appTokenCopy);
        $workerAccessToken = $worker->getValidAccessToken($workerTokenCopy);

        self::assertSame(['grant_type=refresh_token&refresh_token=' . self::STORED_REFRESH_TOKEN], $ssoRequestBodies);
        self::assertSame('new-access-token', $appAccessToken);
        self::assertSame('new-access-token', $workerAccessToken);
        self::assertSame('rotated-refresh-token', $worker->decryptRefreshToken($workerTokenCopy->getRefreshTokenEncrypted()));
    }

    /** Callers go through getValidAccessToken(), the only path that takes the refresh lock */
    public function testTheTokenRefreshIsOnlyReachableThroughGetValidAccessToken(): void
    {
        $publicMethods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(TokenManager::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        self::assertContains('getValidAccessToken', $publicMethods);
        self::assertNotContains('refreshAccessToken', $publicMethods);
    }

    public function testCreateTokenFromDtoEncryptsTheRefreshTokenAndKeepsAccessTokenExpiryAndScopes(): void
    {
        $token = $this->tokenManager->createTokenFromDto(new EveTokenDto(
            'sso-access-token',
            'sso-refresh-token',
            1199,
            ['esi-assets.read_assets.v1'],
        ));

        self::assertSame('sso-access-token', $token->getAccessToken());
        self::assertSame('sso-refresh-token', $this->tokenManager->decryptRefreshToken($token->getRefreshTokenEncrypted()));
        self::assertEqualsWithDelta((new \DateTimeImmutable('+1199 seconds'))->getTimestamp(), $token->getAccessTokenExpiresAt()->getTimestamp(), 5);
        self::assertSame(['esi-assets.read_assets.v1'], $token->getScopes());
    }

    private function assertRefreshFailureLeavesTheUserValid(MockResponse $ssoResponse, int $expectedStatusCode): void
    {
        $user = new User();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');
        $tokenManager = $this->tokenManagerAnsweringWith($ssoResponse, $entityManager);

        $exception = $this->refreshFailure($tokenManager, $this->storedTokenOf($user));

        self::assertInstanceOf(EsiApiException::class, $exception);
        self::assertSame($expectedStatusCode, $exception->statusCode);
        self::assertInstanceOf(ClientExceptionInterface::class, $exception->getPrevious());
        self::assertSame(AuthStatus::Valid, $user->getAuthStatus());
    }

    /** @param array<string, string> $overrides */
    private function ssoTokenResponse(array $overrides): MockResponse
    {
        return new MockResponse(json_encode($overrides + [
            'access_token' => 'new-access-token',
            'expires_in' => 1199,
            'token_type' => 'Bearer',
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    /** @param list<string> $scopes */
    private function jwtWithScopes(array $scopes): string
    {
        return $this->jwtWithPayload(['sub' => 'CHARACTER:EVE:2112000001', 'scp' => $scopes]);
    }

    /** @param array<string, mixed> $payload */
    private function jwtWithPayload(array $payload): string
    {
        $encode = static fn (array $part): string => rtrim(strtr(base64_encode(json_encode($part, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $encode(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $encode($payload) . '.signature';
    }

    private function tokenManagerAnsweringWith(MockResponse $ssoResponse, EntityManagerInterface $entityManager): TokenManager
    {
        return $this->tokenManagerWith(new MockHttpClient($ssoResponse), $entityManager);
    }

    /**
     * The 6th constructor argument is the `LockFactory $lockFactory` that serializes the refresh per character.
     * Processes sharing the same lock store (app and worker, through LOCK_DSN) share the same locks.
     */
    private function tokenManagerWith(
        HttpClientInterface $httpClient,
        EntityManagerInterface $entityManager,
        ?LockFactory $lockFactory = null,
    ): TokenManager {
        return new TokenManager(
            $this->encryptionKey,
            $httpClient,
            $entityManager,
            'test_client_id',
            'test_client_secret',
            $lockFactory ?? new LockFactory(new InMemoryStore()),
        );
    }

    /**
     * A real Symfony lock releases itself when destroyed, which hides a missing release():
     * this lock has no destructor, so only an explicit release() frees it.
     */
    private function lockFactoryExpectingABlockingAcquireAndOneExplicitRelease(): LockFactory
    {
        $refreshLock = $this->createMock(SharedLockInterface::class);
        $refreshLock->expects(self::once())->method('acquire')->with(true)->willReturn(true);
        $refreshLock->expects(self::once())->method('release');

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects(self::once())->method('createLock')->with(self::REFRESH_LOCK_KEY)->willReturn($refreshLock);

        return $lockFactory;
    }

    /** True when another process cannot take the refresh lock of the character right now. */
    private function refreshLockIsHeldIn(InMemoryStore $lockStore): bool
    {
        $otherProcessLock = (new LockFactory($lockStore))->createLock(self::REFRESH_LOCK_KEY);
        if (!$otherProcessLock->acquire(false)) {
            return true;
        }
        $otherProcessLock->release();

        return false;
    }

    /**
     * A process with its own in-memory copy of the token (its own Doctrine identity map),
     * reading and writing the same database row and the same lock store as the other processes.
     *
     * @param array{accessToken: string, refreshTokenEncrypted: string, accessTokenExpiresAt: \DateTimeImmutable} $databaseRow
     */
    private function processSharingTheTokenRow(
        array &$databaseRow,
        EveToken $ownTokenCopy,
        HttpClientInterface $httpClient,
        InMemoryStore $lockStore,
    ): TokenManager {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('refresh')->willReturnCallback(static function (object $token) use (&$databaseRow): void {
            \assert($token instanceof EveToken);
            $token->setAccessToken($databaseRow['accessToken'])
                ->setRefreshTokenEncrypted($databaseRow['refreshTokenEncrypted'])
                ->setAccessTokenExpiresAt($databaseRow['accessTokenExpiresAt']);
        });
        $entityManager->method('flush')->willReturnCallback(static function () use (&$databaseRow, $ownTokenCopy): void {
            $databaseRow = [
                'accessToken' => $ownTokenCopy->getAccessToken(),
                'refreshTokenEncrypted' => $ownTokenCopy->getRefreshTokenEncrypted(),
                'accessTokenExpiresAt' => $ownTokenCopy->getAccessTokenExpiresAt(),
            ];
        });

        return $this->tokenManagerWith($httpClient, $entityManager, new LockFactory($lockStore));
    }

    /** A token whose access token has expired, owned by a character of the given user. */
    private function storedTokenOf(User $user): EveToken
    {
        $character = (new Character())
            ->setEveCharacterId(self::EVE_CHARACTER_ID)
            ->setName('Test Pilot');
        $user->addCharacter($character);

        $token = (new EveToken())
            ->setAccessToken(self::STORED_ACCESS_TOKEN)
            ->setRefreshTokenEncrypted($this->tokenManager->encryptRefreshToken(self::STORED_REFRESH_TOKEN))
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('-1 minute'));
        $character->setEveToken($token);

        return $token;
    }

    private function refreshFailure(TokenManager $tokenManager, EveToken $token): \Throwable
    {
        try {
            $tokenManager->getValidAccessToken($token);
        } catch (\Throwable $e) {
            return $e;
        }

        self::fail('The refresh was expected to fail');
    }
}
