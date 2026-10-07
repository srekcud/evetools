<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Exception\EveAuthRequiredException;
use App\Message\SyncCorporationAssets;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Generic policy: any handler letting EveAuthRequiredException escape must not be retried by Messenger,
 * a revoked ESI grant will not come back by itself (issue #13).
 */
#[CoversNothing]
final class EveAuthRequiredRetryPolicyTest extends TestCase
{
    private const int CORPORATION_ID = 98_000_001;
    private const string TRIGGER_CHARACTER_ID = '0199a1f0-0000-7000-8000-000000000001';

    public function testHandlerFailureCausedByEveAuthRequiredIsNotRetried(): void
    {
        $failure = new EveAuthRequiredException('2112000001');

        self::assertFalse(MessengerRetryProbe::wouldRetry(new SyncCorporationAssets(self::CORPORATION_ID, self::TRIGGER_CHARACTER_ID), $failure));
    }

    public function testOrdinaryHandlerFailureIsStillRetried(): void
    {
        $failure = new \RuntimeException('ESI server error');

        self::assertTrue(MessengerRetryProbe::wouldRetry(new SyncCorporationAssets(self::CORPORATION_ID, self::TRIGGER_CHARACTER_ID), $failure));
    }
}
