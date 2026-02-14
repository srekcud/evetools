<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\State\Processor\Pve\ScanContractsProcessor;
use App\State\Processor\Pve\ScanLootContractsProcessor;
use App\State\Processor\Pve\ScanLootSalesProcessor;

#[ApiResource(
    shortName: 'PveScanResult',
    description: 'PVE scan results for contracts and transactions',
    operations: [
        new Post(
            uriTemplate: '/pve/scan-contracts',
            processor: ScanContractsProcessor::class,
            input: EmptyInput::class,
            output: ScanContractsResultResource::class,
            openapi: new Model\Operation(
                summary: 'Scan contracts for expenses',
                parameters: [
                    new Model\Parameter(name: 'days', in: 'query', schema: ['type' => 'integer']),
                ],
            ),
        ),
        new Post(
            uriTemplate: '/pve/scan-loot-sales',
            processor: ScanLootSalesProcessor::class,
            input: EmptyInput::class,
            output: ScanLootSalesResultResource::class,
            openapi: new Model\Operation(
                summary: 'Scan for loot sales',
                parameters: [
                    new Model\Parameter(name: 'days', in: 'query', schema: ['type' => 'integer']),
                ],
            ),
        ),
        new Post(
            uriTemplate: '/pve/scan-loot-contracts',
            processor: ScanLootContractsProcessor::class,
            input: EmptyInput::class,
            output: ScanLootContractsResultResource::class,
            openapi: new Model\Operation(
                summary: 'Scan for loot contracts',
                parameters: [
                    new Model\Parameter(name: 'days', in: 'query', schema: ['type' => 'integer']),
                ],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class ScanResultResource
{
}
