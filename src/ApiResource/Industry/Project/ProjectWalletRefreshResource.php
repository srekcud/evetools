<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\State\Processor\Industry\Project\RefreshProjectWalletProcessor;

#[ApiResource(
    shortName: 'ProjectWalletRefresh',
    description: 'On-demand wallet reading of the calling member characters, from a group industry project',
    operations: [
        new Post(
            uriTemplate: '/industry/projects/{projectId}/wallet-refresh',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            status: 200,
            input: EmptyInput::class,
            deserialize: false,
            processor: RefreshProjectWalletProcessor::class,
            openapi: new Model\Operation(
                summary: 'Refresh the wallet of the calling member',
                description: 'Queues a wallet transactions sync for each character of the caller whose ESI cache has expired. Returns how many characters were queued and how many are still cached.',
                tags: ['Group Industry - Wallet'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['skip_null_values' => false],
)]
class ProjectWalletRefreshResource
{
    #[ApiProperty(identifier: true)]
    public string $projectId;

    public int $queuedCharacters;

    public int $notDueCharacters;
}
