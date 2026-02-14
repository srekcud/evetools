<?php

declare(strict_types=1);

namespace App\ApiResource\Planetary;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\State\Processor\Planetary\SyncPlanetaryProcessor;
use App\State\Provider\Planetary\ColonyCollectionProvider;
use App\State\Provider\Planetary\ColonyDetailProvider;

#[ApiResource(
    shortName: 'PlanetaryColony',
    description: 'Planetary Interaction colonies',
    operations: [
        new GetCollection(
            uriTemplate: '/planetary',
            provider: ColonyCollectionProvider::class,
            openapi: new Model\Operation(summary: 'List all PI colonies'),
        ),
        new Get(
            uriTemplate: '/planetary/{id<[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}>}',
            provider: ColonyDetailProvider::class,
        ),
        new Post(
            uriTemplate: '/planetary/sync',
            input: EmptyInput::class,
            output: false,
            status: 204,
            processor: SyncPlanetaryProcessor::class,
            openapi: new Model\Operation(summary: 'Sync planetary colonies from ESI'),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class ColonyResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public int $characterId;

    public string $characterName;

    public int $planetId;

    public ?string $planetName = null;

    public string $planetType;

    public int $solarSystemId;

    public ?string $solarSystemName;

    public ?float $solarSystemSecurity = null;

    public int $upgradeLevel;

    public int $numPins;

    public string $lastUpdate;

    public string $cachedAt;

    public int $extractorCount = 0;

    public int $factoryCount = 0;

    public int $activeExtractors = 0;

    public ?string $nearestExpiry = null;

    /** @var string active|expiring|expired|idle */
    public string $status = 'idle';

    public array $pins = [];

    public array $routes = [];
}
