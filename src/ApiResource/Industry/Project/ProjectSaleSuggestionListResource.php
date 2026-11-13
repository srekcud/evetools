<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Industry\WalletReadingResource;
use App\State\Provider\Industry\Project\ProjectSaleSuggestionProvider;

#[ApiResource(
    shortName: 'ProjectSaleSuggestionList',
    description: 'Wallet transactions that can be recorded as sales of a group industry project, with the state of the wallet readings',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/sale-suggestions',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectSaleSuggestionProvider::class,
            openapi: new Model\Operation(
                summary: 'List sale suggestions',
                description: 'Returns the unlinked sales of the wallet of the current user that match an item of the project, and when each wallet was last read (admin/owner only)',
                tags: ['Group Industry - Sales'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['skip_null_values' => false],
)]
class ProjectSaleSuggestionListResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    /** @var ProjectSaleSuggestionResource[] */
    public array $suggestions = [];

    /** @var WalletReadingResource[] */
    public array $walletReadings = [];
}
