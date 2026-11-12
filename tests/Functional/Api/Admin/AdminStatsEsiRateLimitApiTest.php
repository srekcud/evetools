<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Admin;

use App\Entity\Character;
use App\Entity\User;
use App\Service\ESI\EsiRateLimitBudget;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-822')]
final class AdminStatsEsiRateLimitApiTest extends WebTestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 2_113_822_001;
    private const PILOT_SCOPE = '0198c4a0-1111-7000-8000-00000000822a';

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

    public function testStatsExposeTheLowestGroupBudgetsWithoutAnyCharacterIdentifier(): void
    {
        $rateLimitBudget = self::getContainer()->get(EsiRateLimitBudget::class);
        $rateLimitBudget->record('/characters/2113822002/assets/', self::PILOT_SCOPE, 200, $this->headers('assets', '150/15m', '40'));
        $rateLimitBudget->record('/characters/2113822002/assets/', self::PILOT_SCOPE, 200, $this->headers('assets', '150/15m', '5'));
        $rateLimitBudget->record('/markets/10000002/orders/', EsiRateLimitBudget::PUBLIC_SCOPE, 200, $this->headers('market', '600/1h', '400'));
        $this->client->loginUser($this->persistedAdmin(), 'api');

        $this->client->request('GET', '/api/admin/stats', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $stats = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('esiRateLimits', $stats);
        $groups = $stats['esiRateLimits'];
        self::assertCount(2, $groups);
        self::assertSame('assets', $groups[0]['group']);
        self::assertSame(5, $groups[0]['remaining']);
        self::assertSame(150, $groups[0]['limit']);
        self::assertSame(900, $groups[0]['windowSeconds']);
        self::assertSame('critical', $groups[0]['level']);
        self::assertSame(['group', 'level', 'limit', 'observedAt', 'remaining', 'windowSeconds'], $this->sortedKeys($groups[0]));
        self::assertSame('market', $groups[1]['group']);
        self::assertSame(400, $groups[1]['remaining']);
        self::assertSame(3600, $groups[1]['windowSeconds']);
        self::assertSame('ok', $groups[1]['level']);
        self::assertStringNotContainsString(self::PILOT_SCOPE, $content);
        self::assertArrayHasKey('esiErrorBudget', $stats);
    }

    public function testStatsExposeAnEmptyListBeforeAnyObservation(): void
    {
        $this->client->loginUser($this->persistedAdmin(), 'api');

        $this->client->request('GET', '/api/admin/stats', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $stats = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('esiRateLimits', $stats);
        self::assertSame([], $stats['esiRateLimits']);
    }

    /**
     * @param array<array-key, mixed> $group
     * @return list<array-key>
     */
    private function sortedKeys(array $group): array
    {
        $keys = array_keys($group);
        sort($keys);

        return $keys;
    }

    /**
     * @return array<string, list<string>>
     */
    private function headers(string $group, string $limit, string $remaining): array
    {
        return [
            'x-ratelimit-group' => [$group],
            'x-ratelimit-limit' => [$limit],
            'x-ratelimit-remaining' => [$remaining],
        ];
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
