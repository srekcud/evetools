<?php

declare(strict_types=1);

namespace App\State\Provider\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Admin\FailedMessageResource;
use App\Security\AdminAccessGuard;

/**
 * @implements ProviderInterface<FailedMessageResource>
 */
class FailedMessageProvider implements ProviderInterface
{
    public function __construct(
        private readonly AdminAccessGuard $accessGuard,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): FailedMessageResource
    {
        $this->accessGuard->requireAdmin();

        $resource = new FailedMessageResource();
        $resource->id = (string) $uriVariables['id'];

        return $resource;
    }
}
