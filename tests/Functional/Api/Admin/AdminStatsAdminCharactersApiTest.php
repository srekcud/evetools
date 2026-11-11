<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Admin;

use App\Entity\Character;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-654')]
final class AdminStatsAdminCharactersApiTest extends WebTestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 2_113_654_001;
    private const UNKNOWN_ADMIN_EVE_CHARACTER_ID = 2_113_654_002;
    private const REGULAR_EVE_CHARACTER_ID = 2_113_654_003;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $_ENV['ADMIN_CHARACTER_IDS'] = $_SERVER['ADMIN_CHARACTER_IDS'] = self::ADMIN_EVE_CHARACTER_ID . ',' . self::UNKNOWN_ADMIN_EVE_CHARACTER_ID;
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $_ENV['ADMIN_CHARACTER_IDS'] = $_SERVER['ADMIN_CHARACTER_IDS'] = '';
        parent::tearDown();
    }

    public function testStatsListTheConfiguredAdminCharactersWithTheirUserFlag(): void
    {
        $admin = $this->persistedUserWith(self::ADMIN_EVE_CHARACTER_ID, 'Admin Pilot');
        $this->client->loginUser($admin, 'api');

        $this->client->request('GET', '/api/admin/stats', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['adminCharacters'];
        self::assertSame(2, $payload['count']);
        self::assertSame(self::ADMIN_EVE_CHARACTER_ID, $payload['characters'][0]['eveCharacterId']);
        self::assertSame('Admin Pilot', $payload['characters'][0]['characterName']);
        self::assertTrue($payload['characters'][0]['hasUser']);
        self::assertSame(self::UNKNOWN_ADMIN_EVE_CHARACTER_ID, $payload['characters'][1]['eveCharacterId']);
        self::assertNull($payload['characters'][1]['characterName'] ?? null);
        self::assertFalse($payload['characters'][1]['hasUser']);
    }

    public function testStatsDoNotListNonAdminCharacters(): void
    {
        $admin = $this->persistedUserWith(self::ADMIN_EVE_CHARACTER_ID, 'Admin Pilot');
        $this->persistedUserWith(self::REGULAR_EVE_CHARACTER_ID, 'Regular Pilot');
        $this->client->loginUser($admin, 'api');

        $this->client->request('GET', '/api/admin/stats', server: ['HTTP_ACCEPT' => 'application/json']);

        $payload = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['adminCharacters'];
        self::assertNotContains(self::REGULAR_EVE_CHARACTER_ID, array_column($payload['characters'], 'eveCharacterId'));
    }

    public function testNonAdminStillGetsForbiddenAndNoAdminList(): void
    {
        $regular = $this->persistedUserWith(self::REGULAR_EVE_CHARACTER_ID, 'Regular Pilot');
        $this->client->loginUser($regular, 'api');

        $this->client->request('GET', '/api/admin/stats', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('adminCharacters', (string) $this->client->getResponse()->getContent());
    }

    private function persistedUserWith(int $eveCharacterId, string $name): User
    {
        $user = new User();
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(98_000_001)
            ->setCorporationName('Test Corp');
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }
}
