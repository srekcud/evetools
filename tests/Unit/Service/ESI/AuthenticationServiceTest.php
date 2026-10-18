<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Dto\CharacterInfoDto;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Enum\AuthStatus;
use App\Repository\CharacterRepository;
use App\Service\ESI\AuthenticationService;
use App\Service\ESI\CharacterService;
use App\Service\ESI\TokenManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(AuthenticationService::class)]
final class AuthenticationServiceTest extends TestCase
{
    private const string SSO_TOKEN_URL = 'https://login.eveonline.com/v2/oauth/token';
    private const string SSO_VERIFY_URL = 'https://login.eveonline.com/oauth/verify';
    private const int MAIN_EVE_CHARACTER_ID = 2_112_000_002;
    private const int ALT_EVE_CHARACTER_ID = 2_112_000_001;
    private const int CORPORATION_ID = 98_000_001;

    private User $user;
    private Character $mainCharacter;
    private Character $altCharacter;

    protected function setUp(): void
    {
        $this->user = new User();
        $this->mainCharacter = $this->characterWithToken(self::MAIN_EVE_CHARACTER_ID, 'Main Pilot');
        $this->altCharacter = $this->characterWithToken(self::ALT_EVE_CHARACTER_ID, 'Alt Pilot');
        $this->user->setMainCharacter($this->mainCharacter);
    }

    public function testLoginWithTheMainKeepsTheRevokedAltInvalid(): void
    {
        $this->altCharacter->markAuthInvalid();

        $loggedInUser = $this->authenticationServiceSignedInAs(self::MAIN_EVE_CHARACTER_ID)->authenticateWithCode('sso-code');

        self::assertSame($this->user, $loggedInUser);
        self::assertSame(AuthStatus::Valid, $this->mainCharacter->getAuthStatus());
        self::assertSame(AuthStatus::Invalid, $this->altCharacter->getAuthStatus());
        self::assertSame(AuthStatus::Invalid, $loggedInUser->getAuthStatus());
    }

    public function testLoginWithARevokedMainRevalidatesItAndLeavesTheAltValid(): void
    {
        $this->mainCharacter->markAuthInvalid();

        $loggedInUser = $this->authenticationServiceSignedInAs(self::MAIN_EVE_CHARACTER_ID)->authenticateWithCode('sso-code');

        self::assertSame(AuthStatus::Valid, $this->mainCharacter->getAuthStatus());
        self::assertSame(AuthStatus::Valid, $this->altCharacter->getAuthStatus());
        self::assertSame(AuthStatus::Valid, $loggedInUser->getAuthStatus());
    }

    public function testAddingTheRevokedAltBackToItsUserRevalidatesIt(): void
    {
        $this->altCharacter->markAuthInvalid();

        $readdedCharacter = $this->authenticationServiceSignedInAs(self::ALT_EVE_CHARACTER_ID)->addCharacterToUser($this->user, 'sso-code');

        self::assertSame($this->altCharacter, $readdedCharacter);
        self::assertSame(AuthStatus::Valid, $this->altCharacter->getAuthStatus());
        self::assertSame(AuthStatus::Valid, $this->mainCharacter->getAuthStatus());
        self::assertSame(AuthStatus::Valid, $this->user->getAuthStatus());
    }

    public function testAddingTheRevokedAltBackStoresItsNewRefreshToken(): void
    {
        $this->altCharacter->markAuthInvalid();
        $tokenManager = $this->tokenManager();

        $this->authenticationServiceSignedInAs(self::ALT_EVE_CHARACTER_ID, $tokenManager)->addCharacterToUser($this->user, 'sso-code');

        self::assertSame('new-alt-refresh-token', $tokenManager->decryptRefreshToken($this->altCharacter->getEveToken()->getRefreshTokenEncrypted()));
        self::assertSame('new-sso-access-token', $this->altCharacter->getEveToken()->getAccessToken());
    }

    private function authenticationServiceSignedInAs(int $eveCharacterId, ?TokenManager $tokenManager = null): AuthenticationService
    {
        $characterService = $this->createStub(CharacterService::class);
        $characterService->method('getCharacterInfo')->willReturnCallback(
            fn (int $characterId): CharacterInfoDto => new CharacterInfoDto(
                $characterId,
                $this->characterWithEveId($characterId)->getName(),
                self::CORPORATION_ID,
                'Test Corp',
            ),
        );

        $characterRepository = $this->createStub(CharacterRepository::class);
        $characterRepository->method('findByEveCharacterId')->willReturnCallback(
            fn (int $characterId): ?Character => $this->characterWithEveId($characterId),
        );

        return new AuthenticationService(
            $this->eveSsoSigningIn($eveCharacterId),
            $tokenManager ?? $this->tokenManager(),
            $characterService,
            $characterRepository,
            $this->createStub(EntityManagerInterface::class),
            'test_client_id',
            'test_client_secret',
            'https://evetools.local/api/auth/eve/callback',
        );
    }

    private function eveSsoSigningIn(int $eveCharacterId): HttpClientInterface
    {
        return new MockHttpClient(static function (string $method, string $url) use ($eveCharacterId): MockResponse {
            if ($method === 'POST' && $url === self::SSO_TOKEN_URL) {
                return new MockResponse(json_encode([
                    'access_token' => 'new-sso-access-token',
                    'expires_in' => 1199,
                    'token_type' => 'Bearer',
                    'refresh_token' => 'new-alt-refresh-token',
                    'scope' => 'esi-assets.read_assets.v1 esi-markets.structure_markets.v1',
                ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
            }

            if ($method === 'GET' && $url === self::SSO_VERIFY_URL) {
                return new MockResponse(json_encode(['CharacterID' => $eveCharacterId], JSON_THROW_ON_ERROR), ['http_code' => 200]);
            }

            return new MockResponse('', ['http_code' => 404]);
        });
    }

    private function tokenManager(): TokenManager
    {
        return new TokenManager(
            base64_encode(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            new MockHttpClient(),
            $this->createStub(EntityManagerInterface::class),
            'test_client_id',
            'test_client_secret',
            new LockFactory(new InMemoryStore()),
        );
    }

    private function characterWithToken(int $eveCharacterId, string $name): Character
    {
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(self::CORPORATION_ID)
            ->setCorporationName('Test Corp');
        $character->setEveToken((new EveToken())
            ->setAccessToken('old-access-token-' . $eveCharacterId)
            ->setRefreshTokenEncrypted('old-refresh-token-' . $eveCharacterId)
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('-1 day'))
            ->setScopes(['esi-assets.read_assets.v1']));
        $this->user->addCharacter($character);

        return $character;
    }

    private function characterWithEveId(int $eveCharacterId): ?Character
    {
        foreach ($this->user->getCharacters() as $character) {
            if ($character->getEveCharacterId() === $eveCharacterId) {
                return $character;
            }
        }

        return null;
    }
}
