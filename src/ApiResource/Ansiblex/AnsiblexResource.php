<?php

declare(strict_types=1);

namespace App\ApiResource\Ansiblex;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
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
            openapi: new Model\Operation(summary: 'List Ansiblex gates', description: 'Returns all Ansiblex jump gates for the user alliance', tags: ['Navigation']),
        ),
        new Post(
            uriTemplate: '/me/ansiblex/refresh',
            processor: RefreshAnsiblexProcessor::class,
            input: EmptyInput::class,
            output: AnsiblexSyncResultResource::class,
            openapi: new Model\Operation(
                summary: 'Refresh Ansiblex gates',
                description: 'Triggers a sync of Ansiblex gates (requires Director role)',
                tags: ['Navigation'],
                parameters: [
                    new Model\Parameter(name: 'async', in: 'query', required: false, schema: ['type' => 'boolean', 'default' => true]),
                ],
            ),
        ),
        new Post(
            uriTemplate: '/me/ansiblex/discover',
            processor: DiscoverAnsiblexProcessor::class,
            input: EmptyInput::class,
            output: AnsiblexSyncResultResource::class,
            openapi: new Model\Operation(summary: 'Discover Ansiblex gates', description: 'Discovers Ansiblex gates via search (any character with ACL access)', tags: ['Navigation']),
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
