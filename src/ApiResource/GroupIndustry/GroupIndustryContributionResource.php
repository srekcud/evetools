<?php

declare(strict_types=1);

namespace App\ApiResource\GroupIndustry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\GroupIndustry\ReviewContributionInput;
use App\ApiResource\Input\GroupIndustry\SubmitContributionInput;
use App\State\Processor\GroupIndustry\ReviewContributionProcessor;
use App\State\Processor\GroupIndustry\SubmitContributionProcessor;
use App\State\Provider\GroupIndustry\GroupContributionCollectionProvider;
use App\State\Provider\GroupIndustry\GroupContributionItemProvider;

#[ApiResource(
    shortName: 'GroupIndustryContribution',
    description: 'Contributions to a group industry project',
    operations: [
        new GetCollection(
            uriTemplate: '/group-industry/projects/{projectId}/contributions',
            uriVariables: [
                'projectId' => new Link(fromClass: GroupIndustryProjectResource::class),
            ],
            provider: GroupContributionCollectionProvider::class,
            openapi: new Model\Operation(
                summary: 'List project contributions',
                description: 'Returns all contributions for the project',
                tags: ['Group Industry - Contributions'],
            ),
        ),
        new Post(
            uriTemplate: '/group-industry/projects/{projectId}/contributions',
            uriVariables: [
                'projectId' => new Link(fromClass: GroupIndustryProjectResource::class),
            ],
            processor: SubmitContributionProcessor::class,
            input: SubmitContributionInput::class,
            openapi: new Model\Operation(
                summary: 'Submit a contribution',
                description: 'Submits a new contribution (material, job install, BPC, or line rental)',
                tags: ['Group Industry - Contributions'],
            ),
        ),
        new Patch(
            uriTemplate: '/group-industry/projects/{projectId}/contributions/{id<[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}>}',
            uriVariables: [
                'projectId' => new Link(fromClass: GroupIndustryProjectResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            provider: GroupContributionItemProvider::class,
            processor: ReviewContributionProcessor::class,
            input: ReviewContributionInput::class,
            openapi: new Model\Operation(
                summary: 'Review a contribution',
                description: 'Approve or reject a pending contribution (admin/owner only)',
                tags: ['Group Industry - Contributions'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class GroupIndustryContributionResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public string $memberCharacterName;

    public string $memberId;

    public ?string $bomItemId = null;

    public ?string $bomItemTypeName = null;

    public string $type;

    public int $quantity;

    public float $estimatedValue;

    public string $status;

    public bool $isAutoDetected;

    public bool $isVerified;

    public ?string $reviewedByCharacterName = null;

    public ?string $reviewedAt = null;

    public ?string $note = null;

    public string $createdAt;
}
