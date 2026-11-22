<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\FactoryStationCollectionProvider;

#[ApiResource(
    shortName: 'FactoryStation',
    description: 'Stations of the user with specialisation, cost index, access per character, lines in use and open projects',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/factory/stations',
            provider: FactoryStationCollectionProvider::class,
            paginationEnabled: false,
            openapi: new Model\Operation(summary: 'List my stations for the Factory page', tags: ['Industry - Factory']),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class FactoryStationResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public string $name;

    public string $structureType;

    public string $securityType;

    public ?int $locationId = null;

    public ?int $solarSystemId = null;

    public ?string $solarSystemName = null;

    /** @var list<string> */
    public array $specialisations = [];

    public ?float $manufacturingCostIndex = null;

    public ?float $reactionCostIndex = null;

    /** @var list<array{characterId: int, characterName: string, status: string}> */
    public array $access = [];

    public ?int $linesInUse = null;

    /** @var list<array{id: string, name: string|null}> */
    public array $projects = [];
}
