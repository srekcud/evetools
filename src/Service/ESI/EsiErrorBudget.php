<?php

declare(strict_types=1);

namespace App\Service\ESI;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

class EsiErrorBudget
{
    public const int CRITICAL_BELOW = 5;
    public const int LOW_BELOW = 20;

    private const string LAST_OBSERVATION_KEY = 'esi_error_budget_last';
    private const string LIMITED_KEY_PREFIX = 'esi_limited_';
    private const string HOUR_KEY_FORMAT = 'YmdH';
    private const int OBSERVATION_REFRESH_SECONDS = 30;
    private const int WINDOW_HOURS = 24;
    private const int COUNTERS_TTL_SECONDS = 25 * 3600;
    private const int OBSERVATION_TTL_SECONDS = 24 * 3600;
    private const array LIMITED_STATUS_CODES = [420, 429];

    private ?int $lastWrittenRemain = null;
    private ?int $lastWrittenAt = null;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
    }

    public static function levelOf(int $remain): string
    {
        return match (true) {
            $remain < self::CRITICAL_BELOW => 'critical',
            $remain < self::LOW_BELOW => 'low',
            default => 'ok',
        };
    }

    public function observe(int $remain, int $resetSeconds): void
    {
        $now = $this->clock->now()->getTimestamp();
        if ($remain === $this->lastWrittenRemain && $now - (int) $this->lastWrittenAt < self::OBSERVATION_REFRESH_SECONDS) {
            return;
        }

        try {
            $item = $this->cache->getItem(self::LAST_OBSERVATION_KEY);
            $item->set(['remain' => $remain, 'reset' => $resetSeconds, 'observedAt' => $now]);
            $item->expiresAfter(self::OBSERVATION_TTL_SECONDS);
            $this->cache->save($item);
        } catch (\Throwable $e) {
            $this->logger->warning('ESI error budget observation could not be stored: {message}', ['message' => $e->getMessage()]);

            return;
        }

        $this->lastWrittenRemain = $remain;
        $this->lastWrittenAt = $now;
    }

    public function recordLimitedResponse(int $statusCode, string $endpoint): void
    {
        if (!in_array($statusCode, self::LIMITED_STATUS_CODES, true)) {
            throw new \InvalidArgumentException("Status {$statusCode} is neither 420 nor 429");
        }

        try {
            $item = $this->cache->getItem($this->limitedKeyOf($this->clock->now()));
            /** @var array<string, array<int, int>> $families */
            $families = $item->isHit() ? $item->get() : [];
            $family = self::familyOf($endpoint);
            $families[$family][$statusCode] = ($families[$family][$statusCode] ?? 0) + 1;
            $item->set($families);
            $item->expiresAfter(self::COUNTERS_TTL_SECONDS);
            $this->cache->save($item);
        } catch (\Throwable $e) {
            $this->logger->warning('ESI limited response could not be counted: {message}', ['message' => $e->getMessage()]);
        }
    }

    /**
     * @return array{
     *     remain: ?int,
     *     resetSeconds: ?int,
     *     observedAt: ?string,
     *     windowEndsAt: ?string,
     *     level: string,
     *     limited420: int,
     *     limited429: int,
     *     byFamily: list<array{family: string, limited420: int, limited429: int}>,
     *     hourly: list<array{hour: string, limited420: int, limited429: int}>
     * }
     */
    public function snapshot(): array
    {
        $now = $this->clock->now();
        $hourly = [];
        $byFamily = [];
        $limited420 = 0;
        $limited429 = 0;

        for ($hoursAgo = self::WINDOW_HOURS - 1; $hoursAgo >= 0; --$hoursAgo) {
            $hourStart = $this->hourStartOf($now)->modify("-{$hoursAgo} hours");
            $hour420 = 0;
            $hour429 = 0;
            foreach ($this->familiesOf($hourStart) as $family => $counts) {
                $count420 = $counts[420] ?? 0;
                $count429 = $counts[429] ?? 0;
                $hour420 += $count420;
                $hour429 += $count429;
                $byFamily[$family] ??= ['family' => $family, 'limited420' => 0, 'limited429' => 0];
                $byFamily[$family]['limited420'] += $count420;
                $byFamily[$family]['limited429'] += $count429;
            }
            $limited420 += $hour420;
            $limited429 += $hour429;
            $hourly[] = ['hour' => $hourStart->format('c'), 'limited420' => $hour420, 'limited429' => $hour429];
        }

        usort($byFamily, static fn (array $a, array $b): int => ($b['limited420'] + $b['limited429']) <=> ($a['limited420'] + $a['limited429']));

        return [
            ...$this->observationOf($now),
            'limited420' => $limited420,
            'limited429' => $limited429,
            'byFamily' => $byFamily,
            'hourly' => $hourly,
        ];
    }

    /**
     * @return array{remain: ?int, resetSeconds: ?int, observedAt: ?string, windowEndsAt: ?string, level: string}
     */
    private function observationOf(\DateTimeImmutable $now): array
    {
        $unknown = ['remain' => null, 'resetSeconds' => null, 'observedAt' => null, 'windowEndsAt' => null, 'level' => 'unknown'];

        try {
            $item = $this->cache->getItem(self::LAST_OBSERVATION_KEY);
        } catch (\Throwable $e) {
            $this->logger->warning('ESI error budget observation could not be read: {message}', ['message' => $e->getMessage()]);

            return $unknown;
        }
        if (!$item->isHit()) {
            return $unknown;
        }

        /** @var array{remain: int, reset: int, observedAt: int} $observation */
        $observation = $item->get();
        $windowEnd = $observation['observedAt'] + $observation['reset'];

        return [
            'remain' => $observation['remain'],
            'resetSeconds' => $observation['reset'],
            'observedAt' => $this->isoOf($observation['observedAt']),
            'windowEndsAt' => $this->isoOf($windowEnd),
            'level' => $now->getTimestamp() > $windowEnd ? 'unknown' : self::levelOf($observation['remain']),
        ];
    }

    /**
     * @return array<string, array<int, int>>
     */
    private function familiesOf(\DateTimeImmutable $hourStart): array
    {
        try {
            $item = $this->cache->getItem($this->limitedKeyOf($hourStart));
        } catch (\Throwable $e) {
            $this->logger->warning('ESI limited response counts could not be read: {message}', ['message' => $e->getMessage()]);

            return [];
        }

        /** @var array<string, array<int, int>> */
        return $item->isHit() ? $item->get() : [];
    }

    public static function familyOf(string $endpoint): string
    {
        $path = explode('?', $endpoint, 2)[0];

        return (string) preg_replace('#/\d+(?=/|$)#', '/{id}', $path);
    }

    private function limitedKeyOf(\DateTimeImmutable $moment): string
    {
        return self::LIMITED_KEY_PREFIX . $this->hourStartOf($moment)->format(self::HOUR_KEY_FORMAT);
    }

    private function hourStartOf(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        $utc = $moment->setTimezone(new \DateTimeZone('UTC'));

        return $utc->setTime((int) $utc->format('G'), 0);
    }

    private function isoOf(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->format('c');
    }
}
