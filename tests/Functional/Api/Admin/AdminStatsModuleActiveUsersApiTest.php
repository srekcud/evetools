<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Admin;

use App\Entity\Character;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-650')]
final class AdminStatsModuleActiveUsersApiTest extends WebTestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 2_113_650_001;

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

    public function testStatsExposeTheActiveUsersOfEachModuleWithContractsUnknown(): void
    {
        $this->client->loginUser($this->persistedAdmin(), 'api');

        $this->client->request('GET', '/api/admin/stats', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $rows = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['moduleActiveUsers'];
        self::assertSame(
            ['industry', 'groupIndustry', 'mining', 'planetary', 'market', 'pve', 'contracts'],
            array_column($rows, 'module'),
        );
        self::assertSame(['module' => 'contracts', 'activeLast7Days' => null, 'activeLast30Days' => null], $rows[6]);
        self::assertIsInt($rows[0]['activeLast7Days']);
        self::assertIsInt($rows[0]['activeLast30Days']);
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
