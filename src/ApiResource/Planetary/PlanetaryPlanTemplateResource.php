<?php

declare(strict_types=1);

namespace App\ApiResource\Planetary;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Planetary\CreatePlanTemplateInput;
use App\State\Processor\Planetary\CreatePlanTemplateProcessor;
use App\State\Processor\Planetary\DeletePlanTemplateProcessor;
use App\State\Provider\Planetary\PlanetaryPlanTemplateCollectionProvider;
use App\State\Provider\Planetary\PlanetaryPlanTemplateItemProvider;

#[ApiResource(
    shortName: 'PlanetaryPlanTemplate',
    description: 'Planetary Interaction plans saved by the user, recomputed at today\'s prices by the planner',
    operations: [
        new GetCollection(
            uriTemplate: '/planetary/templates',
            provider: PlanetaryPlanTemplateCollectionProvider::class,
            openapi: new Model\Operation(summary: 'List the saved PI plan templates of the connected user', tags: ['Planetary']),
        ),
        new Get(
            uriTemplate: '/planetary/templates/{id}',
            provider: PlanetaryPlanTemplateItemProvider::class,
            openapi: new Model\Operation(summary: 'Get a saved PI plan template (used for IRI resolution)', tags: ['Planetary']),
        ),
        new Post(
            uriTemplate: '/planetary/templates',
            input: CreatePlanTemplateInput::class,
            processor: CreatePlanTemplateProcessor::class,
            openapi: new Model\Operation(summary: 'Save the current PI plan as a template', tags: ['Planetary']),
        ),
        new Delete(
            uriTemplate: '/planetary/templates/{id}',
            provider: PlanetaryPlanTemplateItemProvider::class,
            processor: DeletePlanTemplateProcessor::class,
            openapi: new Model\Operation(summary: 'Delete a saved PI plan template', tags: ['Planetary']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class PlanetaryPlanTemplateResource
{
    #[ApiProperty(identifier: true)]
    public string $id = '';

    public string $name = '';

    /** @var list<array{typeId: int, targetPerDay: int|float}> */
    public array $productions = [];

    /** @var array<int|string, string> */
    public array $choices = [];

    /** @var array{priceSource: string, taxPercent: int|float, daysBetweenRounds: int|float} */
    public array $settings = ['priceSource' => 'jita-sell', 'taxPercent' => 4, 'daysBetweenRounds' => 4];

    public string $createdAt = '';
}
