<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Admin;

use App\Entity\Character;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-649')]
final class AdminStatsMercureCookieApiTest extends WebTestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 2_113_649_001;

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

    public function testStatsExposeTheMercureCookieLimitAndNoUserWhenNobodyIsNearIt(): void
    {
        $this->client->loginUser($this->persistedAdmin(), 'api');

        $this->client->request('GET', '/api/admin/stats', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $mercureCookie = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['mercureCookie'];
        self::assertSame(4096, $mercureCookie['limitBytes']);
        self::assertSame(3276, $mercureCookie['warningThresholdBytes']);
        self::assertSame(0, $mercureCookie['nearLimitCount']);
        self::assertSame([], $mercureCookie['users']);
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
