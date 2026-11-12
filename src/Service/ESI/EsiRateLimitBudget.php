<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Entity\EveToken;
use Psr\Cache\CacheItemInterface;
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

    private const string FAMILY_KEY_PREFIX = 'esi_rl_family_';
    private const string BUCKET_KEY_PREFIX = 'esi_rl_bucket_';
    private const string LOWEST_KEY_PREFIX = 'esi_rl_lowest_';
    private const string GROUPS_KEY = 'esi_rl_groups';
    private const string GROUP_HEADER = 'x-ratelimit-group';
    private const string LIMIT_HEADER = 'x-ratelimit-limit';
    private const string REMAINING_HEADER = 'x-ratelimit-remaining';
    private const string RETRY_AFTER_HEADER = 'retry-after';
    private const string LIMIT_PATTERN = '#^(\d+)/(\d+)([mh])$#';
    private const string COUNT_PATTERN = '#^-?\d+$#';
    private const array UNIT_SECONDS = ['m' => 60, 'h' => 3600];
    private const int TOO_MANY_REQUESTS = 429;
    private const int GROUP_ASSOCIATION_TTL_SECONDS = 7 * 24 * 3600;
    private const int GROUPS_INDEX_TTL_SECONDS = 7 * 24 * 3600;
    private const int LOWEST_RETENTION_WINDOWS = 2;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
    }

    public static function scopeOf(?EveToken $token): ?string
    {
        if ($token === null) {
            return self::PUBLIC_SCOPE;
        }

        return $token->getCharacter()?->getId()?->toRfc4122();
    }

    public static function levelOf(float $remaining, int $limit): string
    {
        return match (true) {
            $remaining < $limit * self::CRITICAL_BELOW_RATIO => 'critical',
            $remaining < $limit * self::LOW_BELOW_RATIO => 'low',
            default => 'ok',
        };
    }

    /**
     * @param array<string, list<string>> $headers lower-cased header names, as returned by ResponseInterface::getHeaders(false)
     */
    public function record(string $endpoint, ?string $scope, int $statusCode, array $headers): void
    {
        if ($scope === null) {
            return;
        }

        $group = $headers[self::GROUP_HEADER][0] ?? '';
        $window = self::parseLimit($headers[self::LIMIT_HEADER][0] ?? null);
        if ($group === '' || $window === null) {
            return;
        }

        $now = $this->clock->now()->getTimestamp();
        $tooManyRequests = $statusCode === self::TOO_MANY_REQUESTS;
        $remaining = $tooManyRequests ? 0 : self::parseCount($headers[self::REMAINING_HEADER][0] ?? null);
        if ($remaining === null) {
            return;
        }
        if ($window['limit'] === 0 || $remaining < 0 || $remaining > $window['limit']) {
            $this->logger->warning('ESI rate limit observation of group {group} ignored: remaining {remaining} out of limit {limit}', [
                'group' => $group,
                'remaining' => $remaining,
                'limit' => $window['limit'],
            ]);

            return;
        }

        $retryAfter = $tooManyRequests ? self::parseCount($headers[self::RETRY_AFTER_HEADER][0] ?? null) : null;
        $bucket = [
            'group' => $group,
            'limit' => $window['limit'],
            'windowSeconds' => $window['windowSeconds'],
            'remaining' => $remaining,
            'observedAt' => $now,
            'retryUntil' => $retryAfter !== null && $retryAfter >= 0 ? $now + $retryAfter : null,
        ];

        try {
            $this->store($endpoint, $scope, $bucket);
        } catch (\Throwable $e) {
            $this->logger->warning('ESI rate limit observation could not be stored: {message}', ['message' => $e->getMessage()]);
        }
    }

    public function groupOf(string $endpoint): ?string
    {
        try {
            $item = $this->cache->getItem($this->familyKeyOf($endpoint));
        } catch (\Throwable $e) {
            $this->logger->warning('ESI rate limit group could not be read: {message}', ['message' => $e->getMessage()]);

            return null;
        }

        /** @var string|null */
        return $item->isHit() ? $item->get() : null;
    }

    /**
     * @return array{group: string, limit: int, windowSeconds: int, remaining: int, observedAt: int, retryUntil: ?int}|null
     */
    public function bucketOf(string $endpoint, ?string $scope): ?array
    {
        if ($scope === null) {
            return null;
        }
        $group = $this->groupOf($endpoint);
        if ($group === null) {
            return null;
        }

        return $this->readBucket($group, $scope);
    }

    public function estimatedRemaining(string $endpoint, ?string $scope): ?float
    {
        $bucket = $this->bucketOf($endpoint, $scope);

        return $bucket === null ? null : $this->estimate($bucket, $this->nowSeconds());
    }

    public function delayBeforeRequest(string $endpoint, ?string $scope): ?EsiRateLimitDelay
    {
        $bucket = $this->bucketOf($endpoint, $scope);

        return $bucket === null ? null : $this->delayOf($bucket, 0, self::LOW_BELOW_RATIO);
    }

    /**
     * @param iterable<array{endpoint: string, scope: ?string}> $requests
     */
    public function delayBeforeBatch(iterable $requests): ?EsiRateLimitDelay
    {
        $planned = [];
        $groupByFamily = [];
        foreach ($requests as $request) {
            if ($request['scope'] === null) {
                continue;
            }
            $family = EsiErrorBudget::familyOf($request['endpoint']);
            if (!array_key_exists($family, $groupByFamily)) {
                $groupByFamily[$family] = $this->groupOf($request['endpoint']);
            }
            $group = $groupByFamily[$family];
            if ($group === null) {
                continue;
            }
            $planned[$group . '|' . $request['scope']] = [
                'group' => $group,
                'scope' => $request['scope'],
                'count' => ($planned[$group . '|' . $request['scope']]['count'] ?? 0) + 1,
            ];
        }

        $heaviest = null;
        foreach ($planned as $entry) {
            $bucket = $this->readBucket($entry['group'], $entry['scope']);
            if ($bucket === null) {
                continue;
            }
            $delay = $this->delayOf($bucket, $entry['count'] * self::TOKENS_PER_SUCCESS, self::CRITICAL_BELOW_RATIO);
            if ($delay !== null && ($heaviest === null || self::outweighs($delay, $heaviest))) {
                $heaviest = $delay;
            }
        }

        return $heaviest;
    }

    /**
     * @return list<array{group: string, remaining: int, limit: int, windowSeconds: int, observedAt: string, level: string}>
     */
    public function snapshot(): array
    {
        $now = $this->clock->now()->getTimestamp();

        try {
            $groups = $this->readGroups();
            $items = $this->cache->getItems(array_map($this->lowestKeyOf(...), $groups));
            $groupSnapshots = [];
            foreach ($items as $item) {
                if (!$item->isHit()) {
                    continue;
                }
                /** @var array{group: string, limit: int, windowSeconds: int, remaining: int, observedAt: int} $lowest */
                $lowest = $item->get();
                $groupSnapshots[] = [
                    'group' => $lowest['group'],
                    'remaining' => $lowest['remaining'],
                    'limit' => $lowest['limit'],
                    'windowSeconds' => $lowest['windowSeconds'],
                    'observedAt' => (new \DateTimeImmutable('@' . $lowest['observedAt']))->format('c'),
                    'level' => $now - $lowest['observedAt'] > $lowest['windowSeconds']
                        ? 'unknown'
                        : self::levelOf($lowest['remaining'], $lowest['limit']),
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('ESI rate limit snapshot could not be read: {message}', ['message' => $e->getMessage()]);

            return [];
        }

        usort($groupSnapshots, static fn (array $a, array $b): int => $a['remaining'] / $a['limit'] <=> $b['remaining'] / $b['limit']);

        return $groupSnapshots;
    }

    /**
     * @param array{group: string, limit: int, windowSeconds: int, remaining: int, observedAt: int, retryUntil: ?int} $bucket
     */
    private function delayOf(array $bucket, int $plannedSpend, float $recoverToRatio): ?EsiRateLimitDelay
    {
        $now = $this->nowSeconds();
        if ($bucket['retryUntil'] !== null && $now >= $bucket['retryUntil']) {
            return null;
        }

        $estimated = $this->estimate($bucket, $now);
        $projected = $estimated - $plannedSpend;
        $limit = $bucket['limit'];
        $retryWait = $bucket['retryUntil'] !== null ? $bucket['retryUntil'] - $now : 0.0;

        if ($retryWait > 0.0 || $projected < $limit * self::CRITICAL_BELOW_RATIO) {
            $tokensPerSecond = $limit / $bucket['windowSeconds'];
            $recoverySeconds = ($limit * $recoverToRatio - $projected) / $tokensPerSecond;

            return new EsiRateLimitDelay(
                $bucket['group'],
                min(max($retryWait, $recoverySeconds), self::MAX_PAUSE_SECONDS),
                $estimated,
                true,
            );
        }

        $lowThreshold = $limit * self::LOW_BELOW_RATIO;
        if ($projected < $lowThreshold) {
            return new EsiRateLimitDelay(
                $bucket['group'],
                self::SLOW_DOWN_MAX_DELAY_MS * (1 - $projected / $lowThreshold) / 1000,
                $estimated,
                false,
            );
        }

        return null;
    }

    private static function outweighs(EsiRateLimitDelay $candidate, EsiRateLimitDelay $current): bool
    {
        if ($candidate->isPause !== $current->isPause) {
            return $candidate->isPause;
        }

        return $candidate->seconds > $current->seconds;
    }

    /**
     * @param array{group: string, limit: int, windowSeconds: int, remaining: int, observedAt: int, retryUntil: ?int} $bucket
     */
    private function estimate(array $bucket, float $now): float
    {
        if ($bucket['retryUntil'] !== null && $now < $bucket['retryUntil']) {
            return 0.0;
        }

        $recovered = ($now - $bucket['observedAt']) * $bucket['limit'] / $bucket['windowSeconds'];

        return (float) min($bucket['limit'], $bucket['remaining'] + $recovered);
    }

    /**
     * @param array{group: string, limit: int, windowSeconds: int, remaining: int, observedAt: int, retryUntil: ?int} $bucket
     */
    private function store(string $endpoint, string $scope, array $bucket): void
    {
        $group = $bucket['group'];
        $bucketKey = $this->bucketKeyOf($group, $scope);
        $lowestKey = $this->lowestKeyOf($group);
        $items = iterator_to_array($this->cache->getItems([$bucketKey, $lowestKey, self::GROUPS_KEY]));

        $familyItem = $this->cache->getItem($this->familyKeyOf($endpoint));
        $familyItem->set($group)->expiresAt($this->expiryAfter(self::GROUP_ASSOCIATION_TTL_SECONDS));
        $this->cache->saveDeferred($familyItem);

        /** @var array{observedAt: int, remaining: int}|null $previous */
        $previous = $items[$bucketKey]->isHit() ? $items[$bucketKey]->get() : null;
        if ($previous === null || $previous['observedAt'] < $bucket['observedAt'] || ($previous['observedAt'] === $bucket['observedAt'] && $bucket['remaining'] <= $previous['remaining'])) {
            $bucketTtl = max($bucket['windowSeconds'], ($bucket['retryUntil'] ?? 0) - $bucket['observedAt']);
            $this->cache->saveDeferred($items[$bucketKey]->set($bucket)->expiresAt($this->expiryAfter($bucketTtl)));
        }

        if ($this->replacesLowest($items[$lowestKey], $bucket)) {
            $this->cache->saveDeferred($items[$lowestKey]->set([
                'group' => $group,
                'limit' => $bucket['limit'],
                'windowSeconds' => $bucket['windowSeconds'],
                'remaining' => $bucket['remaining'],
                'observedAt' => $bucket['observedAt'],
            ])->expiresAt($this->expiryAfter($bucket['windowSeconds'] * self::LOWEST_RETENTION_WINDOWS)));
        }

        /** @var list<string> $groups */
        $groups = $items[self::GROUPS_KEY]->isHit() ? $items[self::GROUPS_KEY]->get() : [];
        if (!in_array($group, $groups, true)) {
            $groups[] = $group;
        }
        $this->cache->saveDeferred($items[self::GROUPS_KEY]->set($groups)->expiresAt($this->expiryAfter(self::GROUPS_INDEX_TTL_SECONDS)));

        $this->cache->commit();
    }

    /**
     * @param array{limit: int, windowSeconds: int, remaining: int, observedAt: int, retryUntil: ?int} $bucket
     */
    private function replacesLowest(CacheItemInterface $lowestItem, array $bucket): bool
    {
        if (!$lowestItem->isHit()) {
            return true;
        }

        /** @var array{windowSeconds: int, remaining: int, observedAt: int} $lowest */
        $lowest = $lowestItem->get();

        return $bucket['remaining'] <= $lowest['remaining']
            || $bucket['observedAt'] - $lowest['observedAt'] > $lowest['windowSeconds'];
    }

    /**
     * @return array{group: string, limit: int, windowSeconds: int, remaining: int, observedAt: int, retryUntil: ?int}|null
     */
    private function readBucket(string $group, string $scope): ?array
    {
        try {
            $item = $this->cache->getItem($this->bucketKeyOf($group, $scope));
        } catch (\Throwable $e) {
            $this->logger->warning('ESI rate limit bucket could not be read: {message}', ['message' => $e->getMessage()]);

            return null;
        }

        /** @var array{group: string, limit: int, windowSeconds: int, remaining: int, observedAt: int, retryUntil: ?int}|null */
        return $item->isHit() ? $item->get() : null;
    }

    /**
     * @return list<string>
     */
    private function readGroups(): array
    {
        $item = $this->cache->getItem(self::GROUPS_KEY);

        /** @var list<string> */
        return $item->isHit() ? $item->get() : [];
    }

    /**
     * @return array{limit: int, windowSeconds: int}|null
     */
    private static function parseLimit(?string $header): ?array
    {
        if ($header === null || preg_match(self::LIMIT_PATTERN, $header, $matches) !== 1) {
            return null;
        }

        $windowSeconds = (int) $matches[2] * self::UNIT_SECONDS[$matches[3]];

        return $windowSeconds > 0 ? ['limit' => (int) $matches[1], 'windowSeconds' => $windowSeconds] : null;
    }

    private static function parseCount(?string $header): ?int
    {
        return $header !== null && preg_match(self::COUNT_PATTERN, $header) === 1 ? (int) $header : null;
    }

    private function expiryAfter(int $seconds): \DateTimeImmutable
    {
        return $this->clock->now()->modify("+{$seconds} seconds");
    }

    private function nowSeconds(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }

    private function familyKeyOf(string $endpoint): string
    {
        return self::FAMILY_KEY_PREFIX . sha1(EsiErrorBudget::familyOf($endpoint));
    }

    private function bucketKeyOf(string $group, string $scope): string
    {
        return self::BUCKET_KEY_PREFIX . sha1($group . '|' . $scope);
    }

    private function lowestKeyOf(string $group): string
    {
        return self::LOWEST_KEY_PREFIX . sha1($group);
    }
}
