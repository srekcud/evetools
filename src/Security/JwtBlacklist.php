<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Cache\CacheItemPoolInterface;

final class JwtBlacklist
{
    public function __construct(
        private readonly CacheItemPoolInterface $jwtBlacklist,
        private readonly int $jwtTokenTtl,
    ) {
    }

    public function block(string $jwt): void
    {
    }

    public function isBlocked(string $jwt): bool
    {
        return false;
    }
}
