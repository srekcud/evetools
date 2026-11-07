<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectReactionStepsProvider;

#[ApiResource(
    shortName: 'ProjectReactionSteps',
    description: 'Reaction steps of an industry project: formula, base duration, produced quantity and surplus per reaction',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/reactions',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectReactionStepsProvider::class,
            openapi: new Model\Operation(
                summary: 'Get reaction steps',
                description: 'Returns one step per reaction of the project BOM with its SDE formula, base duration, need and surplus',
                tags: ['Group Industry - Chain'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['skip_null_values' => false],
)]
class ProjectReactionStepsResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    /**
     * @var list<object>
     */
    public array $steps = [];
}
