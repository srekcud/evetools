<?php

declare(strict_types=1);

namespace App\ApiResource\MoonTax;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\MoonTax\MoonTaxMineProvider;

#[ApiResource(
    shortName: 'MoonTaxMine',
    description: 'Moon tax statements of the characters of my account',
    operations: [
        new GetCollection(
            uriTemplate: '/mining/moon-tax/mine',
            provider: MoonTaxMineProvider::class,
            openapi: new Model\Operation(summary: 'List my moon tax statements, with the period in progress as provisional', tags: ['Moon Tax']),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class MoonTaxMineResource
{
    public int $corporationId;

    public int $structureId;

    public int $characterId;

    public \DateTimeImmutable $periodStart;

    public \DateTimeImmutable $periodEnd;

    public ?string $reference = null;

    public ?float $tax = null;

    public float $paid = 0.0;

    public ?float $remaining = null;

    public string $status;

    public ?bool $outsideAlliance = null;

    /** @var list<int> */
    public array $unknownTypeIds = [];
}
