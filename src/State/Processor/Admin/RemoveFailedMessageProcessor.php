<?php

declare(strict_types=1);

namespace App\State\Processor\Admin;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\Admin\FailedMessageResource;
use App\Security\AdminAccessGuard;
use App\Service\Admin\FailedMessageService;

/**
 * @extends AbstractFailedMessageProcessor<FailedMessageResource, void>
 */
class RemoveFailedMessageProcessor extends AbstractFailedMessageProcessor
{
    public function __construct(
        AdminAccessGuard $accessGuard,
        private readonly FailedMessageService $failedMessages,
    ) {
        parent::__construct($accessGuard);
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $this->accessGuard->requireAdmin();

        $id = (string) $uriVariables['id'];
        $this->mappingServiceFailures(fn () => $this->failedMessages->remove($id));
    }
}
