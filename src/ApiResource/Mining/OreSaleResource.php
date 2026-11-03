<?php

declare(strict_types=1);

namespace App\ApiResource\Mining;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\Mining\OreSaleProvider;

#[ApiResource(
    shortName: 'OreSale',
    operations: [
        new Get(
            uriTemplate: '/mining/ore-sale',
            provider: OreSaleProvider::class,
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class OreSaleResource
{
    public ?int $structureId = null;

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var array<string, mixed> */
    public array $totals = [];
}
