<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Notification;

use App\Service\Notification\DiscordWebhookUrlValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(DiscordWebhookUrlValidator::class)]
#[Group('issue-1392')]
final class DiscordWebhookUrlValidatorTest extends TestCase
{
    private const string WEBHOOK_ID = '123456789012345678';
    private const string WEBHOOK_TOKEN = 'abcDEF_ghi-JKL0123456789xyz';
    private const string WEBHOOK_PATH = '/api/webhooks/' . self::WEBHOOK_ID . '/' . self::WEBHOOK_TOKEN;

    private DiscordWebhookUrlValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new DiscordWebhookUrlValidator();
    }

    public function testAcceptsDiscordWebhookUrlUnchanged(): void
    {
        $url = 'https://discord.com' . self::WEBHOOK_PATH;

        self::assertSame($url, $this->validator->validate($url));
    }

    public function testAcceptsVersionedPathAndLowercasesTheHost(): void
    {
        $normalized = $this->validator->validate('https://DISCORD.com/api/v10/webhooks/' . self::WEBHOOK_ID . '/' . self::WEBHOOK_TOKEN);

        self::assertSame('https://discord.com/api/v10/webhooks/' . self::WEBHOOK_ID . '/' . self::WEBHOOK_TOKEN, $normalized);
    }

    public function testAcceptsEveryOfficialDiscordHost(): void
    {
        foreach (['canary.discord.com', 'ptb.discord.com', 'discordapp.com'] as $host) {
            $url = 'https://' . $host . self::WEBHOOK_PATH;

            self::assertSame($url, $this->validator->validate($url));
        }
    }

    public function testAcceptsExplicitPort443AndDropsIt(): void
    {
        self::assertSame(
            'https://discord.com' . self::WEBHOOK_PATH,
            $this->validator->validate('https://discord.com:443' . self::WEBHOOK_PATH),
        );
    }

    public function testAcceptsBoundaryLengthsOfIdAndToken(): void
    {
        $shortest = 'https://discord.com/api/webhooks/' . str_repeat('7', 17) . '/' . str_repeat('a', 20);
        $longest = 'https://discord.com/api/webhooks/' . str_repeat('9', 20) . '/' . str_repeat('Z', 100);

        self::assertSame($shortest, $this->validator->validate($shortest));
        self::assertSame($longest, $this->validator->validate($longest));
    }

    public function testRejectsHttpScheme(): void
    {
        $this->assertRejected('http://discord.com' . self::WEBHOOK_PATH, 'https');
    }

    public function testRejectsHostThatOnlyStartsWithDiscord(): void
    {
        $this->assertRejected('https://discord.com.evil.io' . self::WEBHOOK_PATH, 'host');
    }

    public function testRejectsHostThatOnlyEndsWithDiscord(): void
    {
        $this->assertRejected('https://evil-discord.com' . self::WEBHOOK_PATH, 'host');
    }

    public function testRejectsCredentialsDisguisingTheHost(): void
    {
        $this->assertRejected('https://discord.com@evil.io' . self::WEBHOOK_PATH, '@');
    }

    public function testRejectsPortDisguisingTheHost(): void
    {
        $this->assertRejected('https://discord.com:8080@evil.io' . self::WEBHOOK_PATH, '@');
    }

    public function testRejectsPortOtherThan443(): void
    {
        $this->assertRejected('https://discord.com:8443' . self::WEBHOOK_PATH, 'port');
    }

    public function testRejectsQueryString(): void
    {
        $this->assertRejected('https://discord.com' . self::WEBHOOK_PATH . '?wait=true', 'query');
    }

    public function testRejectsFragment(): void
    {
        $this->assertRejected('https://discord.com' . self::WEBHOOK_PATH . '#top', 'fragment');
    }

    public function testRejectsIdAndTokenThatAreTooShort(): void
    {
        $this->assertRejected('https://discord.com/api/webhooks/12/short', 'path');
    }

    public function testRejectsIdOf16AndOf21Digits(): void
    {
        $this->assertRejected('https://discord.com/api/webhooks/' . str_repeat('1', 16) . '/' . self::WEBHOOK_TOKEN, 'path');
        $this->assertRejected('https://discord.com/api/webhooks/' . str_repeat('1', 21) . '/' . self::WEBHOOK_TOKEN, 'path');
    }

    public function testRejectsTokenOf19And101Characters(): void
    {
        $this->assertRejected('https://discord.com/api/webhooks/' . self::WEBHOOK_ID . '/' . str_repeat('a', 19), 'path');
        $this->assertRejected('https://discord.com/api/webhooks/' . self::WEBHOOK_ID . '/' . str_repeat('a', 101), 'path');
    }

    public function testRejectsTokenWithForbiddenCharacter(): void
    {
        $this->assertRejected('https://discord.com/api/webhooks/' . self::WEBHOOK_ID . '/' . str_repeat('a', 20) . '.', 'path');
    }

    public function testRejectsOtherPath(): void
    {
        $this->assertRejected('https://discord.com/other/path', 'path');
    }

    public function testRejectsTrailingSlash(): void
    {
        $this->assertRejected('https://discord.com' . self::WEBHOOK_PATH . '/', 'path');
    }

    public function testRejectsEmptyString(): void
    {
        $this->assertRejected('', 'empty');
    }

    public function testRejectsUrlOf513Characters(): void
    {
        $prefix = 'https://discord.com/api/webhooks/' . self::WEBHOOK_ID . '/';
        $tooLong = $prefix . str_repeat('a', 513 - strlen($prefix));

        self::assertSame(513, strlen($tooLong));
        $this->assertRejected($tooLong, '512');
    }

    public function testRejectsBackslash(): void
    {
        $this->assertRejected('https://discord.com\\' . self::WEBHOOK_PATH, 'backslash');
    }

    public function testRejectsSpace(): void
    {
        $this->assertRejected('https://discord.com' . self::WEBHOOK_PATH . ' ', 'space');
    }

    public function testRejectsControlCharacter(): void
    {
        $this->assertRejected('https://discord.com' . self::WEBHOOK_PATH . "\n", 'control');
    }

    private function assertRejected(string $url, string $messagePart): void
    {
        try {
            $this->validator->validate($url);
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsStringIgnoringCase($messagePart, $exception->getMessage());
            self::assertStringNotContainsString(self::WEBHOOK_TOKEN, $exception->getMessage());

            return;
        }

        self::fail('Expected InvalidArgumentException for ' . strlen($url) . ' characters URL');
    }
}
