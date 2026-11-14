<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Service\Admin\MessengerQueueService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

#[CoversClass(MessengerQueueService::class)]
class MessengerQueueServiceTest extends TestCase
{
    private const ASYNC_MESSAGE_COUNT = 7;
    private const FAILED_MESSAGE_COUNT = 3;
    private const ON_DEMAND_MESSAGE_COUNT = 4;

    public function testAsyncDepthIsTheMessageCountOfTheAsyncTransport(): void
    {
        $service = new MessengerQueueService(
            $this->countableTransport(self::ASYNC_MESSAGE_COUNT),
            $this->countableTransport(11),
            new InMemoryTransport(),
        );

        $this->assertSame(7, $service->getDepth('async'));
    }

    public function testEmptyFailedTransportHasAKnownDepthOfZero(): void
    {
        $service = new MessengerQueueService(
            $this->countableTransport(self::ASYNC_MESSAGE_COUNT),
            $this->countableTransport(0),
            new InMemoryTransport(),
        );

        $this->assertSame(0, $service->getDepth('failed'));
    }

    public function testDepthIsUnknownWhenTheTransportCannotCountItsMessages(): void
    {
        $service = new MessengerQueueService(
            new InMemoryTransport(),
            $this->countableTransport(11),
            new InMemoryTransport(),
        );

        $this->assertNull($service->getDepth('async'));
    }

    public function testDepthIsUnknownWhenReadingTheMessageCountFails(): void
    {
        $service = new MessengerQueueService(
            $this->countableTransport(self::ASYNC_MESSAGE_COUNT),
            $this->failingCountTransport(),
            new InMemoryTransport(),
        );

        $this->assertNull($service->getDepth('failed'));
    }

    #[Group('issue-838')]
    public function testOnDemandDepthIsTheMessageCountOfTheOnDemandTransport(): void
    {
        $service = new MessengerQueueService(
            $this->countableTransport(self::ASYNC_MESSAGE_COUNT),
            $this->countableTransport(11),
            $this->countableTransport(self::ON_DEMAND_MESSAGE_COUNT),
        );

        $this->assertSame(4, $service->getDepth('on_demand'));
    }

    #[Group('issue-838')]
    public function testOnDemandDepthIsUnknownWhenTheTransportCannotCountItsMessages(): void
    {
        $service = new MessengerQueueService(
            $this->countableTransport(self::ASYNC_MESSAGE_COUNT),
            $this->countableTransport(11),
            new InMemoryTransport(),
        );

        $this->assertNull($service->getDepth('on_demand'));
    }

    public function testDepthOfAnUnknownTransportNameIsRejected(): void
    {
        $service = new MessengerQueueService(
            $this->countableTransport(self::ASYNC_MESSAGE_COUNT),
            $this->countableTransport(0),
            new InMemoryTransport(),
        );

        $this->expectException(\InvalidArgumentException::class);

        $service->getDepth('other');
    }

    public function testPurgingFailedMessagesReturnsTheNumberOfMessagesRemoved(): void
    {
        $failedTransport = new InMemoryTransport();
        foreach (range(1, self::FAILED_MESSAGE_COUNT) as $unused) {
            $failedTransport->send(new Envelope(new \stdClass()));
        }
        $service = new MessengerQueueService(new InMemoryTransport(), $failedTransport, new InMemoryTransport());

        $purged = $service->purgeFailed();

        $this->assertSame(3, $purged);
        $this->assertSame([], iterator_to_array($failedTransport->get(), false));
        $this->assertCount(3, $failedTransport->getAcknowledged());
        $this->assertCount(3, $failedTransport->getSent());
    }

    public function testPurgingAnEmptyFailedTransportRemovesNothing(): void
    {
        $service = new MessengerQueueService(new InMemoryTransport(), new InMemoryTransport(), new InMemoryTransport());

        $this->assertSame(0, $service->purgeFailed());
    }

    public function testPurgingDoesNotTouchTheAsyncTransport(): void
    {
        $asyncTransport = new InMemoryTransport();
        $asyncTransport->send(new Envelope(new \stdClass()));
        $failedTransport = new InMemoryTransport();
        $failedTransport->send(new Envelope(new \stdClass()));
        $service = new MessengerQueueService($asyncTransport, $failedTransport, new InMemoryTransport());

        $service->purgeFailed();

        $this->assertCount(1, iterator_to_array($asyncTransport->get(), false));
    }

    public function testPurgingPropagatesATransportFailure(): void
    {
        $failedTransport = $this->createStub(TransportInterface::class);
        $failedTransport->method('get')->willThrowException(new \RuntimeException('AMQP connection lost'));
        $service = new MessengerQueueService(new InMemoryTransport(), $failedTransport, new InMemoryTransport());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AMQP connection lost');

        $service->purgeFailed();
    }

    private function countableTransport(int $messageCount): TransportInterface
    {
        return new class($messageCount) extends InMemoryTransport implements MessageCountAwareInterface {
            public function __construct(private readonly int $messageCount)
            {
                parent::__construct();
            }

            public function getMessageCount(): int
            {
                return $this->messageCount;
            }
        };
    }

    private function failingCountTransport(): TransportInterface
    {
        return new class extends InMemoryTransport implements MessageCountAwareInterface {
            public function getMessageCount(): int
            {
                throw new \RuntimeException('AMQP connection lost');
            }
        };
    }
}
