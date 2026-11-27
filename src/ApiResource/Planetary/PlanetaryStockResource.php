<?php

declare(strict_types=1);

namespace App\ApiResource\Planetary;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Planetary\PlanetaryStockProvider;

#[ApiResource(
    shortName: 'PlanetaryStock',
    description: 'Coverage of the PI inputs by the stock of the connected user',
    operations: [
        new Get(
            uriTemplate: '/planetary/stock',
            provider: PlanetaryStockProvider::class,
            openapi: new Model\Operation(summary: 'Get the PI input coverage and the purchases to make', tags: ['Planetary']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['skip_null_values' => false],
)]
final class PlanetaryStockResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'stock';

    public ?int $homeLocationId = null;

    public ?string $homeLocationName = null;

    public int $hubStructureId = 0;

    public string $hubStructureName = '';

    public int $coverageWeeks = 0;

    public ?string $corpUnknownReason = null;

    public bool $complete = false;

    /** @var list<int> */
    public array $excludedTypeIds = [];

    /** @var list<array<string, mixed>> */
    public array $rows = [];
}
