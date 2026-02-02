<?php

declare(strict_types=1);

namespace App\ApiResource\Ansiblex;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\EmptyInput;
use App\State\Processor\Ansiblex\DiscoverAnsiblexProcessor;
use App\State\Processor\Ansiblex\RefreshAnsiblexProcessor;
use App\State\Provider\Ansiblex\AnsiblexCollectionProvider;

#[ApiResource(
    shortName: 'Ansiblex',
    description: 'Ansiblex jump gates',
    operations: [
        new GetCollection(
            uriTemplate: '/me/ansiblex',
            provider: AnsiblexCollectionProvider::class,
            openapiContext: [
                'summary' => 'List Ansiblex gates',
                'description' => 'Returns all Ansiblex jump gates for the user alliance',
            ],
        ),
        new Post(
            uriTemplate: '/me/ansiblex/refresh',
            processor: RefreshAnsiblexProcessor::class,
            input: EmptyInput::class,
            output: AnsiblexSyncResultResource::class,
            openapiContext: [
                'summary' => 'Refresh Ansiblex gates',
                'description' => 'Triggers a sync of Ansiblex gates (requires Director role)',
                'parameters' => [
                    [
                        'name' => 'async',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'boolean', 'default' => true],
                        'description' => 'Use async processing (default: true)',
                    ],
                ],
            ],
        ),
        new Post(
            uriTemplate: '/me/ansiblex/discover',
            processor: DiscoverAnsiblexProcessor::class,
            input: EmptyInput::class,
            output: AnsiblexSyncResultResource::class,
            openapiContext: [
                'summary' => 'Discover Ansiblex gates',
                'description' => 'Discovers Ansiblex gates via search (any character with ACL access)',
            ],
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class AnsiblexResource
{
    #[ApiProperty(identifier: true)]
    public int $structureId;

    public string $name;

    public AnsiblexLocationResource $source;

    public AnsiblexLocationResource $destination;

    public AnsiblexOwnerResource $owner;

    public bool $isActive = true;

    public ?string $lastSeenAt = null;

    public string $updatedAt;
}
