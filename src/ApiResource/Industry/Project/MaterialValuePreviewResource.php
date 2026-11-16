<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\MaterialValuePreviewProvider;

#[ApiResource(
    shortName: 'MaterialValuePreview',
    description: 'Split of a material contribution between the units matched in the wallet of the contributor and the units still unmatched',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/material-value-previews/{id}',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            provider: MaterialValuePreviewProvider::class,
            openapi: new Model\Operation(
                summary: 'Preview the wallet value of a material contribution',
                description: 'Returns the units of the quantity matched in the buy transactions of the current user, at the price paid, and the units left unmatched (accepted member or owner only)',
                tags: ['Group Industry - Contributions'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class MaterialValuePreviewResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public int $matchedQuantity;

    public ?float $matchedValue = null;

    public int $unmatchedQuantity;

    /** @var list<array{date: string, quantity: int, unitPrice: float}> */
    public array $matches = [];
}
