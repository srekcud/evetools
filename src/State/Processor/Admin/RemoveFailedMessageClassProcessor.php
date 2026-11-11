<?php

declare(strict_types=1);

namespace App\State\Processor\Admin;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\Admin\FailedMessageClassActionResource;
use App\ApiResource\Input\Admin\FailedMessageClassInput;
use App\Security\AdminAccessGuard;
use App\Service\Admin\FailedMessageService;

/**
 * @extends AbstractFailedMessageProcessor<FailedMessageClassInput, FailedMessageClassActionResource>
 */
class RemoveFailedMessageClassProcessor extends AbstractFailedMessageProcessor
{
    public function __construct(
        AdminAccessGuard $accessGuard,
        private readonly FailedMessageService $failedMessages,
    ) {
        parent::__construct($accessGuard);
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): FailedMessageClassActionResource
    {
        $this->accessGuard->requireAdmin();
        \assert($data instanceof FailedMessageClassInput);

        $outcome = $this->mappingServiceFailures(fn () => $this->failedMessages->removeClass($data->messageClass));

        $resource = new FailedMessageClassActionResource();
        $resource->processed = $outcome->processed;
        $resource->remaining = $outcome->remaining;

        return $resource;
    }
}
