<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Notification;

use App\Entity\User;
use App\Entity\UserDiscordWebhook;
use App\Repository\UserDiscordWebhookRepository;
use App\Service\Notification\DiscordWebhookSender;
use App\Service\Notification\DiscordWebhookUrlCipher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(DiscordWebhookSender::class)]
#[Group('issue-1392')]
final class DiscordWebhookSenderTest extends TestCase
{
    private const string WEBHOOK_TOKEN = 'abcDEF_ghi-JKL0123456789xyz';
    private const string WEBHOOK_URL = 'https://discord.com/api/webhooks/123456789012345678/' . self::WEBHOOK_TOKEN;

    private DiscordWebhookUrlCipher $cipher;
    private User $user;

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->cipher = new DiscordWebhookUrlCipher(base64_encode(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
        $this->user = new User();
        $this->requests = [];
    }

    public function testPostsTheTitleAndMessageToTheDecryptedWebhookUrl(): void
    {
        $this->sender(new MockResponse('', ['http_code' => 204]))
            ->send($this->user, 'Launchpad empty', 'Your factory launchpad on Ishomilken IV is empty.');

        self::assertCount(1, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame(self::WEBHOOK_URL, $this->requests[0]['url']);
        self::assertSame(
            [
                'content' => "**Launchpad empty**\nYour factory launchpad on Ishomilken IV is empty.",
                'allowed_mentions' => ['parse' => []],
            ],
            json_decode((string) $this->requests[0]['options']['body'], true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testSendsNoAuthorizationHeader(): void
    {
        $this->sender(new MockResponse('', ['http_code' => 204]))->send($this->user, 'Title', 'Message');
        self::assertCount(1, $this->requests);

        $headers = array_map('strtolower', $this->requests[0]['options']['headers']);
        self::assertSame([], array_values(array_filter($headers, static fn (string $header): bool => str_starts_with($header, 'authorization:'))));
    }

    public function testDisablesRedirectsAndBoundsTheRequestToFiveSeconds(): void
    {
        $this->sender(new MockResponse('', ['http_code' => 204]))->send($this->user, 'Title', 'Message');
        self::assertCount(1, $this->requests);

        $options = $this->requests[0]['options'];
        self::assertSame(0, $options['max_redirects']);
        self::assertEquals(5, $options['timeout']);
        self::assertEquals(5, $options['max_duration']);
    }

    public function testTruncatesTheContentToTwoThousandCharacters(): void
    {
        $this->sender(new MockResponse('', ['http_code' => 204]))->send($this->user, 'Title', str_repeat('é', 3000));
        self::assertCount(1, $this->requests);

        $content = json_decode((string) $this->requests[0]['options']['body'], true, flags: JSON_THROW_ON_ERROR)['content'];
        self::assertSame(2000, mb_strlen($content));
        self::assertSame(mb_substr("**Title**\n" . str_repeat('é', 3000), 0, 2000), $content);
    }

    public function testKeepsAContentOfExactlyTwoThousandCharacters(): void
    {
        $message = str_repeat('m', 2000 - mb_strlen("**Title**\n"));

        $this->sender(new MockResponse('', ['http_code' => 204]))->send($this->user, 'Title', $message);
        self::assertCount(1, $this->requests);

        $content = json_decode((string) $this->requests[0]['options']['body'], true, flags: JSON_THROW_ON_ERROR)['content'];
        self::assertSame("**Title**\n" . $message, $content);
    }

    public function testKeepsMentionsInertInTheBody(): void
    {
        $this->sender(new MockResponse('', ['http_code' => 204]))->send($this->user, 'Title', '@everyone <@123456789012345678>');
        self::assertCount(1, $this->requests);

        $body = json_decode((string) $this->requests[0]['options']['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['parse' => []], $body['allowed_mentions']);
    }

    public function testDoesNothingWhenNoWebhookIsRegistered(): void
    {
        $this->sender(new MockResponse('', ['http_code' => 204]), registered: false)->send($this->user, 'Title', 'Message');

        self::assertSame([], $this->requests);
    }

    public function testRejectedWebhookThrowsWithTheHttpCodeAndWithoutTheUrl(): void
    {
        foreach ([404, 429, 500] as $httpCode) {
            $this->requests = [];
            $exception = $this->failureOf($this->sender(new MockResponse('{"message": "Unknown Webhook"}', ['http_code' => $httpCode])));

            self::assertNotNull($exception, 'HTTP ' . $httpCode . ' must throw');
            self::assertStringContainsString((string) $httpCode, $exception->getMessage());
            self::assertStringNotContainsString(self::WEBHOOK_TOKEN, $exception->getMessage());
            self::assertStringNotContainsString('discord.com/api/webhooks', $exception->getMessage());
            self::assertCount(1, $this->requests);
        }
    }

    public function testAnyTwoHundredSeriesStatusIsASuccess(): void
    {
        self::assertNull($this->failureOf($this->sender(new MockResponse('', ['http_code' => 204]))));
        self::assertNull($this->failureOf($this->sender(new MockResponse('{}', ['http_code' => 200]))));
        self::assertCount(2, $this->requests);
    }

    public function testRedirectResponseIsAFailureAndIsNotFollowed(): void
    {
        $exception = $this->failureOf($this->sender(new MockResponse('', [
            'http_code' => 302,
            'response_headers' => ['Location: https://evil.io/collect'],
        ])));

        self::assertNotNull($exception);
        self::assertStringContainsString('302', $exception->getMessage());
        self::assertCount(1, $this->requests);
    }

    private function failureOf(DiscordWebhookSender $sender): ?\Throwable
    {
        try {
            $sender->send($this->user, 'Title', 'Message');
        } catch (\Throwable $exception) {
            return $exception;
        }

        return null;
    }

    private function sender(MockResponse $response, bool $registered = true): DiscordWebhookSender
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return $response;
        });

        $repository = $this->createStub(UserDiscordWebhookRepository::class);
        $repository->method('findByUser')->willReturn($registered ? $this->webhook() : null);

        return new DiscordWebhookSender($httpClient, $repository, $this->cipher);
    }

    private function webhook(): UserDiscordWebhook
    {
        return (new UserDiscordWebhook())
            ->setUser($this->user)
            ->setUrlEncrypted($this->cipher->encrypt(self::WEBHOOK_URL));
    }
}
