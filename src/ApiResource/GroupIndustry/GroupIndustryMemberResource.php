<?php

declare(strict_types=1);

namespace App\ApiResource\GroupIndustry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\GroupIndustry\UpdateGroupMemberInput;
use App\State\Processor\GroupIndustry\KickGroupMemberProcessor;
use App\State\Processor\GroupIndustry\UpdateGroupMemberProcessor;
use App\State\Provider\GroupIndustry\GroupMemberCollectionProvider;
use App\State\Provider\GroupIndustry\GroupMemberItemProvider;

#[ApiResource(
    shortName: 'GroupIndustryMember',
    description: 'Members of a group industry project',
    operations: [
        new GetCollection(
            uriTemplate: '/group-industry/projects/{projectId}/members',
            uriVariables: [
                'projectId' => new Link(fromClass: GroupIndustryProjectResource::class),
            ],
            provider: GroupMemberCollectionProvider::class,
            openapi: new Model\Operation(
                summary: 'List project members',
                description: 'Returns all members of the project with contribution stats',
                tags: ['Group Industry - Members'],
            ),
        ),
        new Patch(
            uriTemplate: '/group-industry/projects/{projectId}/members/{id<[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}>}',
            uriVariables: [
                'projectId' => new Link(fromClass: GroupIndustryProjectResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            provider: GroupMemberItemProvider::class,
            processor: UpdateGroupMemberProcessor::class,
            input: UpdateGroupMemberInput::class,
            openapi: new Model\Operation(
                summary: 'Update a member',
                description: 'Change role (promote/demote) or accept a pending member (admin/owner only)',
                tags: ['Group Industry - Members'],
            ),
        ),
        new Delete(
            uriTemplate: '/group-industry/projects/{projectId}/members/{id<[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}>}',
            uriVariables: [
                'projectId' => new Link(fromClass: GroupIndustryProjectResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            provider: GroupMemberItemProvider::class,
            processor: KickGroupMemberProcessor::class,
            openapi: new Model\Operation(
                summary: 'Kick a member',
                description: 'Remove a member from the project (admin/owner only, cannot kick the owner)',
                tags: ['Group Industry - Members'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class GroupIndustryMemberResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public string $characterName;

    public int $characterId;

    public ?int $corporationId = null;

    public ?string $corporationName = null;

    public string $role;

    public string $status;

    public float $totalContributionValue = 0.0;

    public int $contributionCount = 0;

    public string $joinedAt;
}
