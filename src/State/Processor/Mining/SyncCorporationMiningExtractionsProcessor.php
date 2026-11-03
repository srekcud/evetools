<?php

declare(strict_types=1);

namespace App\State\Processor\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mining\CorporationMiningExtractionsResource;

/**
 * @implements ProcessorInterface<mixed, CorporationMiningExtractionsResource>
 */
final readonly class SyncCorporationMiningExtractionsProcessor implements ProcessorInterface
{
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CorporationMiningExtractionsResource
    {
        return new CorporationMiningExtractionsResource();
    }
}
