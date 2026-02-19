<?php

declare(strict_types=1);

namespace App\ApiResource\Sde;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Sde\SolarSystemSearchProvider;

#[ApiResource(
    shortName: 'SolarSystemSearch',
    operations: [
        new GetCollection(
            uriTemplate: '/sde/solar-systems',
            provider: SolarSystemSearchProvider::class,
            openapi: new Model\Operation(tags: ['Navigation']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class SolarSystemSearchResource
{
    public int $solarSystemId = 0;

    public string $solarSystemName = '';

    public float $security = 0.0;

    public string $regionName = '';
}
