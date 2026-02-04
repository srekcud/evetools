<?php

declare(strict_types=1);

namespace App\ApiResource\Ledger;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\State\Provider\Ledger\MiningEntryCollectionProvider;
use App\State\Provider\Ledger\MiningEntryProvider;
use App\State\Processor\Ledger\UpdateMiningEntryUsageProcessor;

#[ApiResource(
    shortName: 'MiningEntry',
    description: 'Mining ledger entries',
    operations: [
        new GetCollection(
            uriTemplate: '/ledger/mining/entries',
            provider: MiningEntryCollectionProvider::class,
            openapiContext: [
                'summary' => 'List mining entries',
                'parameters' => [
                    ['name' => 'days', 'in' => 'query', 'type' => 'integer', 'description' => 'Number of days (default: 30)'],
                    ['name' => 'typeId', 'in' => 'query', 'type' => 'integer', 'description' => 'Filter by ore type'],
                    ['name' => 'usage', 'in' => 'query', 'type' => 'string', 'description' => 'Filter by usage (unknown, sold, corp_project, industry)'],
                    ['name' => 'structureId', 'in' => 'query', 'type' => 'integer', 'description' => 'Structure ID for price comparison'],
                    ['name' => 'reprocessYield', 'in' => 'query', 'type' => 'integer', 'description' => 'Reprocessing yield percentage (default: 78)'],
                ],
            ],
        ),
        new Get(
            uriTemplate: '/ledger/mining/entries/{id}',
            provider: MiningEntryProvider::class,
        ),
        new Patch(
            uriTemplate: '/ledger/mining/entries/{id}',
            provider: MiningEntryProvider::class,
            processor: UpdateMiningEntryUsageProcessor::class,
            openapiContext: [
                'summary' => 'Update mining entry usage',
                'requestBody' => [
                    'content' => [
                        'application/merge-patch+json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'usage' => ['type' => 'string', 'enum' => ['unknown', 'sold', 'corp_project', 'industry']],
                                    'linkedProjectId' => ['type' => 'string', 'nullable' => true],
                                    'linkedCorpProjectId' => ['type' => 'integer', 'nullable' => true],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class MiningEntryResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public int $characterId;

    public string $characterName;

    public string $date;

    public int $typeId;

    public string $typeName;

    public int $solarSystemId;

    public string $solarSystemName;

    public int $quantity;

    public ?float $unitPrice = null;

    public ?float $totalValue = null;

    // Compressed ore data
    public ?int $compressedTypeId = null;

    public ?string $compressedTypeName = null;

    public ?float $compressedUnitPrice = null;

    /** Price per raw unit based on compressed price (compressedPrice / 100) */
    public ?float $compressedEquivalentPrice = null;

    // Structure prices (if structure selected)
    public ?float $structureUnitPrice = null;

    public ?float $structureCompressedUnitPrice = null;

    // Reprocess value (value of minerals after reprocessing, per unit)
    public ?float $reprocessValue = null;

    // Structure reprocess value (reprocess value using structure mineral prices)
    public ?float $structureReprocessValue = null;

    public string $usage = 'unknown';

    public ?string $linkedProjectId = null;

    public ?int $linkedCorpProjectId = null;

    public string $syncedAt;
}
