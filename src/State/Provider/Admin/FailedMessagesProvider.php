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
use App\Service\Admin\FailedMessageService;
use App\Service\Admin\FailedMessagesUnavailable;
use App\Service\Admin\MessageClassCount;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * @implements ProviderInterface<FailedMessagesResource>
 */
class FailedMessagesProvider implements ProviderInterface
{
    public function __construct(
        private readonly AdminAccessGuard $accessGuard,
        private readonly FailedMessageService $failedMessages,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): FailedMessagesResource
    {
        $this->accessGuard->requireAdmin();

        $messageClass = $context['request']?->query->getString('messageClass') ?? '';

        try {
            $list = $this->failedMessages->list('' === $messageClass ? null : $messageClass);
        } catch (FailedMessagesUnavailable $unavailable) {
            throw new ServiceUnavailableHttpException(null, $unavailable->getMessage(), $unavailable);
        }

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
