<?php

declare(strict_types=1);

namespace App\State\Processor\Admin;

use App\ApiResource\Admin\FailedMessageResource;

/**
 * @extends AbstractFailedMessageProcessor<FailedMessageResource, null>
 */
class RemoveFailedMessageProcessor extends AbstractFailedMessageProcessor
{
    protected function apply(mixed $data, array $uriVariables): null
    {
        $this->failedMessages->remove((string) $uriVariables['id']);

        return null;
    }
}
