<?php

declare(strict_types=1);

namespace App\ApiResource\DataFreshness;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\DataFreshness\DataFreshnessProvider;

#[ApiResource(
    shortName: 'DataFreshness',
    description: 'Last successful sync of the global data sources',
    operations: [
        new GetCollection(
            uriTemplate: '/data-freshness',
            provider: DataFreshnessProvider::class,
            openapi: new Model\Operation(summary: 'List the data freshness of the global sync sources', tags: ['Sync']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['skip_null_values' => false],
    paginationEnabled: false,
)]
class DataFreshnessResource
{
    #[ApiProperty(identifier: true)]
    public string $source;

    public ?string $lastSuccessAt = null;

    public int $expectedInterval;

    public bool $lastRunFailed = false;
}
