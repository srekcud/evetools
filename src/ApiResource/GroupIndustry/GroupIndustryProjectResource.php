<?php

declare(strict_types=1);

namespace App\ApiResource\GroupIndustry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\ApiResource\Input\GroupIndustry\CreateGroupProjectInput;
use App\ApiResource\Input\GroupIndustry\UpdateGroupProjectInput;
use App\State\Processor\GroupIndustry\CreateGroupProjectProcessor;
use App\State\Processor\GroupIndustry\DeleteGroupProjectProcessor;
use App\State\Processor\GroupIndustry\JoinGroupProjectProcessor;
use App\State\Processor\GroupIndustry\UpdateGroupProjectProcessor;
use App\State\Provider\GroupIndustry\AvailableGroupProjectsProvider;
use App\State\Provider\GroupIndustry\GroupProjectCollectionProvider;
use App\State\Provider\GroupIndustry\GroupProjectDeleteProvider;
use App\State\Provider\GroupIndustry\GroupProjectProvider;

#[ApiResource(
    shortName: 'GroupIndustryProject',
    description: 'Group industry collaborative projects',
    operations: [
        new GetCollection(
            uriTemplate: '/group-industry/projects',
            provider: GroupProjectCollectionProvider::class,
            openapi: new Model\Operation(
                summary: 'List my projects',
                description: 'Returns all group industry projects where the current user is a member',
                tags: ['Group Industry - Projects'],
            ),
        ),
        new GetCollection(
            uriTemplate: '/group-industry/projects/available',
            provider: AvailableGroupProjectsProvider::class,
            openapi: new Model\Operation(
                summary: 'List available projects',
                description: 'Returns same-corp projects the user has not yet joined',
                tags: ['Group Industry - Projects'],
            ),
        ),
        new Get(
            uriTemplate: '/group-industry/projects/{id<[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}>}',
            provider: GroupProjectProvider::class,
            openapi: new Model\Operation(
                summary: 'Get project details',
                description: 'Returns detailed project information with items, BOM summary, and current user role',
                tags: ['Group Industry - Projects'],
            ),
        ),
        new Post(
            uriTemplate: '/group-industry/projects',
            processor: CreateGroupProjectProcessor::class,
            input: CreateGroupProjectInput::class,
            openapi: new Model\Operation(
                summary: 'Create a project',
                description: 'Creates a new group industry project with BOM generation',
                tags: ['Group Industry - Projects'],
            ),
        ),
        new Patch(
            uriTemplate: '/group-industry/projects/{id<[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}>}',
            provider: GroupProjectProvider::class,
            processor: UpdateGroupProjectProcessor::class,
            input: UpdateGroupProjectInput::class,
            openapi: new Model\Operation(
                summary: 'Update a project',
                description: 'Updates project settings or transitions status (owner/admin only)',
                tags: ['Group Industry - Projects'],
            ),
        ),
        new Delete(
            uriTemplate: '/group-industry/projects/{id<[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}>}',
            provider: GroupProjectDeleteProvider::class,
            processor: DeleteGroupProjectProcessor::class,
            openapi: new Model\Operation(
                summary: 'Delete a project',
                description: 'Deletes a group industry project (owner only)',
                tags: ['Group Industry - Projects'],
            ),
        ),
        new Post(
            uriTemplate: '/group-industry/projects/join/{shortLinkCode}',
            uriVariables: [
                'shortLinkCode' => new Link(
                    fromClass: self::class,
                    identifiers: ['shortLinkCode'],
                ),
            ],
            processor: JoinGroupProjectProcessor::class,
            input: EmptyInput::class,
            openapi: new Model\Operation(
                summary: 'Join a project via short link',
                description: 'Join a group industry project using its short link code. Same-corp members are auto-accepted.',
                tags: ['Group Industry - Projects'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class GroupIndustryProjectResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public ?string $name = null;

    public string $status;

    public string $shortLinkCode;

    public ?string $containerName = null;

    public string $ownerCharacterName;

    public ?int $ownerCorporationId = null;

    public int $membersCount = 0;

    /** @var list<array{typeId: int, typeName: string, meLevel: int, teLevel: int, runs: int}> */
    public array $items = [];

    public float $totalBomValue = 0.0;

    public float $fulfillmentPercent = 0.0;

    public float $brokerFeePercent = 3.6;

    public float $salesTaxPercent = 3.6;

    /** @var array<string, int>|null */
    public ?array $lineRentalRatesOverride = null;

    /** @var int[] */
    public array $blacklistGroupIds = [];

    /** @var int[] */
    public array $blacklistTypeIds = [];

    public string $createdAt;

    /** Current user's role in the project, null if not a member */
    public ?string $myRole = null;
}
