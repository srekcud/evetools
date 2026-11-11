<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Admin;

use App\Entity\Character;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-639')]
final class TriggerSyncApiTest extends WebTestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 2_113_639_001;
    private const OTHER_EVE_CHARACTER_ID = 2_113_639_002;

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

    public function testAnAdminTriggersATrackedSyncByItsType(): void
    {
        $this->client->loginUser($this->persistedUser(self::ADMIN_EVE_CHARACTER_ID), 'api');

        $this->postSyncType('cost-indices');

        self::assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($body['success']);
        self::assertSame('Sync triggered: cost-indices', $body['message']);
    }

    public function testAnUnknownTypeIsReportedAsUnsuccessful(): void
    {
        $this->client->loginUser($this->persistedUser(self::ADMIN_EVE_CHARACTER_ID), 'api');

        $this->postSyncType('does-not-exist');

        $body = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($body['success']);
        self::assertSame('Unknown sync type', $body['message']);
    }

    public function testAMissingTypeIsRejected(): void
    {
        $this->client->loginUser($this->persistedUser(self::ADMIN_EVE_CHARACTER_ID), 'api');

        $this->client->request('POST', '/api/admin/actions/sync', server: $this->jsonHeaders(), content: '{}');

        self::assertResponseStatusCodeSame(422);
    }

    public function testAUserWhoIsNotAdminIsRefused(): void
    {
        $this->client->loginUser($this->persistedUser(self::OTHER_EVE_CHARACTER_ID), 'api');

        $this->postSyncType('cost-indices');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAnonymousVisitorIsRefused(): void
    {
        $this->postSyncType('cost-indices');

        self::assertResponseStatusCodeSame(401);
    }

    private function postSyncType(string $type): void
    {
        $this->client->request(
            'POST',
            '/api/admin/actions/sync',
            server: $this->jsonHeaders(),
            content: json_encode(['type' => $type], JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, string> */
    private function jsonHeaders(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    }

    private function persistedUser(int $eveCharacterId): User
    {
        $user = new User();
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName('Noor Halvik')
            ->setCorporationId(98_639_001)
            ->setCorporationName('Halvik Freight');
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }
}
