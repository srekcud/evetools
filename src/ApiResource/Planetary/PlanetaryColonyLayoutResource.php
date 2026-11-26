<?php

declare(strict_types=1);

namespace App\ApiResource\Planetary;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Planetary\CreateColonyLayoutInput;
use App\ApiResource\Input\Planetary\RenameColonyLayoutInput;
use App\Entity\PlanetaryColonyLayout;
use App\State\Processor\Planetary\CreateColonyLayoutProcessor;
use App\State\Processor\Planetary\DeleteColonyLayoutProcessor;
use App\State\Processor\Planetary\RenameColonyLayoutProcessor;
use App\State\Provider\Planetary\PlanetaryColonyLayoutCollectionProvider;
use App\State\Provider\Planetary\PlanetaryColonyLayoutItemProvider;

#[ApiResource(
    shortName: 'PlanetaryColonyLayout',
    description: 'Colony layouts imported by the user from game templates, summarized from the SDE at read time',
    operations: [
        new GetCollection(
            uriTemplate: '/planetary/layouts',
            provider: PlanetaryColonyLayoutCollectionProvider::class,
            openapi: new Model\Operation(summary: 'List the colony layouts of the connected user', tags: ['Planetary']),
        ),
        new Get(
            uriTemplate: '/planetary/layouts/{id}',
            provider: PlanetaryColonyLayoutItemProvider::class,
            openapi: new Model\Operation(summary: 'Get a colony layout of the connected user', tags: ['Planetary']),
        ),
        new Post(
            uriTemplate: '/planetary/layouts',
            input: CreateColonyLayoutInput::class,
            processor: CreateColonyLayoutProcessor::class,
            openapi: new Model\Operation(summary: 'Import a game template as a colony layout', tags: ['Planetary']),
        ),
        new Patch(
            uriTemplate: '/planetary/layouts/{id}',
            provider: PlanetaryColonyLayoutItemProvider::class,
            input: RenameColonyLayoutInput::class,
            processor: RenameColonyLayoutProcessor::class,
            openapi: new Model\Operation(summary: 'Rename a colony layout', tags: ['Planetary']),
        ),
        new Delete(
            uriTemplate: '/planetary/layouts/{id}',
            provider: PlanetaryColonyLayoutItemProvider::class,
            processor: DeleteColonyLayoutProcessor::class,
            openapi: new Model\Operation(summary: 'Delete a colony layout', tags: ['Planetary']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class PlanetaryColonyLayoutResource
{
    #[ApiProperty(identifier: true)]
    public string $id = '';

    public string $name = '';

    /** @var array<string, mixed> */
    public array $summary = [];

    /** @var array<string, mixed> */
    public array $gameTemplate = [];

    public string $createdAt = '';

    /**
     * @param array<string, mixed> $summary
     */
    public static function fromEntity(PlanetaryColonyLayout $layout, array $summary): self
    {
        $resource = new self();
        $resource->id = (string) $layout->getId();
        $resource->name = $layout->getName();
        $resource->summary = $summary;
        $resource->gameTemplate = $layout->getTemplate();
        $resource->createdAt = $layout->getCreatedAt()->format('c');

        return $resource;
    }
}
