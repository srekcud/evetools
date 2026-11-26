<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Notification;

use App\Entity\Character;
use App\Entity\User;
use App\Service\Notification\DiscordWebhookSender;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1392')]
final class DiscordWebhookApiTest extends WebTestCase
{
    private const string WEBHOOK_TOKEN = 'abcDEF_ghi-JKL0123456789xyz';
    private const string WEBHOOK_URL = 'https://discord.com/api/webhooks/123456789012345678/' . self::WEBHOOK_TOKEN;
    private const string OTHER_WEBHOOK_URL = 'https://discord.com/api/webhooks/987654321098765432/QRSTuv_wxy-ZAB9876543210abc';
    private const string ENDPOINT = '/api/me/discord-webhook';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testEveryOperationRequiresAuthentication(): void
    {
        foreach ([['GET', self::ENDPOINT, []], ['PUT', self::ENDPOINT, ['url' => self::WEBHOOK_URL]], ['DELETE', self::ENDPOINT, []], ['POST', self::ENDPOINT . '/test', []]] as [$method, $uri, $body]) {
            $this->request($method, $uri, $body);

            self::assertResponseStatusCodeSame(401, $method . ' ' . $uri);
        }
    }

    public function testGetReportsNotConfiguredWhenNoWebhookIsRegistered(): void
    {
        $this->client->loginUser($this->persistPilot(2_112_392_001, 'Ilsa Verhoeven'), 'api');

        $this->request('GET', self::ENDPOINT);

        self::assertResponseStatusCodeSame(200);
        $this->assertConfigured(false);
    }

