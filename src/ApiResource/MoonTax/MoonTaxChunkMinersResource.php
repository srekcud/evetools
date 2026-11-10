<?php

declare(strict_types=1);

namespace App\ApiResource\MoonTax;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\MoonTax\MoonTaxChunkMinersProvider;

#[ApiResource(
    shortName: 'MoonTaxChunkMiners',
    description: 'Miners of a moon chunk with the tax of their records of the chunk and the statement of each period they touch',
    operations: [
        new Get(
            uriTemplate: '/mining/moon-tax/{corporationId}/chunks/{id}/miners',
            uriVariables: [
                'corporationId' => new Link(fromClass: MoonTaxCorporationResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            provider: MoonTaxChunkMinersProvider::class,
            openapi: new Model\Operation(summary: 'List the miners of a moon chunk with their estimated tax', tags: ['Moon Tax']),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class MoonTaxChunkMinersResource
{
    #[ApiProperty(identifier: true)]
    public ?string $id = null;

    #[ApiProperty(readable: false, writable: false)]
    public ?MoonTaxCorporationResource $corporation = null;

    public string $taxBasis = 'current_prices';

    public bool $refineryOpen = true;

    /** @var list<array{characterId: int, characterName: ?string, chunkTax: ?float, unknownTypeIds: list<int>, statements: list<array{periodStart: string, periodEnd: string, reference: ?string, status: ?string, tax: ?float, paid: ?float, remaining: ?float}>}> */
    public array $miners = [];
}
