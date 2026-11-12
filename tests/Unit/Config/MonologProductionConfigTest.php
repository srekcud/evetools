<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use Monolog\Level;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Yaml\Yaml;

#[CoversNothing]
#[Group('issue-675')]
final class MonologProductionConfigTest extends TestCase
{
    private const string MONOLOG_CONFIG = __DIR__ . '/../../../config/packages/monolog.yaml';
    private const string BUNDLES = __DIR__ . '/../../../config/bundles.php';
    private const string JSON_FORMATTER = 'monolog.formatter.json';
    private const string STDERR = 'php://stderr';
    private const array INFO_CHANNELS = ['app', 'messenger'];

    public function testTheMonologBundleIsRegisteredInEveryEnvironment(): void
    {
        $bundles = require self::BUNDLES;

        self::assertTrue($bundles[MonologBundle::class]['all'] ?? false);
    }

    public function testApplicationAndMessengerMessagesAreLoggedFromInfoLevel(): void
    {
        $handler = $this->handlerForChannels(self::INFO_CHANNELS);

        self::assertSame('stream', $handler['type']);
        self::assertSame(Level::Info, Level::fromName($handler['level']));
    }

    public function testOtherChannelsAreLoggedFromWarningLevelOnly(): void
    {
        $handler = $this->handlerExcludingChannels(self::INFO_CHANNELS);

        self::assertSame('stream', $handler['type']);
        self::assertSame(Level::Warning, Level::fromName($handler['level']));
    }

    public function testEveryProductionStreamHandlerWritesJsonToStderr(): void
    {
        $streamHandlers = array_filter(
            $this->productionHandlers(),
            static fn (array $handler): bool => 'stream' === $handler['type'],
        );

        self::assertNotEmpty($streamHandlers);
        foreach ($streamHandlers as $name => $handler) {
            self::assertSame(self::STDERR, $handler['path'], $name);
            self::assertSame(self::JSON_FORMATTER, $handler['formatter'], $name);
        }
    }

    public function testNoProductionHandlerBuffersMessagesUntilAnErrorOccurs(): void
    {
        $bufferingTypes = array_column($this->productionHandlers(), 'type');

        self::assertNotContains('fingers_crossed', $bufferingTypes);
    }

    private function handlerForChannels(array $channels): array
    {
        foreach ($this->productionHandlers() as $handler) {
            if (($handler['channels'] ?? null) === $channels) {
                return $handler;
            }
        }

        self::fail('No production handler is dedicated to the channels ' . implode(', ', $channels));
    }

    private function handlerExcludingChannels(array $channels): array
    {
        $excluded = array_map(static fn (string $channel): string => '!' . $channel, $channels);
        foreach ($this->productionHandlers() as $handler) {
            $handlerChannels = $handler['channels'] ?? [];
            if ([] === array_diff($excluded, $handlerChannels) && !in_array('deprecation', $handlerChannels, true)) {
                return $handler;
            }
        }

        self::fail('No production handler excludes the channels ' . implode(', ', $channels));
    }

    private function productionHandlers(): array
    {
        return Yaml::parseFile(self::MONOLOG_CONFIG)['when@prod']['monolog']['handlers'];
    }
}
