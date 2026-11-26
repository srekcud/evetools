<?php

declare(strict_types=1);

namespace App\ApiResource\Planetary;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Planetary\PlanetaryColonyLayoutLibraryProvider;

#[ApiResource(
    shortName: 'PlanetaryColonyLayoutLibrary',
    description: 'Public colony layouts published by the users, without any author information',
    operations: [
        new GetCollection(
            uriTemplate: '/planetary/layout-library',
            provider: PlanetaryColonyLayoutLibraryProvider::class,
            openapi: new Model\Operation(summary: 'List the public colony layouts, most recently published first', tags: ['Planetary']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class PlanetaryColonyLayoutLibraryResource
{
    #[ApiProperty(identifier: true)]
    public string $id = '';

    public string $name = '';

    /** @var array<string, mixed> */
    public array $summary = [];

    /** @var array<string, mixed> */
    public array $gameTemplate = [];

    public string $publishedAt = '';

    public bool $mine = false;
}
