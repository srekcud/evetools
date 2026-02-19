<?php

declare(strict_types=1);

namespace App\ApiResource\Ansiblex;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Ansiblex\AnsiblexGraphProvider;

#[ApiResource(
    shortName: 'AnsiblexGraph',
    description: 'Ansiblex jump gates adjacency graph for pathfinding',
    operations: [
        new Get(
            uriTemplate: '/me/ansiblex/graph',
            provider: AnsiblexGraphProvider::class,
            openapi: new Model\Operation(summary: 'Get Ansiblex graph', description: 'Returns adjacency list of Ansiblex connections for pathfinding', tags: ['Navigation']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class AnsiblexGraphResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'graph';

    public int $totalSystems;

    /** @var array<int, int[]> */
    public array $graph = [];
}
