<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ContainerVerificationProvider;

#[ApiResource(
    shortName: 'ProjectContainerVerification',
    description: 'Container verification results for a group industry project',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/projects/{projectId}/container-verification',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ContainerVerificationProvider::class,
            openapi: new Model\Operation(
                summary: 'Verify container contents',
                description: 'Compares BOM material requirements against corp container contents',
                tags: ['Group Industry - Container'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class ProjectContainerVerificationResource
{
    #[ApiProperty(identifier: true)]
    public string $bomItemId;

    public int $typeId;

    public string $typeName;

    public int $requiredQuantity;

    /** Quantity found in the corp container(s) */
    public ?int $containerQuantity;

    public string $status;

    public ?string $unknownReason = null;
}
