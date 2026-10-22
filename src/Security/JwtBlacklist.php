<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;

final class JwtBlacklist
{
    private const string KEY_PREFIX = 'jwt_blacklist_';
    private const string BEARER_PREFIX = 'Bearer ';

    public function __construct(
        private readonly CacheItemPoolInterface $jwtBlacklist,
        private readonly int $jwtTokenTtl,
    ) {
    }

    public function block(string $jwt): void
    {
        $cacheItem = $this->jwtBlacklist->getItem($this->cacheKey($jwt));
        $cacheItem->set(true);
        $cacheItem->expiresAfter($this->jwtTokenTtl);
        $this->jwtBlacklist->save($cacheItem);
    }

    public function blockBearerOf(Request $request): void
    {
        $authorization = $request->headers->get('Authorization');

        if ($authorization === null || !str_starts_with($authorization, self::BEARER_PREFIX)) {
            return;
        }

        $this->block(substr($authorization, \strlen(self::BEARER_PREFIX)));
    }

    public function isBlocked(string $jwt): bool
    {
        return $this->jwtBlacklist->getItem($this->cacheKey($jwt))->isHit();
    }

    private function cacheKey(string $jwt): string
    {
        return self::KEY_PREFIX . hash('sha256', $jwt);
    }
}
