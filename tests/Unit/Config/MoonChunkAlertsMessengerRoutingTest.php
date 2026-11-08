<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Message\CheckMoonChunkAlerts;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversNothing]
#[Group('issue-274')]
final class MoonChunkAlertsMessengerRoutingTest extends TestCase
{
    private const string MESSENGER_CONFIG = __DIR__ . '/../../../config/packages/messenger.yaml';

    public function testTheScheduledMoonChunkAlertsCheckIsRoutedToTheAsyncTransport(): void
    {
        $routing = Yaml::parseFile(self::MESSENGER_CONFIG)['framework']['messenger']['routing'];

        self::assertSame('async', $routing[CheckMoonChunkAlerts::class] ?? null);
    }
}
