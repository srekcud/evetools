<?php

declare(strict_types=1);

namespace App\State\Provider\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Mining\CorporationMiningExtractionsResource;

/**
 * @implements ProviderInterface<CorporationMiningExtractionsResource>
 */
final readonly class CorporationMiningExtractionsProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CorporationMiningExtractionsResource
    {
        return new CorporationMiningExtractionsResource();
    }
}
