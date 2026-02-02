<?php

declare(strict_types=1);

namespace App\State\Provider\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Pve\ExpenseResource;

/**
 * @implements ProviderInterface<ExpenseResource>
 */
class ExpenseProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ExpenseResource
    {
        $resource = new ExpenseResource();
        $resource->id = (string) $uriVariables['id'];

        return $resource;
    }
}
