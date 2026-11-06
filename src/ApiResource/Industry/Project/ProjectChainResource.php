<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectChainProvider;

#[ApiResource(
    shortName: 'ProjectChain',
    description: 'Production chain of a group industry project, derived from its BOM, linked jobs, contributions and sales',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/chain',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectChainProvider::class,
            openapi: new Model\Operation(
                summary: 'Get production chain',
                description: 'Returns one node per BOM line plus the sale node, with status, real cost, suppliers and linked jobs',
                tags: ['Group Industry - Chain'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class ProjectChainResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    /**
     * @var list<object>
     */
    public array $nodes = [];
}
