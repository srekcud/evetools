<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Entity\EveToken;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

class EsiRateLimitBudget
{
    public const float LOW_BELOW_RATIO = 0.20;
    public const float CRITICAL_BELOW_RATIO = 0.05;
    public const int SLOW_DOWN_MAX_DELAY_MS = 1000;
    public const int MAX_PAUSE_SECONDS = 60;
    public const int TOKENS_PER_SUCCESS = 2;
    public const string PUBLIC_SCOPE = 'public';

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
    }

    public static function scopeOf(?EveToken $token): ?string
    {
        return null;
    }

    public static function levelOf(float $remaining, int $limit): string
    {
        return 'ok';
    }

    /**
     * @param array<string, list<string>> $headers lower-cased header names, as returned by ResponseInterface::getHeaders(false)
     */
    public function record(string $endpoint, ?string $scope, int $statusCode, array $headers): void
    {
    }

    public function groupOf(string $endpoint): ?string
    {
        return null;
    }

    /**
     * @return array{group: string, limit: int, windowSeconds: int, remaining: int, observedAt: int, retryUntil: ?int}|null
     */
    public function bucketOf(string $endpoint, ?string $scope): ?array
    {
        return null;
    }

    public function estimatedRemaining(string $endpoint, ?string $scope): ?float
    {
        return null;
    }

    /**
     * @return list<array{group: string, remaining: int, limit: int, windowSeconds: int, observedAt: string, level: string}>
     */
    public function snapshot(): array
    {
        return [];
    }
}
