<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Industry\Project\LinkCourierContractInput;
use App\State\Processor\Industry\Project\LinkCourierContractProcessor;
use App\State\Processor\Industry\Project\UnlinkCourierContractProcessor;
use App\State\Provider\Industry\Project\ProjectCourierLinkProvider;
use App\State\Provider\Industry\Project\ProjectCourierProvider;

#[ApiResource(
    shortName: 'ProjectCourier',
    description: 'Prefilled courier contract of the purchases of a project, linked contract and transport state of the purchased lines',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/courier',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectCourierProvider::class,
            openapi: new Model\Operation(
                summary: 'Read the courier contract of the project',
                description: 'Returns the quote of the courier contract (volume, collateral, reward, route), the contract linked to the project with its state read from the ESI, the transport state of each purchased line and, for the owner, the courier contracts that can be linked',
                tags: ['Group Industry - Courier'],
            ),
        ),
        new Post(
            uriTemplate: '/industry/projects/{projectId}/courier-contract',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            processor: LinkCourierContractProcessor::class,
            input: LinkCourierContractInput::class,
            openapi: new Model\Operation(
                summary: 'Link a courier contract to the project',
                description: 'Links a courier contract of one of the characters of the owner, issued after the creation of the project, and replaces the previous link (owner only)',
                tags: ['Group Industry - Courier'],
            ),
        ),
        new Delete(
            uriTemplate: '/industry/projects/{projectId}/courier-contract',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectCourierLinkProvider::class,
            processor: UnlinkCourierContractProcessor::class,
            openapi: new Model\Operation(
                summary: 'Unlink the courier contract of the project',
                description: 'Removes the link between the project and its courier contract (owner only)',
                tags: ['Group Industry - Courier'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectCourierResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public ?float $volumeCubicMeters = null;

    public ?int $collateral = null;

    public ?int $reward = null;

    public ?int $jumps = null;

    public string $quoteStatus;

    /**
     * @var list<int>
     */
    public array $missingVolumeTypeIds = [];

    public int $daysToComplete;

    public int $expirationDays;

    public int $originSolarSystemId;

    public ?string $originSolarSystemName = null;

    public ?int $workshopSolarSystemId = null;

    public ?string $workshopSolarSystemName = null;

    public bool $canLink;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $contract = null;

    /**
     * @var list<array<string, mixed>>
     */
    public array $lines = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $candidates = [];
}
