<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Admin;

use App\Entity\Character;
use App\Entity\User;
use App\Service\ESI\EsiErrorBudget;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-645')]
final class AdminStatsEsiErrorBudgetApiTest extends WebTestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 2_113_645_001;
    private const OTHER_EVE_CHARACTER_ID = 2_113_645_002;
    private const HOURS_IN_WINDOW = 24;

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

    public function testStatsExposeTheSharedEsiErrorBudgetAndTheLimitedResponses(): void
    {
        $errorBudget = self::getContainer()->get(EsiErrorBudget::class);
        $errorBudget->observe(12, 60);
        $errorBudget->recordLimitedResponse(420, '/characters/1/assets/');
        $this->client->loginUser($this->persistedUser(self::ADMIN_EVE_CHARACTER_ID), 'api');

        $this->client->request('GET', '/api/admin/stats', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $budget = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['esiErrorBudget'];
        self::assertSame(12, $budget['remain']);
        self::assertSame('low', $budget['level']);
        self::assertSame(1, $budget['limited420']);
        self::assertSame(0, $budget['limited429']);
        self::assertCount(self::HOURS_IN_WINDOW, $budget['hourly']);
        self::assertSame('/characters/{id}/assets/', $budget['byFamily'][0]['family']);
    }

    public function testAUserWhoIsNotAdminDoesNotSeeTheBudget(): void
    {
        $this->client->loginUser($this->persistedUser(self::OTHER_EVE_CHARACTER_ID), 'api');

        $this->client->request('GET', '/api/admin/stats', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(403);
    }

    private function persistedUser(int $eveCharacterId): User
    {
        $user = new User();
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName('Pilot ' . $eveCharacterId)
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
