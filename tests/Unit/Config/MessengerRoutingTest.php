<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Message\SyncCharacterIndustryJobs;
use App\Message\SyncUserIndustryJobs;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversNothing]
#[Group('issue-215')]
final class MessengerRoutingTest extends TestCase
{
    private const string MESSENGER_CONFIG = __DIR__ . '/../../../config/packages/messenger.yaml';

    public function testUserIndustryJobsSyncIsRoutedToTheAsyncTransport(): void
    {
        self::assertSame('async', $this->messengerRouting()[SyncUserIndustryJobs::class] ?? null);
    }

    public function testCharacterIndustryJobsSyncIsRoutedToTheAsyncTransport(): void
    {
        self::assertSame('async', $this->messengerRouting()[SyncCharacterIndustryJobs::class] ?? null);
    }

    /** @return array<string, string> */
    private function messengerRouting(): array
    {
        return Yaml::parseFile(self::MESSENGER_CONFIG)['framework']['messenger']['routing'];
    }
}
