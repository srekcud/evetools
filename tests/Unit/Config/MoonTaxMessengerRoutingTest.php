<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Message\SyncMoonTax;
use App\Message\TriggerMoonTaxSync;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversNothing]
#[Group('issue-273')]
final class MoonTaxMessengerRoutingTest extends TestCase
{
    private const string MESSENGER_CONFIG = __DIR__ . '/../../../config/packages/messenger.yaml';

    public function testTheScheduledTriggerIsRoutedToTheAsyncTransport(): void
    {
        self::assertSame('async', $this->messengerRouting()[TriggerMoonTaxSync::class] ?? null);
    }

    public function testTheCorporationSyncIsRoutedToTheAsyncTransport(): void
    {
        self::assertSame('async', $this->messengerRouting()[SyncMoonTax::class] ?? null);
    }

    private function messengerRouting(): array
    {
        return Yaml::parseFile(self::MESSENGER_CONFIG)['framework']['messenger']['routing'];
    }
}
