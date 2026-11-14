<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Admin\InFlightMessageRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

final readonly class InFlightMessageListener
{
    public function __construct(private InFlightMessageRegistry $registry)
    {
    }

    #[AsEventListener(event: WorkerMessageReceivedEvent::class)]
    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->registry->start($event->getReceiverName(), $event->getEnvelope()->getMessage()::class);
    }

    #[AsEventListener(event: WorkerMessageHandledEvent::class)]
    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $this->registry->finish($event->getReceiverName());
    }

    #[AsEventListener(event: WorkerMessageFailedEvent::class)]
    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $this->registry->finish($event->getReceiverName());
    }
}
