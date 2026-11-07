<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Assets\SyncStatusResource;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<mixed, SyncStatusResource>
 */
class RefreshBlueprintsProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SyncStatusResource
    {
        $result = new SyncStatusResource();
        $result->status = 'idle';
        $result->message = '';

        return $result;
    }
}
