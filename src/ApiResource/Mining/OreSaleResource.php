<?php

declare(strict_types=1);

namespace App\ApiResource\Mining;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use App\ApiResource\MiningOperation\MiningOperationResource;
use App\State\Provider\Mining\OreSaleProvider;
use App\State\Provider\MiningOperation\MiningOperationOreSaleProvider;

#[ApiResource(
    shortName: 'OreSale',
    operations: [
        new Get(
            uriTemplate: '/mining/ore-sale',
            provider: OreSaleProvider::class,
        ),
        new Get(
            uriTemplate: '/mining-operations/{operationId}/ore-sale',
            uriVariables: [
                'operationId' => new Link(fromClass: MiningOperationResource::class),
            ],
            provider: MiningOperationOreSaleProvider::class,
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class OreSaleResource
{
    public ?string $operationId = null;

    public ?int $structureId = null;

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var array<string, mixed> */
    public array $totals = [];

    /** @var list<array{typeId: int, typeName: string|null}> */
    public array $unconvertedTypes = [];
}
