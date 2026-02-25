<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Industry\StockpileImportInput;
use App\State\Processor\Industry\StockpileImportPreviewProcessor;

#[ApiResource(
    shortName: 'StockpileImportPreview',
    description: 'Preview stockpile import from a blueprint',
    operations: [
        new Post(
            uriTemplate: '/industry/stockpile-targets/preview',
            processor: StockpileImportPreviewProcessor::class,
            input: StockpileImportInput::class,
            openapi: new Model\Operation(summary: 'Preview import', description: 'Preview targets that would be imported from a blueprint', tags: ['Industry - Stockpile']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class StockpileImportPreviewResource
{
    /** @var array<string, list<array{typeId: int, typeName: string, quantity: int, unitPrice: float|null}>> */
    public array $stages = [];

    public int $totalItems = 0;

    public float $estimatedCost = 0.0;
}
