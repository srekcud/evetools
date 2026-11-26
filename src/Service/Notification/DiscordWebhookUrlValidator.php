<?php

declare(strict_types=1);

namespace App\Service\Notification;

class DiscordWebhookUrlValidator
{
    public const int MAX_URL_LENGTH = 512;

    public const array ALLOWED_HOSTS = ['discord.com', 'canary.discord.com', 'ptb.discord.com', 'discordapp.com'];

    private const int HTTPS_PORT = 443;

    private const string PATH_PATTERN = '#^/api(?:/v\d+)?/webhooks/\d{17,20}/[A-Za-z0-9_-]{20,100}$#D';

    public function validate(string $url): string
    {
        $this->assertWellFormedString($url);

        $parts = parse_url($url);
        if ($parts === false) {
            throw new \InvalidArgumentException('The Discord webhook URL is malformed.');
        }

        if (strtolower($parts['scheme'] ?? '') !== 'https') {
            throw new \InvalidArgumentException('The Discord webhook URL must use https.');
        }

        $host = strtolower($parts['host'] ?? '');
        if (!in_array($host, self::ALLOWED_HOSTS, true)) {
            throw new \InvalidArgumentException('The Discord webhook URL host is not a Discord host.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('The Discord webhook URL must not contain credentials (@).');
        }

        if (isset($parts['port']) && $parts['port'] !== self::HTTPS_PORT) {
            throw new \InvalidArgumentException('The Discord webhook URL must not use a port other than 443.');
        }

        if (str_contains($url, '?')) {
            throw new \InvalidArgumentException('The Discord webhook URL must not contain a query string.');
        }

        if (str_contains($url, '#')) {
            throw new \InvalidArgumentException('The Discord webhook URL must not contain a fragment.');
        }

        $path = $parts['path'] ?? '';
        if (preg_match(self::PATH_PATTERN, $path) !== 1) {
            throw new \InvalidArgumentException('The Discord webhook URL path must look like /api/webhooks/{id}/{token}.');
        }

        return 'https://' . $host . $path;
    }

    private function assertWellFormedString(string $url): void
    {
        if ($url === '') {
            throw new \InvalidArgumentException('The Discord webhook URL is empty.');
        }

        if (strlen($url) > self::MAX_URL_LENGTH) {
            throw new \InvalidArgumentException('The Discord webhook URL must be at most 512 characters long.');
        }

        if (str_contains($url, '\\')) {
            throw new \InvalidArgumentException('The Discord webhook URL must not contain a backslash.');
        }

        if (str_contains($url, '@')) {
            throw new \InvalidArgumentException('The Discord webhook URL must not contain credentials (@).');
        }

        if (str_contains($url, ' ')) {
            throw new \InvalidArgumentException('The Discord webhook URL must not contain a space.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            throw new \InvalidArgumentException('The Discord webhook URL must not contain a control character.');
        }
    }
}
