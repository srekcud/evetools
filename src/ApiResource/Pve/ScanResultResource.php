<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
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
            openapiContext: [
                'summary' => 'Scan contracts for expenses',
                'parameters' => [
                    ['name' => 'days', 'in' => 'query', 'type' => 'integer', 'description' => 'Number of days to scan (default: 30)'],
                ],
            ],
        ),
        new Post(
            uriTemplate: '/pve/scan-loot-sales',
            processor: ScanLootSalesProcessor::class,
            input: EmptyInput::class,
            output: ScanLootSalesResultResource::class,
            openapiContext: [
                'summary' => 'Scan for loot sales',
                'parameters' => [
                    ['name' => 'days', 'in' => 'query', 'type' => 'integer', 'description' => 'Number of days to scan (default: 30)'],
                ],
            ],
        ),
        new Post(
            uriTemplate: '/pve/scan-loot-contracts',
            processor: ScanLootContractsProcessor::class,
            input: EmptyInput::class,
            output: ScanLootContractsResultResource::class,
            openapiContext: [
                'summary' => 'Scan for loot contracts',
                'parameters' => [
                    ['name' => 'days', 'in' => 'query', 'type' => 'integer', 'description' => 'Number of days to scan (default: 30)'],
                ],
            ],
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class ScanResultResource
{
}
