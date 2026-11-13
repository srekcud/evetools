<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\ApiResource\Input\Industry\Project\UpdateParticipantInput;
use App\State\Processor\Industry\Project\KickParticipantProcessor;
use App\State\Processor\Industry\Project\LeaveProjectProcessor;
use App\State\Processor\Industry\Project\UpdateParticipantProcessor;
use App\State\Provider\Industry\Project\ProjectParticipantCollectionProvider;
use App\State\Provider\Industry\Project\ProjectParticipantItemProvider;

#[ApiResource(
    shortName: 'ProjectParticipant',
    description: 'Members of a group industry project',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/projects/{projectId}/members',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectParticipantCollectionProvider::class,
            openapi: new Model\Operation(
                summary: 'List project members',
                description: 'Returns all members of the project with contribution stats',
                tags: ['Group Industry - Members'],
            ),
        ),
        new Patch(
            uriTemplate: '/industry/projects/{projectId}/members/{id<[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}>}',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            provider: ProjectParticipantItemProvider::class,
            processor: UpdateParticipantProcessor::class,
            input: UpdateParticipantInput::class,
            openapi: new Model\Operation(
                summary: 'Update a member',
                description: 'Change role (promote/demote) or accept a pending member (admin/owner only)',
                tags: ['Group Industry - Members'],
            ),
        ),
        new Delete(
            uriTemplate: '/industry/projects/{projectId}/members/{id<[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}>}',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            provider: ProjectParticipantItemProvider::class,
            processor: KickParticipantProcessor::class,
            openapi: new Model\Operation(
                summary: 'Kick a member',
                description: 'Remove a member from the project (admin/owner only, cannot kick the owner)',
                tags: ['Group Industry - Members'],
            ),
        ),
        new Post(
            uriTemplate: '/industry/projects/{projectId}/leave',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            input: EmptyInput::class,
            deserialize: false,
            output: false,
            status: 204,
            processor: LeaveProjectProcessor::class,
            openapi: new Model\Operation(
                summary: 'Leave a project',
                description: 'Leave a group industry project voluntarily. The membership is marked as removed and its contributions are kept. Owner cannot leave.',
                tags: ['Group Industry - Members'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class ProjectParticipantResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public ?string $characterName = null;

    public ?int $characterId = null;

    public ?int $corporationId = null;

    public ?string $corporationName = null;

    public string $role;

    public string $status;

    public ?float $totalContributionValue = 0.0;

    public int $contributionCount = 0;

    public string $joinedAt;
}
