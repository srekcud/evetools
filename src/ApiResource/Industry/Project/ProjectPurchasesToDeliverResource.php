<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectPurchasesToDeliverProvider;

#[ApiResource(
    shortName: 'ProjectPurchasesToDeliver',
    description: 'Purchased materials of a project not yet delivered to its container, by place of purchase',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/purchases-to-deliver',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectPurchasesToDeliverProvider::class,
            openapi: new Model\Operation(
                summary: 'List the purchases still to bring to the project container',
                description: 'Returns, for each purchased line and place of purchase, the quantity bought and the quantity still missing in the project container, read from the container stock; unknown stock gives an unknown delivery state, never zero',
                tags: ['Group Industry - Courier'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectPurchasesToDeliverResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public string $stockState;

    public int $purchaseCount = 0;

    /**
     * @var list<array<string, mixed>>
     */
    public array $rows = [];
}
