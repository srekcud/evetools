<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectContractSuggestionsProvider;

#[ApiResource(
    shortName: 'IndustryProjectContractSuggestions',
    description: 'Finished contracts of the viewer characters that sold one item of a group industry project',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/contract-suggestions',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectContractSuggestionsProvider::class,
            openapi: new Model\Operation(
                summary: 'List the contracts that can be linked to a sale',
                description: 'Reads the finished item exchange contracts the viewer characters issued after the project was created, for one item of the project (admin/owner only)',
                tags: ['Group Industry - Sales'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['skip_null_values' => false],
)]
class ProjectContractSuggestionsResource
{
    #[ApiProperty(identifier: true)]
    public string $projectId;

    /**
     * @var list<array{contractId: int, typeId: int, typeName: string, quantity: int, price: float, unitPrice: float, completedAt: string, sellerCharacterId: int, sellerCharacterName: string, contractFees: float|null}>
     */
    public array $candidates = [];

    /**
     * @var list<array{characterId: int, reason: string}>
     */
    public array $unreadableCharacters = [];
}
