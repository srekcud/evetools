<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Message\SyncCharacterAssets;
use App\Message\SyncCharacterIndustryJobs;
use App\Message\SyncCorporationAssets;
use App\Message\SyncUserIndustryJobs;
use App\Message\TriggerAssetsSync;
use App\Message\TriggerCorporationAssetsSync;
use App\Scheduler\SyncScheduler;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;
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

    /** @return iterable<string, array{class-string}> */
    public static function onDemandAssetMessages(): iterable
    {
        yield 'character assets sync' => [SyncCharacterAssets::class];
        yield 'corporation assets sync' => [SyncCorporationAssets::class];
        yield 'character assets trigger' => [TriggerAssetsSync::class];
        yield 'corporation assets trigger' => [TriggerCorporationAssetsSync::class];
    }

    /** @param class-string $messageClass */
    #[Group('issue-838')]
    #[DataProvider('onDemandAssetMessages')]
    public function testAssetSyncMessageIsRoutedToTheOnDemandTransport(string $messageClass): void
    {
        self::assertSame('on_demand', $this->messengerRouting()[$messageClass] ?? null);
    }

    #[Group('issue-838')]
    public function testOnDemandTransportReadsTheSameDsnAndRetriesLikeTheAsyncTransport(): void
    {
        $transports = $this->messengerTransports();

        self::assertArrayHasKey('on_demand', $transports);
        self::assertSame($transports['async']['dsn'], $transports['on_demand']['dsn']);
        self::assertSame($transports['async']['retry_strategy'], $transports['on_demand']['retry_strategy']);
    }

    #[Group('issue-838')]
    public function testOnDemandTransportHasItsOwnExchangeAndASingleQueue(): void
    {
        $transports = $this->messengerTransports();

        self::assertArrayHasKey('on_demand', $transports);
        self::assertSame('on_demand', $transports['on_demand']['options']['exchange']['name'] ?? null);
        self::assertSame(['on_demand'], array_keys($transports['on_demand']['options']['queues'] ?? []));
        self::assertNotSame($transports['async']['options']['exchange']['name'] ?? null, $transports['on_demand']['options']['exchange']['name'] ?? null);
        self::assertArrayNotHasKey('queues', $transports['async']['options'] ?? []);
    }

    #[Group('issue-838')]
    public function testOnDemandTransportIsInMemoryInTheTestEnvironment(): void
    {
        $config = Yaml::parseFile(self::MESSENGER_CONFIG);

        self::assertSame('in-memory://', $config['when@test']['framework']['messenger']['transports']['on_demand'] ?? null);
    }

    #[Group('issue-838')]
    public function testNoScheduledMessageIsRoutedToTheOnDemandTransport(): void
    {
        $schedule = (new SyncScheduler(new ArrayAdapter(), new LockFactory(new InMemoryStore())))->getSchedule();
        $referenceRun = new \DateTimeImmutable('2026-11-12 12:00:00');
        $routing = $this->messengerRouting();
        $scheduledMessageClasses = [];

        foreach ($schedule->getRecurringMessages() as $recurringMessage) {
            $context = new MessageContext('default', $recurringMessage->getId(), $recurringMessage->getTrigger(), $referenceRun);
            foreach ($recurringMessage->getMessages($context) as $message) {
                self::assertInstanceOf(RedispatchMessage::class, $message);
                $scheduledMessageClasses[] = $message->envelope::class;
            }
        }

        self::assertNotSame([], $scheduledMessageClasses);
        $routedToOnDemand = array_values(array_filter(
            $scheduledMessageClasses,
            static fn (string $messageClass): bool => ($routing[$messageClass] ?? null) === 'on_demand',
        ));
        self::assertSame([], $routedToOnDemand);
    }

    /** @return array<string, string> */
    private function messengerRouting(): array
    {
        return Yaml::parseFile(self::MESSENGER_CONFIG)['framework']['messenger']['routing'];
    }

    /** @return array<string, array<string, mixed>> */
    private function messengerTransports(): array
    {
        return Yaml::parseFile(self::MESSENGER_CONFIG)['framework']['messenger']['transports'];
    }
}
