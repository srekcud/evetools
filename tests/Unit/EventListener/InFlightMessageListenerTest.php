<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\InFlightMessageListener;
use App\Service\Admin\InFlightMessageRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

#[CoversClass(InFlightMessageListener::class)]
#[Group('issue-838')]
final class InFlightMessageListenerTest extends TestCase
{
    private const START_TIME = 1_800_000_000;

    private MockClock $clock;
    private InFlightMessageRegistry $registry;
    private InFlightMessageListener $listener;

    protected function setUp(): void
    {
        $this->clock = new MockClock('@' . self::START_TIME);
        $this->registry = new InFlightMessageRegistry(new ArrayAdapter(clock: $this->clock), $this->clock);
        $this->listener = new InFlightMessageListener($this->registry);
    }

    public function testAReceivedMessageIsTrackedAsRunningOnItsReceiver(): void
    {
        $this->listener->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope(), 'on_demand'));
        $this->clock->modify('@' . (self::START_TIME + 3700));

        self::assertSame(3700, $this->registry->oldestRunningSeconds());
    }

    public function testAHandledMessageIsNoLongerRunning(): void
    {
        $this->listener->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope(), 'on_demand'));

        $this->listener->onMessageHandled(new WorkerMessageHandledEvent($this->envelope(), 'on_demand'));

        self::assertNull($this->registry->oldestRunningSeconds());
    }

    public function testAFailedMessageIsNoLongerRunningWhetherOrNotItWillBeRetried(): void
    {
        $this->listener->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope(), 'on_demand'));
        $this->listener->onMessageFailed($this->failedEvent('on_demand', true));
        self::assertNull($this->registry->oldestRunningSeconds());

        $this->listener->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope(), 'async'));
        $this->listener->onMessageFailed($this->failedEvent('async', false));
        self::assertNull($this->registry->oldestRunningSeconds());
    }

    public function testOnlyTheReceiverOfTheHandledMessageIsCleared(): void
    {
        $this->listener->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope(), 'on_demand'));
        $this->clock->modify('@' . (self::START_TIME + 400));
        $this->listener->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope(), 'async'));
        $this->clock->modify('@' . (self::START_TIME + 450));

        $this->listener->onMessageHandled(new WorkerMessageHandledEvent($this->envelope(), 'on_demand'));

        self::assertSame(50, $this->registry->oldestRunningSeconds());
    }

    public function testTheListenerSubscribesToTheReceivedHandledAndFailedWorkerEvents(): void
    {
        $subscribedEventsByMethod = [];
        foreach ((new \ReflectionClass(InFlightMessageListener::class))->getMethods() as $method) {
            foreach ($method->getAttributes(AsEventListener::class) as $attribute) {
                $subscribedEventsByMethod[$method->getName()] = $attribute->getArguments()['event'] ?? null;
            }
        }

        self::assertSame(
            [
                'onMessageReceived' => WorkerMessageReceivedEvent::class,
                'onMessageHandled' => WorkerMessageHandledEvent::class,
                'onMessageFailed' => WorkerMessageFailedEvent::class,
            ],
            $subscribedEventsByMethod,
        );
    }

    private function envelope(): Envelope
    {
        return new Envelope(new \stdClass());
    }

    private function failedEvent(string $receiverName, bool $willRetry): WorkerMessageFailedEvent
    {
        $event = new WorkerMessageFailedEvent($this->envelope(), $receiverName, new \RuntimeException('boom'));
        if ($willRetry) {
            $event->setForRetry();
        }

        return $event;
    }
}
