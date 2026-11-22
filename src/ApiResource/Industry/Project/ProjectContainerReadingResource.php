<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectContainerReadingsProvider;

#[ApiResource(
    shortName: 'ProjectContainerReading',
    description: 'What a project container holds at its station, or why it cannot be read',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/projects/{projectId}/container-readings',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectContainerReadingsProvider::class,
            paginationEnabled: false,
            openapi: new Model\Operation(
                summary: 'Read the project containers station by station',
                description: 'Returns one reading per project container: its contents at its station, or an unknown status with the reason, never a zero',
                tags: ['Group Industry - Containers'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectContainerReadingResource
{
    #[ApiProperty(identifier: true)]
    public string $containerId = '';

    public string $containerName = '';

    public ?int $locationId = null;

    public string $status = '';

    public ?string $unknownReason = null;

    /**
     * @var list<array{typeId: int, typeName: ?string, quantity: int}>|null
     */
    public ?array $items = null;

    public ?\DateTimeImmutable $assetsSyncedAt = null;
}
