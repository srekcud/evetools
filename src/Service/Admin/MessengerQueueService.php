<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

class MessengerQueueService
{
    private const ASYNC_TRANSPORT = 'async';
    private const FAILED_TRANSPORT = 'failed';

    public function __construct(
        #[Autowire(service: 'messenger.transport.async')]
        private readonly TransportInterface $asyncTransport,
        #[Autowire(service: 'messenger.transport.failed')]
        private readonly TransportInterface $failedTransport,
    ) {
    }

    public function getDepth(string $transportName): ?int
    {
        $transport = match ($transportName) {
            self::ASYNC_TRANSPORT => $this->asyncTransport,
            self::FAILED_TRANSPORT => $this->failedTransport,
            default => throw new \InvalidArgumentException(sprintf('Unknown transport "%s"', $transportName)),
        };

        if (!$transport instanceof MessageCountAwareInterface) {
            return null;
        }

        try {
            return $transport->getMessageCount();
        } catch (\Throwable) {
            return null;
        }
    }

    public function purgeFailed(): int
    {
        $purged = 0;

        do {
            $acknowledgedInPass = 0;
            foreach ($this->failedTransport->get() as $envelope) {
                $this->failedTransport->ack($envelope);
                ++$acknowledgedInPass;
            }
            $purged += $acknowledgedInPass;
        } while ($acknowledgedInPass > 0);

        return $purged;
    }
}
