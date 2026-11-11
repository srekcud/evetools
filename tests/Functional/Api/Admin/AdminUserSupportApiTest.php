<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Admin;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Message\SyncUserIndustryJobs;
use App\Service\ESI\AuthenticationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[Group('issue-647')]
final class AdminUserSupportApiTest extends WebTestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 2_113_647_101;
    private const PLAYER_EVE_CHARACTER_ID = 2_113_647_102;
    private const UNKNOWN_USER_ID = '0192f0c4-6d2e-7a1b-9c3d-000000000000';

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

    public function testAnAdminSearchesAUserByEveCharacterId(): void
    {
        $this->client->loginUser($this->persistedUser(self::ADMIN_EVE_CHARACTER_ID), 'api');
        $player = $this->persistedUser(self::PLAYER_EVE_CHARACTER_ID);

        $this->request('GET', '/api/admin/users?search=' . self::PLAYER_EVE_CHARACTER_ID);

        self::assertResponseIsSuccessful();
        $hits = $this->jsonBody();
        self::assertCount(1, $hits);
        self::assertSame($player->getId()?->toRfc4122(), $hits[0]['userId']);
        self::assertSame(self::PLAYER_EVE_CHARACTER_ID, $hits[0]['mainEveCharacterId']);
        self::assertSame(1, $hits[0]['characterCount']);
    }

    public function testASearchWithoutTermIsEmpty(): void
    {
        $this->client->loginUser($this->persistedUser(self::ADMIN_EVE_CHARACTER_ID), 'api');

        $this->request('GET', '/api/admin/users');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonBody());
    }

    public function testAnAdminReadsTheSheetOfAUser(): void
    {
        $this->client->loginUser($this->persistedUser(self::ADMIN_EVE_CHARACTER_ID), 'api');
        $player = $this->persistedUser(self::PLAYER_EVE_CHARACTER_ID, array_slice(AuthenticationService::getRequiredScopes(), 1));

        $this->request('GET', '/api/admin/users/' . $player->getId()?->toRfc4122());

        self::assertResponseIsSuccessful();
        $sheet = $this->jsonBody();
        self::assertSame($player->getId()?->toRfc4122(), $sheet['userId']);
        self::assertSame(self::PLAYER_EVE_CHARACTER_ID, $sheet['characters'][0]['eveCharacterId']);
        self::assertTrue($sheet['characters'][0]['isMain']);
        self::assertSame('valid', $sheet['characters'][0]['tokenState']);
        self::assertSame([AuthenticationService::getRequiredScopes()[0]], $sheet['characters'][0]['missingScopes']);
        self::assertSame('industry', $sheet['syncs'][0]['type']);
        self::assertTrue($sheet['syncs'][0]['triggerable']);
        self::assertArrayNotHasKey('accessToken', $sheet['characters'][0]);
    }

    public function testTheSheetOfAnUnknownUserIsNotFound(): void
    {
        $this->client->loginUser($this->persistedUser(self::ADMIN_EVE_CHARACTER_ID), 'api');

        $this->request('GET', '/api/admin/users/' . self::UNKNOWN_USER_ID);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnAdminTriggersAUserSyncThatReachesTheAsyncQueue(): void
    {
        $this->client->loginUser($this->persistedUser(self::ADMIN_EVE_CHARACTER_ID), 'api');
        $player = $this->persistedUser(self::PLAYER_EVE_CHARACTER_ID);

        $this->postSync($player->getId()?->toRfc4122() ?? '', 'industry');

        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonBody()['success']);
        $messages = array_map(static fn ($envelope): object => $envelope->getMessage(), $this->asyncTransport()->getSent());
        self::assertEquals([new SyncUserIndustryJobs($player->getId()?->toRfc4122() ?? '')], $messages);
    }

    public function testASyncTypeWithoutUserMessageIsRefusedWithoutDispatch(): void
    {
        $this->client->loginUser($this->persistedUser(self::ADMIN_EVE_CHARACTER_ID), 'api');
        $player = $this->persistedUser(self::PLAYER_EVE_CHARACTER_ID);

        $this->postSync($player->getId()?->toRfc4122() ?? '', 'assets');

        self::assertFalse($this->jsonBody()['success']);
        self::assertSame([], $this->asyncTransport()->getSent());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function adminRoutes(): iterable
    {
        yield 'search' => ['GET', '/api/admin/users?search=pilot'];
        yield 'sheet' => ['GET', '/api/admin/users/%s'];
        yield 'trigger sync' => ['POST', '/api/admin/users/%s/sync'];
    }

    #[DataProvider('adminRoutes')]
    public function testAnAnonymousVisitorIsRejected(string $method, string $uriTemplate): void
    {
        $player = $this->persistedUser(self::PLAYER_EVE_CHARACTER_ID);

        $this->requestRoute($method, sprintf($uriTemplate, $player->getId()?->toRfc4122()));

        self::assertResponseStatusCodeSame(401);
        self::assertSame([], $this->asyncTransport()->getSent());
    }

    #[DataProvider('adminRoutes')]
    public function testANonAdminIsForbidden(string $method, string $uriTemplate): void
    {
        $player = $this->persistedUser(self::PLAYER_EVE_CHARACTER_ID);
        $this->client->loginUser($player, 'api');

        $this->requestRoute($method, sprintf($uriTemplate, $player->getId()?->toRfc4122()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->asyncTransport()->getSent());
    }

    private function requestRoute(string $method, string $uri): void
    {
        if ($method === 'POST') {
            $this->client->request(
                'POST',
                $uri,
                server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
                content: json_encode(['type' => 'industry'], JSON_THROW_ON_ERROR),
            );

            return;
        }

        $this->request($method, $uri);
    }

    private function request(string $method, string $uri): void
    {
        $this->client->request($method, $uri, server: ['HTTP_ACCEPT' => 'application/json']);
    }

    private function postSync(string $userId, string $type): void
    {
        $this->client->request(
            'POST',
            sprintf('/api/admin/users/%s/sync', $userId),
            server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['type' => $type], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * @param list<string>|null $scopes
     */
    private function persistedUser(int $eveCharacterId, ?array $scopes = null): User
    {
        $user = new User();
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName('Pilot ' . $eveCharacterId)
            ->setCorporationId(98_000_001)
            ->setCorporationName('Test Corp');
        $character->setEveToken((new EveToken())
            ->setAccessToken('access')
            ->setRefreshTokenEncrypted('refresh')
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+20 minutes'))
            ->setScopes($scopes ?? AuthenticationService::getRequiredScopes()));
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }
}
