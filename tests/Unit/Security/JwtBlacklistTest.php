<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\JwtBlacklist;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

#[CoversClass(JwtBlacklist::class)]
#[Group('issue-53')]
final class JwtBlacklistTest extends TestCase
{
    private const string BLOCKED_JWT = 'eyJhbGciOiJSUzI1NiJ9.deleted-account.signature';
    private const string OTHER_JWT = 'eyJhbGciOiJSUzI1NiJ9.other-session.signature';
    private const int JWT_TOKEN_TTL = 3_600;

    private MockClock $clock;
    private ArrayAdapter $pool;
    private JwtBlacklist $jwtBlacklist;

    protected function setUp(): void
    {
        $this->clock = new MockClock();
        $this->pool = new ArrayAdapter(clock: $this->clock);
        $this->jwtBlacklist = new JwtBlacklist(jwtBlacklist: $this->pool, jwtTokenTtl: self::JWT_TOKEN_TTL);
    }

    public function testBlockedJwtIsBlocked(): void
    {
        $this->jwtBlacklist->block(self::BLOCKED_JWT);

        self::assertTrue($this->jwtBlacklist->isBlocked(self::BLOCKED_JWT));
    }

    public function testJwtThatWasNeverBlockedIsNotBlocked(): void
    {
        $this->jwtBlacklist->block(self::BLOCKED_JWT);

        self::assertFalse($this->jwtBlacklist->isBlocked(self::OTHER_JWT));
    }

    public function testBlockStoresTheKeyReadByTheJwtDecodedListener(): void
    {
        $this->jwtBlacklist->block(self::BLOCKED_JWT);

        self::assertTrue($this->pool->getItem('jwt_blacklist_' . hash('sha256', self::BLOCKED_JWT))->isHit());
    }

    public function testJwtBlockedByTheLogoutKeyIsBlocked(): void
    {
        $item = $this->pool->getItem('jwt_blacklist_' . hash('sha256', self::OTHER_JWT));
        $item->set(true);
        $this->pool->save($item);

        self::assertTrue($this->jwtBlacklist->isBlocked(self::OTHER_JWT));
    }

    public function testBlockLastsTheJwtTokenTtl(): void
    {
        $this->jwtBlacklist->block(self::BLOCKED_JWT);

        $this->clock->sleep(self::JWT_TOKEN_TTL - 1);
        self::assertTrue($this->jwtBlacklist->isBlocked(self::BLOCKED_JWT));

        $this->clock->sleep(2);
        self::assertFalse($this->jwtBlacklist->isBlocked(self::BLOCKED_JWT));
    }
}
