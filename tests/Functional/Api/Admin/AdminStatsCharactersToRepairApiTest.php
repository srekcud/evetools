<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Admin;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Service\ESI\AuthenticationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-648')]
final class AdminStatsCharactersToRepairApiTest extends WebTestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 2_113_648_001;
    private const INVALID_EVE_CHARACTER_ID = 2_113_648_002;
    private const MISSING_SCOPE_EVE_CHARACTER_ID = 2_113_648_003;
    private const HEALTHY_EVE_CHARACTER_ID = 2_113_648_004;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $_ENV['ADMIN_CHARACTER_IDS'] = $_SERVER['ADMIN_CHARACTER_IDS'] = (string) self::ADMIN_EVE_CHARACTER_ID;
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $_ENV['ADMIN_CHARACTER_IDS'] = $_SERVER['ADMIN_CHARACTER_IDS'] = '';
        parent::tearDown();
    }

    public function testStatsListTheCharactersToRepairAndSkipTheHealthyOnes(): void
    {
        $admin = $this->persistedUserWith(self::ADMIN_EVE_CHARACTER_ID, AuthenticationService::getRequiredScopes());
        $this->persistedUserWith(self::INVALID_EVE_CHARACTER_ID, AuthenticationService::getRequiredScopes(), invalid: true);
        $this->persistedUserWith(self::MISSING_SCOPE_EVE_CHARACTER_ID, array_slice(AuthenticationService::getRequiredScopes(), 1));
        $this->persistedUserWith(self::HEALTHY_EVE_CHARACTER_ID, AuthenticationService::getRequiredScopes());
        $this->client->loginUser($admin, 'api');

        $this->client->request('GET', '/api/admin/stats', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['charactersToRepair'];
        $byCharacterId = array_column($payload['characters'], null, 'eveCharacterId');
        self::assertSame(count($payload['characters']), $payload['count']);
        self::assertArrayHasKey(self::INVALID_EVE_CHARACTER_ID, $byCharacterId);
        self::assertArrayHasKey(self::MISSING_SCOPE_EVE_CHARACTER_ID, $byCharacterId);
        self::assertArrayNotHasKey(self::HEALTHY_EVE_CHARACTER_ID, $byCharacterId);
        self::assertArrayNotHasKey(self::ADMIN_EVE_CHARACTER_ID, $byCharacterId);
        self::assertSame('invalid', $byCharacterId[self::INVALID_EVE_CHARACTER_ID]['state']);
        self::assertSame('missing_scopes', $byCharacterId[self::MISSING_SCOPE_EVE_CHARACTER_ID]['state']);
        self::assertSame([AuthenticationService::getRequiredScopes()[0]], $byCharacterId[self::MISSING_SCOPE_EVE_CHARACTER_ID]['missingScopes']);
        self::assertNotNull($byCharacterId[self::INVALID_EVE_CHARACTER_ID]['userId']);
        self::assertNull($byCharacterId[self::INVALID_EVE_CHARACTER_ID]['userLastLoginAt'] ?? null);
        self::assertNotNull($byCharacterId[self::INVALID_EVE_CHARACTER_ID]['sinceAt']);
    }

    /**
     * @param list<string> $scopes
     */
    private function persistedUserWith(int $eveCharacterId, array $scopes, bool $invalid = false): User
    {
        $user = new User();
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName('Pilot ' . $eveCharacterId)
            ->setCorporationId(98_000_001)
            ->setCorporationName('Test Corp');
        if ($invalid) {
            $character->markAuthInvalid();
        }
        $token = (new EveToken())
            ->setAccessToken('access')
            ->setRefreshTokenEncrypted('refresh')
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+20 minutes'))
            ->setScopes($scopes);
        $character->setEveToken($token);
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);
        $this->em->persist($token);
        $this->em->flush();

        return $user;
    }
}
