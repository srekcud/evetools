<?php

declare(strict_types=1);

namespace App\State\Processor\Admin;

use App\ApiResource\Admin\FailedMessageClassActionResource;
use App\ApiResource\Input\Admin\FailedMessageClassInput;

/**
 * @extends AbstractFailedMessageProcessor<FailedMessageClassInput, FailedMessageClassActionResource>
 */
class RetryFailedMessageClassProcessor extends AbstractFailedMessageProcessor
{
    protected function apply(mixed $data, array $uriVariables): FailedMessageClassActionResource
    {
        \assert($data instanceof FailedMessageClassInput);

        $outcome = $this->failedMessages->retryClass($data->messageClass);

        $resource = new FailedMessageClassActionResource();
        $resource->processed = $outcome->processed;
        $resource->remaining = $outcome->remaining;

        return $resource;
    }
}
