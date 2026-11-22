<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Industry\Project\PlaceProjectContainerInput;
use App\Service\Industry\Project\ProjectContainerStation;
use App\State\Processor\Industry\Project\PlaceProjectContainerProcessor;
use App\State\Provider\Industry\Project\ProjectContainerStationsProvider;

#[ApiResource(
    shortName: 'ProjectContainerStation',
    description: 'Station of the plan of a project with its project container or the name proposed for one',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/projects/{projectId}/container-stations',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectContainerStationsProvider::class,
            paginationEnabled: false,
            openapi: new Model\Operation(
                summary: 'List the stations of the plan with their project container',
                description: 'Returns one entry per station of the plan: its project container, or the name proposed for one',
                tags: ['Group Industry - Containers'],
            ),
        ),
        new Post(
            uriTemplate: '/industry/projects/{projectId}/containers',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            status: 200,
            processor: PlaceProjectContainerProcessor::class,
            input: PlaceProjectContainerInput::class,
            openapi: new Model\Operation(
                summary: 'Create the project container of a station or locate an existing one',
                description: 'Creates the container of a station of the plan, or gives the station to the container of the same name that has none',
                tags: ['Group Industry - Containers'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectContainerStationResource
{
    #[ApiProperty(identifier: true)]
    public string $structureId = '';

    public string $structureName = '';

    public ?int $locationId = null;

    public ?int $solarSystemId = null;

    /**
     * @var array{id: string, name: string}|null
     */
    public ?array $container = null;

    public ?string $proposedName = null;

    public static function fromStation(ProjectContainerStation $station): self
    {
        $resource = new self();
        $resource->structureId = $station->structureId;
        $resource->structureName = $station->structureName;
        $resource->locationId = $station->locationId;
        $resource->solarSystemId = $station->solarSystemId;
        $resource->container = null === $station->containerId
            ? null
            : ['id' => $station->containerId, 'name' => $station->containerName ?? throw new \LogicException('A station with a container carries its name.')];
        $resource->proposedName = $station->proposedName;

        return $resource;
    }
}
