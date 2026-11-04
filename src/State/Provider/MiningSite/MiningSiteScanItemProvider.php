<?php

declare(strict_types=1);

namespace App\State\Provider\MiningSite;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningSite\MiningSiteScanResource;

/**
 * @implements ProviderInterface<MiningSiteScanResource>
 */
final readonly class MiningSiteScanItemProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?MiningSiteScanResource
    {
        return null;
    }
}
