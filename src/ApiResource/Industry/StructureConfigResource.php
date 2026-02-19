<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Industry\CreateStructureInput;
use App\ApiResource\Input\Industry\UpdateStructureInput;
use App\State\Processor\Industry\CreateStructureProcessor;
use App\State\Processor\Industry\DeleteStructureProcessor;
use App\State\Processor\Industry\UpdateStructureProcessor;
use App\State\Provider\Industry\StructureConfigCollectionProvider;
use App\State\Provider\Industry\StructureConfigProvider;
use App\State\Provider\Industry\StructureDeleteProvider;

#[ApiResource(
    shortName: 'IndustryStructure',
    description: 'Industry structure configurations',
    operations: [
        new Get(
            uriTemplate: '/industry/structures',
            provider: StructureConfigCollectionProvider::class,
            output: StructureConfigListResource::class,
            openapi: new Model\Operation(summary: 'List structures', description: 'Returns all structure configurations for the user', tags: ['Industry - Structures']),
        ),
        new Post(
            uriTemplate: '/industry/structures',
            processor: CreateStructureProcessor::class,
            input: CreateStructureInput::class,
            openapi: new Model\Operation(summary: 'Create structure', description: 'Creates a new structure configuration', tags: ['Industry - Structures']),
        ),
        new Patch(
            uriTemplate: '/industry/structures/{id}',
            provider: StructureConfigProvider::class,
            processor: UpdateStructureProcessor::class,
            input: UpdateStructureInput::class,
            openapi: new Model\Operation(summary: 'Update structure', description: 'Updates structure configuration', tags: ['Industry - Structures']),
        ),
        new Delete(
            uriTemplate: '/industry/structures/{id}',
            provider: StructureDeleteProvider::class,
            processor: DeleteStructureProcessor::class,
            openapi: new Model\Operation(summary: 'Delete structure', description: 'Deletes a structure configuration', tags: ['Industry - Structures']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class StructureConfigResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public string $name;

    public ?int $locationId = null;

    public ?int $solarSystemId = null;

    public string $securityType;

    public string $structureType;

    /** @var string[] */
    public array $rigs = [];

    public bool $isDefault = false;

    public bool $isCorporationStructure = false;

    public float $manufacturingMaterialBonus = 0.0;

    public float $reactionMaterialBonus = 0.0;

    public float $manufacturingTimeBonus = 0.0;

    public float $reactionTimeBonus = 0.0;

    public string $createdAt;
}
