<?php

declare(strict_types=1);

namespace App\ApiResource\Mining;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\Mining\OreValueTableProvider;

#[ApiResource(
    shortName: 'OreValueTable',
    operations: [
        new Get(
            uriTemplate: '/mining/ore-values',
            provider: OreValueTableProvider::class,
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class OreValueTableResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'ore-values';

    public string $market = '';

    public string $side = '';

    public ?int $structureId = null;

    public bool $reprocessYieldKnown = false;

    /** @var array{market: string, side: string, form: string, structureName: string|null}|null */
    public ?array $valuationSource = null;

    /** @var list<array<string, mixed>> */
    public array $rows = [];
}
