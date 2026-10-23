<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Me;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-231')]
final class WalletUnknownBalanceSerializationTest extends WebTestCase
{
    private const string KNOWN_BALANCE_CHARACTER_NAME = 'Ilyra Castell';
    private const string UNKNOWN_BALANCE_CHARACTER_NAME = 'Doran Vex';
    private const int KNOWN_BALANCE_EVE_CHARACTER_ID = 2_112_231_001;
    private const int UNKNOWN_BALANCE_EVE_CHARACTER_ID = 2_112_231_002;
    private const int CORPORATION_ID = 98_231_001;
    private const string CORPORATION_NAME = 'Castell Freight Union';
    private const float KNOWN_BALANCE = 1500000.5;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private EsiClient&Stub $esiClient;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->esiClient = $this->createStub(EsiClient::class);
        self::getContainer()->set(EsiClient::class, $this->esiClient);
        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn('valid-access-token');
        self::getContainer()->set(TokenManager::class, $tokenManager);
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public static function responseFormats(): iterable
    {
        yield 'JSON-LD' => ['application/ld+json'];
        yield 'JSON' => ['application/json'];
    }

    #[DataProvider('responseFormats')]
    public function testUnknownBalanceIsSerializedAsNullNextToTheKnownBalance(string $acceptedFormat): void
    {
        $user = $this->persistUserWithCharacters([
            self::KNOWN_BALANCE_EVE_CHARACTER_ID => self::KNOWN_BALANCE_CHARACTER_NAME,
            self::UNKNOWN_BALANCE_EVE_CHARACTER_ID => self::UNKNOWN_BALANCE_CHARACTER_NAME,
        ]);
        $this->esiClient->method('getScalarBatch')->willReturn([
            (string) self::KNOWN_BALANCE_EVE_CHARACTER_ID => self::KNOWN_BALANCE,
            (string) self::UNKNOWN_BALANCE_EVE_CHARACTER_ID => null,
        ]);

        $wallet = $this->getWallets($user, $acceptedFormat);

        $unknownWalletEntry = $this->walletEntryOf($wallet, self::UNKNOWN_BALANCE_CHARACTER_NAME);
        self::assertArrayHasKey('balance', $unknownWalletEntry);
        self::assertNull($unknownWalletEntry['balance']);
        self::assertSame(self::KNOWN_BALANCE, $this->walletEntryOf($wallet, self::KNOWN_BALANCE_CHARACTER_NAME)['balance']);
        self::assertSame(self::KNOWN_BALANCE, $wallet['totalBalance']);
        self::assertTrue($wallet['incomplete']);
    }

    public function testKnownZeroBalanceIsSerializedAsZeroAndTheTotalIsComplete(): void
    {
        $user = $this->persistUserWithCharacters([
            self::KNOWN_BALANCE_EVE_CHARACTER_ID => self::KNOWN_BALANCE_CHARACTER_NAME,
        ]);
        $this->esiClient->method('getScalarBatch')->willReturn([
            (string) self::KNOWN_BALANCE_EVE_CHARACTER_ID => 0,
        ]);

        $wallet = $this->getWallets($user, 'application/ld+json');

        $zeroWalletEntry = $this->walletEntryOf($wallet, self::KNOWN_BALANCE_CHARACTER_NAME);
        self::assertArrayHasKey('balance', $zeroWalletEntry);
        self::assertNotNull($zeroWalletEntry['balance']);
        self::assertEquals(0, $zeroWalletEntry['balance']);
        self::assertEquals(0, $wallet['totalBalance']);
        self::assertFalse($wallet['incomplete']);
    }

    private function persistUserWithCharacters(array $namesByEveCharacterId): User
    {
        $user = new User();
        foreach ($namesByEveCharacterId as $eveCharacterId => $name) {
            $character = (new Character())
                ->setEveCharacterId($eveCharacterId)
                ->setName($name)
                ->setCorporationId(self::CORPORATION_ID)
                ->setCorporationName(self::CORPORATION_NAME);
            $character->setEveToken($this->validToken());
            $user->addCharacter($character);
            if ($user->getMainCharacter() === null) {
                $user->setMainCharacter($character);
            }
        }
        $this->em->persist($user);
        foreach ($user->getCharacters() as $character) {
            $this->em->persist($character);
        }
        $this->em->flush();

        return $user;
    }

    private function validToken(): EveToken
    {
        return (new EveToken())
            ->setAccessToken('access-token')
            ->setRefreshTokenEncrypted('refresh-token')
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+1 hour'))
            ->setScopes(['esi-wallet.read_character_wallet.v1']);
    }

    private function getWallets(User $user, string $acceptedFormat): array
    {
        $this->client->loginUser($user, 'api');
        $this->client->request('GET', '/api/me/wallets', server: ['HTTP_ACCEPT' => $acceptedFormat]);

        self::assertResponseStatusCodeSame(200);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function walletEntryOf(array $wallet, string $characterName): array
    {
        foreach ($wallet['wallets'] as $walletEntry) {
            if ($walletEntry['characterName'] === $characterName) {
                return $walletEntry;
            }
        }

        self::fail(sprintf('No wallet entry for character "%s"', $characterName));
    }
}
