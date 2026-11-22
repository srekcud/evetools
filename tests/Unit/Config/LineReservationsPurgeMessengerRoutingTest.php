<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Message\PurgeExpiredLineReservations;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversNothing]
#[Group('issue-1301')]
final class LineReservationsPurgeMessengerRoutingTest extends TestCase
{
    private const string MESSENGER_CONFIG = __DIR__ . '/../../../config/packages/messenger.yaml';

    public function testTheExpiredLineReservationsPurgeIsRoutedToTheAsyncTransport(): void
    {
        $routing = Yaml::parseFile(self::MESSENGER_CONFIG)['framework']['messenger']['routing'];

        self::assertArrayHasKey(PurgeExpiredLineReservations::class, $routing);
        self::assertSame('async', $routing[PurgeExpiredLineReservations::class]);
    }
}