    public function testPutRejectsAnUrlOutsideDiscordWithAnExplicitMessage(): void
    {
        $this->client->loginUser($this->persistPilot(2_112_392_001, 'Ilsa Verhoeven'), 'api');

        $this->request('PUT', self::ENDPOINT, ['url' => 'https://discord.com.evil.io/api/webhooks/123456789012345678/' . self::WEBHOOK_TOKEN]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsStringIgnoringCase('host', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->storedWebhookCount());
    }

    public function testPutRejectsAnUrlOf513Characters(): void
    {
        $this->client->loginUser($this->persistPilot(2_112_392_001, 'Ilsa Verhoeven'), 'api');

        $prefix = 'https://discord.com/api/webhooks/123456789012345678/';
        $url = $prefix . str_repeat('a', 513 - strlen($prefix));
        self::assertSame(513, strlen($url));

        $this->request('PUT', self::ENDPOINT, ['url' => $url]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->storedWebhookCount());
    }

    public function testPutWithoutUrlOrWithEmptyUrlIsRejectedAsUnprocessable(): void
    {
        $this->client->loginUser($this->persistPilot(2_112_392_001, 'Ilsa Verhoeven'), 'api');

        $this->request('PUT', self::ENDPOINT, []);
        self::assertResponseStatusCodeSame(422);

        $this->request('PUT', self::ENDPOINT, ['url' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->storedWebhookCount());
    }

    public function testPutStoresTheUrlEncryptedAndNeverReturnsIt(): void
    {
        $user = $this->persistPilot(2_112_392_001, 'Ilsa Verhoeven');
        $this->client->loginUser($user, 'api');

        $this->request('PUT', self::ENDPOINT, ['url' => self::WEBHOOK_URL]);

        self::assertResponseStatusCodeSame(200);
        $this->assertConfigured(true);
        self::assertStringNotContainsString(self::WEBHOOK_TOKEN, (string) $this->client->getResponse()->getContent());

        $this->request('GET', self::ENDPOINT);
        self::assertResponseStatusCodeSame(200);
        $this->assertConfigured(true);
        self::assertStringNotContainsString(self::WEBHOOK_TOKEN, (string) $this->client->getResponse()->getContent());

        $stored = $this->storedUrlsOf($user);
        self::assertCount(1, $stored);
        self::assertNotSame(self::WEBHOOK_URL, $stored[0]);
        self::assertStringNotContainsString(self::WEBHOOK_TOKEN, $stored[0]);
    }

    public function testPutReplacesTheExistingWebhookInTheSingleRow(): void
    {
        $user = $this->persistPilot(2_112_392_001, 'Ilsa Verhoeven');
        $this->client->loginUser($user, 'api');
        $this->request('PUT', self::ENDPOINT, ['url' => self::WEBHOOK_URL]);
        self::assertResponseStatusCodeSame(200);
        $first = $this->storedUrlsOf($user);

        $this->request('PUT', self::ENDPOINT, ['url' => self::OTHER_WEBHOOK_URL]);

        self::assertResponseStatusCodeSame(200);
        $this->assertConfigured(true);
        $second = $this->storedUrlsOf($user);
        self::assertCount(1, $first);
        self::assertCount(1, $second);
        self::assertNotSame($first[0], $second[0]);
    }

    public function testDeleteRemovesTheWebhook(): void
    {
        $user = $this->persistPilot(2_112_392_001, 'Ilsa Verhoeven');
        $this->client->loginUser($user, 'api');
        $this->request('PUT', self::ENDPOINT, ['url' => self::WEBHOOK_URL]);
        self::assertResponseStatusCodeSame(200);

        $this->request('DELETE', self::ENDPOINT);

        self::assertResponseStatusCodeSame(204);
        $this->request('GET', self::ENDPOINT);
        $this->assertConfigured(false);
        self::assertSame([], $this->storedUrlsOf($user));
    }

    public function testDeleteWithoutWebhookIsANoOp(): void
    {
        $this->client->loginUser($this->persistPilot(2_112_392_001, 'Ilsa Verhoeven'), 'api');

        $this->request('DELETE', self::ENDPOINT);

        self::assertResponseStatusCodeSame(204);
    }

    public function testWebhooksAreIsolatedBetweenUsers(): void
    {
        $owner = $this->persistPilot(2_112_392_001, 'Ilsa Verhoeven');
        $other = $this->persistPilot(2_112_392_002, 'Tomas Brandt');
        $this->client->loginUser($owner, 'api');
        $this->request('PUT', self::ENDPOINT, ['url' => self::WEBHOOK_URL]);
        self::assertResponseStatusCodeSame(200);

        $this->client->loginUser($other, 'api');
        $this->request('GET', self::ENDPOINT);
        $this->assertConfigured(false);

        $this->request('DELETE', self::ENDPOINT);
        self::assertResponseStatusCodeSame(204);

        $this->client->loginUser($owner, 'api');
        $this->request('GET', self::ENDPOINT);
        $this->assertConfigured(true);
        self::assertCount(1, $this->storedUrlsOf($owner));
        self::assertSame([], $this->storedUrlsOf($other));
    }

    public function testTwoUsersCanRegisterTheSameWebhookUrl(): void
    {
        $owner = $this->persistPilot(2_112_392_001, 'Ilsa Verhoeven');
        $other = $this->persistPilot(2_112_392_002, 'Tomas Brandt');

        $this->client->loginUser($owner, 'api');
        $this->request('PUT', self::ENDPOINT, ['url' => self::WEBHOOK_URL]);
        self::assertResponseStatusCodeSame(200);
        $this->client->loginUser($other, 'api');
        $this->request('PUT', self::ENDPOINT, ['url' => self::WEBHOOK_URL]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, $this->storedWebhookCount());
    }

    public function testTestWithoutWebhookIsRejectedAsUnprocessable(): void
    {
        $this->client->loginUser($this->persistPilot(2_112_392_001, 'Ilsa Verhoeven'), 'api');

        $this->request('POST', self::ENDPOINT . '/test');

        self::assertResponseStatusCodeSame(422);
    }

    public function testTestSendsTheTestMessageThroughTheSender(): void
    {
        $user = $this->persistPilot(2_112_392_001, 'Ilsa Verhoeven');
        $this->client->loginUser($user, 'api');
        $this->request('PUT', self::ENDPOINT, ['url' => self::WEBHOOK_URL]);
        self::assertResponseStatusCodeSame(200);
        $sender = $this->createMock(DiscordWebhookSender::class);
        $sender->expects(self::once())->method('send')->with($user, 'Test', self::isType('string'));
        self::getContainer()->set(DiscordWebhookSender::class, $sender);

        $this->request('POST', self::ENDPOINT . '/test');

        self::assertResponseStatusCodeSame(200);
    }

    public function testTestAnswersBadGatewayWithTheDiscordHttpCodeWhenTheSendFails(): void
    {
        $this->client->loginUser($this->persistPilot(2_112_392_001, 'Ilsa Verhoeven'), 'api');
        $this->request('PUT', self::ENDPOINT, ['url' => self::WEBHOOK_URL]);
        self::assertResponseStatusCodeSame(200);
        $sender = $this->createStub(DiscordWebhookSender::class);
        $sender->method('send')->willThrowException(new \RuntimeException('Discord webhook answered HTTP 404'));
        self::getContainer()->set(DiscordWebhookSender::class, $sender);

        $this->request('POST', self::ENDPOINT . '/test');

        self::assertResponseStatusCodeSame(502);
        self::assertStringContainsString('404', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString(self::WEBHOOK_TOKEN, (string) $this->client->getResponse()->getContent());
    }

    private function assertConfigured(bool $expected): void
    {
        $json = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('configured', $json);
        self::assertSame($expected, $json['configured']);
        self::assertArrayNotHasKey('url', $json);
    }

    private function storedWebhookCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM user_discord_webhooks');
    }

    /**
     * @return list<string>
     */
    private function storedUrlsOf(User $user): array
    {
        return array_values($this->em->getConnection()->fetchFirstColumn(
            'SELECT url_encrypted FROM user_discord_webhooks WHERE user_id = :userId',
            ['userId' => $user->getId()?->toRfc4122()],
        ));
    }

    private function persistPilot(int $eveCharacterId, string $name): User
    {
        $user = new User();
        $this->em->persist($user);
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(98_392_001)
            ->setCorporationName('Verhoeven Deep Core');
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $uri, array $body = []): void
    {
        $this->client->request(
            $method,
            $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: $body === [] ? ($method === 'PUT' || $method === 'POST' ? '{}' : null) : json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}
