<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\State\Processor\Industry\DismissUnassignedJobProcessor;
use App\State\Provider\Industry\UnassignedJobsProvider;

#[ApiResource(
    shortName: 'UnassignedJob',
    description: 'ESI jobs of the user that no project clearly wins, with the lines they can be assigned to',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/unassigned-jobs',
            provider: UnassignedJobsProvider::class,
            paginationEnabled: false,
            openapi: new Model\Operation(summary: 'Unassigned jobs', description: 'Lists the jobs of the own characters that the matching pass did not link, with their valid candidate lines', tags: ['Industry - Projects']),
        ),
        new Post(
            uriTemplate: '/industry/unassigned-jobs/{esiJobId}/dismiss',
            uriVariables: [
                'esiJobId' => new Link(fromClass: self::class),
            ],
            processor: DismissUnassignedJobProcessor::class,
            input: EmptyInput::class,
            deserialize: false,
            output: false,
            status: 204,
            openapi: new Model\Operation(summary: 'Dismiss an unassigned job', description: 'Marks the job as not meant for a project: never proposed again, never linked automatically', tags: ['Industry - Projects']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['skip_null_values' => false],
)]
class UnassignedJobResource
{
    #[ApiProperty(identifier: true)]
    public int $esiJobId;

    public string $characterName;

    public int $blueprintTypeId;

    public int $productTypeId;

    public ?string $productTypeName = null;

    public int $runs;

    public int $activityId;

    public ?int $stationId = null;

    public ?string $stationName = null;

    public string $startDate;

    public string $endDate;

    public string $status;

    /** @var list<array{projectId: string, projectName: string|null, lineId: string, lineName: string, remainingRuns: int, score: int, signals: list<string>, oversized: bool}> */
    public array $candidates = [];
}
