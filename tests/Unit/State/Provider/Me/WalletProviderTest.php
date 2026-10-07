<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Me;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Me\WalletEntryResource;
use App\ApiResource\Me\WalletResource;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\State\Provider\Me\WalletProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Issue #37 : a character whose wallet cannot be read must not silently lower the total.
 */
#[CoversClass(WalletProvider::class)]
class WalletProviderTest extends TestCase
{
    private const int MAIN_EVE_CHARACTER_ID = 2112000001;
    private const int ALT_EVE_CHARACTER_ID = 2112000002;
    private const int FAILING_EVE_CHARACTER_ID = 2112000003;

    public function testCharacterWhoseWalletFailsAppearsWithUnknownBalanceAndTotalIsFlaggedIncomplete(): void
    {
        $user = $this->createUserWithCharacters([
            self::MAIN_EVE_CHARACTER_ID => 'Main Pilot',
            self::ALT_EVE_CHARACTER_ID => 'Alt Pilot',
            self::FAILING_EVE_CHARACTER_ID => 'Failing Pilot',
        ]);
        // getScalarBatch() returns null for a character whose ESI request failed
        $balancesByEveCharacterId = [
            (string) self::MAIN_EVE_CHARACTER_ID => 1500000000.5,
            (string) self::ALT_EVE_CHARACTER_ID => 250000000.25,
            (string) self::FAILING_EVE_CHARACTER_ID => null,
        ];

        $wallet = $this->provideWallet($user, $balancesByEveCharacterId);

        $this->assertSame(1750000000.75, $wallet->totalBalance);
        $this->assertCount(3, $wallet->wallets);
        $this->assertSame(1500000000.5, $this->walletOf($wallet, 'Main Pilot')->balance);
        $this->assertSame(250000000.25, $this->walletOf($wallet, 'Alt Pilot')->balance);
        $this->assertNull($this->walletOf($wallet, 'Failing Pilot')->balance);
        $this->assertTrue($wallet->incomplete ?? null);
    }

    public function testCharacterWhoseTokenRefreshFailsAppearsWithUnknownBalanceAndTotalIsFlaggedIncomplete(): void
    {
        $user = $this->createUserWithCharacters([
            self::MAIN_EVE_CHARACTER_ID => 'Main Pilot',
            self::FAILING_EVE_CHARACTER_ID => 'Failing Pilot',
        ]);
        $this->expireTokenOf($user, 'Failing Pilot');
        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('refreshAccessToken')->willThrowException(new \RuntimeException('invalid_grant'));

        $wallet = $this->provideWallet($user, [
            (string) self::MAIN_EVE_CHARACTER_ID => 1500000000.5,
        ], $tokenManager);

        $this->assertSame(1500000000.5, $wallet->totalBalance);
        $this->assertCount(2, $wallet->wallets);
        $this->assertNull($this->walletOf($wallet, 'Failing Pilot')->balance);
        $this->assertTrue($wallet->incomplete ?? null);
    }

    public function testAllWalletsKnownGivesExactTotalAndIsNotIncomplete(): void
    {
        $user = $this->createUserWithCharacters([
            self::MAIN_EVE_CHARACTER_ID => 'Main Pilot',
            self::ALT_EVE_CHARACTER_ID => 'Alt Pilot',
        ]);

        $wallet = $this->provideWallet($user, [
            (string) self::MAIN_EVE_CHARACTER_ID => 1500000000.5,
            (string) self::ALT_EVE_CHARACTER_ID => 250000000.25,
        ]);

        $this->assertSame(1750000000.75, $wallet->totalBalance);
        $this->assertCount(2, $wallet->wallets);
        $this->assertSame(1500000000.5, $this->walletOf($wallet, 'Main Pilot')->balance);
        $this->assertSame(250000000.25, $this->walletOf($wallet, 'Alt Pilot')->balance);
        $this->assertFalse($wallet->incomplete ?? null);
    }

    public function testEmptyWalletIsAKnownZeroBalanceNotAnUnknownOne(): void
    {
        $user = $this->createUserWithCharacters([
            self::MAIN_EVE_CHARACTER_ID => 'Main Pilot',
            self::ALT_EVE_CHARACTER_ID => 'Alt Pilot',
        ]);

        $wallet = $this->provideWallet($user, [
            (string) self::MAIN_EVE_CHARACTER_ID => 1500000000.5,
            (string) self::ALT_EVE_CHARACTER_ID => 0,
        ]);

        $this->assertSame(1500000000.5, $wallet->totalBalance);
        $this->assertSame(0.0, $this->walletOf($wallet, 'Alt Pilot')->balance);
        $this->assertFalse($wallet->incomplete ?? null);
    }

    /**
     * @param array<int, string> $namesByEveCharacterId first one is the main character
     */
    private function createUserWithCharacters(array $namesByEveCharacterId): User
    {
        $user = new User();
        foreach ($namesByEveCharacterId as $eveCharacterId => $name) {
            $character = new Character();
            $character->setEveCharacterId($eveCharacterId);
            $character->setName($name);
            $token = new EveToken();
            $token->setAccessTokenExpiresAt(new \DateTimeImmutable('+1 hour'));
            $character->setEveToken($token);
            $user->addCharacter($character);
            if ($user->getMainCharacter() === null) {
                $user->setMainCharacter($character);
            }
        }

        return $user;
    }

    private function expireTokenOf(User $user, string $characterName): void
    {
        foreach ($user->getCharacters() as $character) {
            if ($character->getName() === $characterName) {
                $character->getEveToken()?->setAccessTokenExpiresAt(new \DateTimeImmutable('-1 minute'));
            }
        }
    }

    /**
     * @param array<string, mixed> $balancesByEveCharacterId what EsiClient::getScalarBatch() returns
     */
    private function provideWallet(User $user, array $balancesByEveCharacterId, ?TokenManager $tokenManager = null): WalletResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getScalarBatch')->willReturn($balancesByEveCharacterId);

        $provider = new WalletProvider($security, $esiClient, $tokenManager ?? $this->createStub(TokenManager::class));

        return $provider->provide(new Get());
    }

    private function walletOf(WalletResource $wallet, string $characterName): WalletEntryResource
    {
        foreach ($wallet->wallets as $entry) {
            if ($entry->characterName === $characterName) {
                return $entry;
            }
        }

        $this->fail(sprintf('No wallet entry for character "%s"', $characterName));
    }
}
