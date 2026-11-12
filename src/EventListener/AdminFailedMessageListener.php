<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Enum\AdminHealthScope;
use App\Service\Mercure\MercurePublisherService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

#[AsEventListener(event: WorkerMessageFailedEvent::class, priority: -200)]
final readonly class AdminFailedMessageListener
{
    public function __construct(private MercurePublisherService $publisher)
    {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $this->publisher->publishAdminHealth(AdminHealthScope::FailedMessages);
    }
}
