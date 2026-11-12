<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\AdminFailedMessageListener;
use App\Tests\Unit\Service\Mercure\RecordsAdminHealthUpdates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

#[CoversClass(AdminFailedMessageListener::class)]
#[Group('issue-813')]
final class AdminFailedMessageListenerTest extends TestCase
{
    use RecordsAdminHealthUpdates;

    public function testAFailureThatWillBeRetriedPublishesNothing(): void
    {
        $event = $this->failedEvent();
        $event->setForRetry();

        (new AdminFailedMessageListener($this->adminHealthPublisher()))($event);

        self::assertSame([], $this->publishedScopes());
    }

    public function testAFailureThatWillNotBeRetriedPublishesTheFailedMessagesScope(): void
    {
        (new AdminFailedMessageListener($this->adminHealthPublisher()))($this->failedEvent());

        self::assertSame(['failed-messages'], $this->publishedScopes());
    }

    public function testTheListenerRunsAfterTheMessageIsSentToTheFailureTransport(): void
    {
        $attributes = (new \ReflectionClass(AdminFailedMessageListener::class))->getAttributes(AsEventListener::class);

        self::assertCount(1, $attributes);
        self::assertSame(WorkerMessageFailedEvent::class, $attributes[0]->getArguments()['event']);
        self::assertSame(-200, $attributes[0]->getArguments()['priority']);
    }

    private function failedEvent(): WorkerMessageFailedEvent
    {
        return new WorkerMessageFailedEvent(new Envelope(new \stdClass()), 'async', new \RuntimeException('boom'));
    }
}
