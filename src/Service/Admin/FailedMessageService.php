<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class FailedMessageService
{
    public const FAILED_MESSAGES_PAGE_SIZE = 100;
    public const EXCEPTION_MESSAGE_MAX_LENGTH = 500;
    public const CLASS_ACTION_LIMIT = 500;

    public function __construct(
        private readonly TransportInterface&ListableReceiverInterface $failedTransport,
        private readonly ContainerInterface $transports,
        private readonly PhpSerializer $serializer,
    ) {
    }

    public function list(?string $messageClass): FailedMessageList
    {
        return new FailedMessageList(0, [], []);
    }

    public function retry(string $id): void
    {
    }

    public function remove(string $id): void
    {
    }

    public function retryClass(string $messageClass): FailedMessageClassOutcome
    {
        return new FailedMessageClassOutcome(0, 0);
    }

    public function removeClass(string $messageClass): FailedMessageClassOutcome
    {
        return new FailedMessageClassOutcome(0, 0);
    }
}
