<?php

declare(strict_types=1);

namespace App\State\Provider\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Admin\FailedMessageClassCountDto;
use App\ApiResource\Admin\FailedMessageItemDto;
use App\ApiResource\Admin\FailedMessagesResource;
use App\Security\AdminAccessGuard;
use App\Service\Admin\FailedMessage;
use App\Service\Admin\FailedMessageHttpExceptionMapper;
use App\Service\Admin\FailedMessageService;
use App\Service\Admin\MessageClassCount;

/**
 * @implements ProviderInterface<FailedMessagesResource>
 */
class FailedMessagesProvider implements ProviderInterface
{
    public function __construct(
        private readonly AdminAccessGuard $accessGuard,
        private readonly FailedMessageService $failedMessages,
        private readonly FailedMessageHttpExceptionMapper $exceptionMapper,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): FailedMessagesResource
    {
        $this->accessGuard->requireAdmin();

        $messageClass = $context['request']?->query->getString('messageClass') ?? '';

        $list = $this->exceptionMapper->mapping(fn () => $this->failedMessages->list('' === $messageClass ? null : $messageClass));

        $resource = new FailedMessagesResource();
        $resource->total = $list->total;
        $resource->classes = array_map(
            static fn (MessageClassCount $class): FailedMessageClassCountDto => new FailedMessageClassCountDto($class->messageClass, $class->count),
            $list->classes,
        );
        $resource->messages = array_map(
            static fn (FailedMessage $message): FailedMessageItemDto => new FailedMessageItemDto(
                $message->id,
                $message->messageClass,
                $message->exceptionClass,
                $message->exceptionMessage,
                $message->failedAt?->format(\DATE_ATOM),
                $message->retryCount,
            ),
            $list->messages,
        );

        return $resource;
    }
}
