<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversNothing]
#[Group('issue-840')]
final class RedisEvictionPolicyTest extends TestCase
{
    private const string PROJECT_DIR = __DIR__ . '/../../..';
    private const string CACHE_CONFIG = self::PROJECT_DIR . '/config/packages/cache.yaml';
    private const string BASE_COMPOSE = self::PROJECT_DIR . '/docker-compose.yaml';
    private const string PRODUCTION_COMPOSE = self::PROJECT_DIR . '/docker-compose.prod.yml';
    private const string TAG_AWARE_ADAPTER = 'cache.adapter.redis_tag_aware';
    private const string REDIS_ADAPTER = 'cache.adapter.redis';
    private const string TTL_COMPATIBLE_POLICY = 'volatile-lru';

    /**
     * @return iterable<string, array{string}>
     */
    public static function composeFiles(): iterable
    {
        yield 'base compose' => [self::BASE_COMPOSE];
        yield 'production compose' => [self::PRODUCTION_COMPOSE];
    }

    #[DataProvider('composeFiles')]
    public function testRedisEvictsOnlyKeysThatCarryATtl(string $composeFile): void
    {
        self::assertSame(self::TTL_COMPATIBLE_POLICY, $this->evictionPolicyOf($composeFile));
    }

    public function testRedisNeverUsesAnEvictionPolicyThatTheTagAwareAdapterRefuses(): void
    {
        foreach ([self::BASE_COMPOSE, self::PRODUCTION_COMPOSE] as $composeFile) {
            self::assertStringStartsNotWith('allkeys-', (string) $this->evictionPolicyOf($composeFile));
        }
    }

    public function testEveryRedisPoolWritesATtl(): void
    {
        $withoutLifetime = [];
        foreach ($this->redisPools() as $name => $pool) {
            if (!isset($pool['default_lifetime']) || $pool['default_lifetime'] <= 0) {
                $withoutLifetime[] = $name;
            }
        }

        self::assertSame([], $withoutLifetime);
    }

    public function testTheEsiCacheStaysTagAwareWithALifetime(): void
    {
        $pool = $this->pools()['esi.cache'];

        self::assertSame(self::TAG_AWARE_ADAPTER, $pool['adapter']);
        self::assertTrue($pool['tags']);
        self::assertGreaterThan(0, $pool['default_lifetime']);
    }

    public function testTheApplicationPoolStaysWithoutLifetimeForTheSchedulerCheckpoint(): void
    {
        $framework = $this->cacheConfig()['framework']['cache'];

        self::assertSame(self::REDIS_ADAPTER, $framework['app']);
        self::assertArrayNotHasKey('cache.app', $framework['pools']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function redisPools(): array
    {
        return array_filter(
            $this->pools(),
            static fn (array $pool): bool => in_array($pool['adapter'], [self::REDIS_ADAPTER, self::TAG_AWARE_ADAPTER], true),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function pools(): array
    {
        return $this->cacheConfig()['framework']['cache']['pools'];
    }

    /**
     * @return array<string, mixed>
     */
    private function cacheConfig(): array
    {
        return Yaml::parseFile(self::CACHE_CONFIG);
    }

    private function evictionPolicyOf(string $composeFile): ?string
    {
        $compose = Yaml::parseFile($composeFile);
        $command = $compose['services']['redis']['command'] ?? '';
        $command = is_array($command) ? implode(' ', $command) : $command;

        return preg_match('/--maxmemory-policy\s+(\S+)/', $command, $matches) === 1 ? $matches[1] : null;
    }
}
