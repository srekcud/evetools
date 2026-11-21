<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Industry\Project\IndustryProjectResource;
use App\ApiResource\Input\EmptyInput;
use App\State\Processor\Industry\MatchJobsProcessor;

#[ApiResource(
    shortName: 'MatchJobsResult',
    description: 'Result of the sync of the ESI jobs of the caller and of the matching of the jobs to the job lines of the project BOM',
    operations: [
        new Post(
            uriTemplate: '/industry/projects/{projectId}/match-jobs',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            processor: MatchJobsProcessor::class,
            input: EmptyInput::class,
            deserialize: false,
            openapi: new Model\Operation(summary: 'Match ESI jobs', description: 'Syncs the industry jobs of the characters of the caller, an accepted participant of the project, then matches the job lines of the BOM with the ESI industry jobs', tags: ['Industry - Projects']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['skip_null_values' => false],
)]
class MatchJobsResultResource
{
    /** @var ProjectJobMatchResource[] */
    #[ApiProperty(readableLink: true)]
    public array $jobMatches = [];

    public ?float $jobsCost = null;

    public int $syncedCharacters = 0;

    /** @var list<array{characterName: string, reason: string, statusCode: int|null}> */
    public array $failedCharacters = [];

    /** @var list<array{typeId: int, typeName: string, reason: string}> */
    public array $failedLines = [];
}
