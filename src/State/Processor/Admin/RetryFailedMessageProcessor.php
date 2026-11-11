<?php

declare(strict_types=1);

namespace App\State\Processor\Admin;

use App\ApiResource\Admin\FailedMessageResource;

/**
 * @extends AbstractFailedMessageProcessor<mixed, FailedMessageResource>
 */
class RetryFailedMessageProcessor extends AbstractFailedMessageProcessor
{
    protected function apply(mixed $data, array $uriVariables): FailedMessageResource
    {
        $id = (string) $uriVariables['id'];
        $this->failedMessages->retry($id);

        $resource = new FailedMessageResource();
        $resource->id = $id;

        return $resource;
    }
}
