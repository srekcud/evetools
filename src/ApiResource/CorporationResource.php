<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\CorporationProvider;

#[ApiResource(
    shortName: 'Corporation',
    operations: [
        new Get(
            uriTemplate: '/me/corporation',
            provider: CorporationProvider::class,
        ),
    ],
)]
class CorporationResource
{
    #[ApiProperty(identifier: true)]
    public int $id;

    public string $name;

    public string $ticker;

    public int $memberCount;

    public ?int $allianceId = null;

    /** @var array<int, string> */
    public array $divisions = [];
}
