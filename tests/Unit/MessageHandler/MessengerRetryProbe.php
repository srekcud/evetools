<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Asks Symfony Messenger's own retry listener whether a handler failure would be retried,
 * with the retry strategy of the `async` transport (config/packages/messenger.yaml).
 */
final class MessengerRetryProbe
{
    private const string RECEIVER = 'async';
    private const int MAX_RETRIES = 3;
    private const int DELAY_MS = 1000;
    private const float MULTIPLIER = 2;

    public static function wouldRetry(object $message, \Throwable $handlerFailure): bool
    {
        $envelope = new Envelope($message);
        $listener = new SendFailedMessageForRetryListener(
            new ServiceLocator([self::RECEIVER => static fn () => new InMemoryTransport()]),
            new ServiceLocator([self::RECEIVER => static fn () => new MultiplierRetryStrategy(self::MAX_RETRIES, self::DELAY_MS, self::MULTIPLIER, 0)]),
        );
        // The worker sees handler exceptions wrapped by HandleMessageMiddleware
        $event = new WorkerMessageFailedEvent($envelope, self::RECEIVER, new HandlerFailedException($envelope, [$handlerFailure]));

        $listener->onMessageFailed($event);

        return $event->willRetry();
    }
}
