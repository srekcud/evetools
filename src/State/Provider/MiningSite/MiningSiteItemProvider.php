<?php

declare(strict_types=1);

namespace App\State\Provider\MiningSite;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningSite\MiningSiteResource;

/**
 * @implements ProviderInterface<MiningSiteResource>
 */
final readonly class MiningSiteItemProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?MiningSiteResource
    {
        return null;
    }
}
