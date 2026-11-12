<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Admin;

use App\Entity\Character;
use App\Entity\User;
use App\Service\Admin\SyncTracker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-646')]
final class AdminStatsSyncRunHistoryApiTest extends WebTestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 2_113_646_001;
    private const TRACKED_TYPE = 'industry';
    private const UNTOUCHED_TYPE = 'pve';

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

    public function testStatsExposeTheRecordedRunsAndTheSuccessRateOfEachType(): void
    {
        $syncTracker = self::getContainer()->get(SyncTracker::class);
        $syncTracker->start(self::TRACKED_TYPE);
        $syncTracker->complete(self::TRACKED_TYPE, '3 jobs');
        $syncTracker->start(self::TRACKED_TYPE);
        $syncTracker->fail(self::TRACKED_TYPE, 'ESI timeout');
        $this->client->loginUser($this->persistedAdmin(), 'api');

        $this->client->request('GET', '/api/admin/stats', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $health = array_column(json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['schedulerHealth'], null, 'type');
        $tracked = $health[self::TRACKED_TYPE];
        self::assertSame(50, $tracked['successRate24h']);
        self::assertSame(['error', 'ok'], array_column($tracked['runs'], 'status'));
        self::assertSame('ESI timeout', $tracked['runs'][0]['message']);
        self::assertFalse($tracked['runs'][0]['stuck']);
        self::assertNotNull($tracked['runs'][1]['completedAt']);
        self::assertSame([], $health[self::UNTOUCHED_TYPE]['runs']);
        self::assertNull($health[self::UNTOUCHED_TYPE]['successRate24h'] ?? null);
    }

    private function persistedAdmin(): User
    {
        $user = new User();
        $character = (new Character())
            ->setEveCharacterId(self::ADMIN_EVE_CHARACTER_ID)
            ->setName('Pilot ' . self::ADMIN_EVE_CHARACTER_ID)
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
