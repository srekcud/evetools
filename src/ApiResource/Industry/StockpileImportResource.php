<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Industry\StockpileImportInput;
use App\State\Processor\Industry\StockpileImportProcessor;

#[ApiResource(
    shortName: 'StockpileImport',
    description: 'Import stockpile targets from a blueprint',
    operations: [
        new Post(
            uriTemplate: '/industry/stockpile-targets/import',
            processor: StockpileImportProcessor::class,
            input: StockpileImportInput::class,
            openapi: new Model\Operation(summary: 'Import targets', description: 'Import stockpile targets from a blueprint production tree', tags: ['Industry - Stockpile']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class StockpileImportResource
{
    public string $status = 'success';

    public int $importedCount = 0;
}
