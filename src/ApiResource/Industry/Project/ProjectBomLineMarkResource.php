<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Industry\Project\MarkBomLineInput;
use App\Entity\IndustryProjectBomLine;
use App\State\Processor\Industry\Project\MarkBomLineProcessor;
use App\State\Processor\Industry\Project\RemoveBomLineMarkProcessor;
use App\State\Provider\Industry\Project\BomLineMarkProvider;

#[ApiResource(
    shortName: 'ProjectBomLineMark',
    description: 'Declaration that a job line of a project BOM was launched or done outside the application',
    operations: [
        new Post(
            uriTemplate: '/industry/projects/{projectId}/bom/{lineId}/mark',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
                'lineId' => new Link(fromClass: ProjectBomLineResource::class),
            ],
            status: 200,
            processor: MarkBomLineProcessor::class,
            input: MarkBomLineInput::class,
            openapi: new Model\Operation(
                summary: 'Mark a job line',
                description: 'Declares a job line launched or done for a number of runs, replacing the previous mark of the line',
                tags: ['Group Industry - BOM'],
            ),
        ),
        new Delete(
            uriTemplate: '/industry/projects/{projectId}/bom/{lineId}/mark',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
                'lineId' => new Link(fromClass: ProjectBomLineResource::class),
            ],
            provider: BomLineMarkProvider::class,
            processor: RemoveBomLineMarkProcessor::class,
            openapi: new Model\Operation(
                summary: 'Remove the mark of a job line',
                description: 'Removes the declaration made on the job line',
                tags: ['Group Industry - BOM'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectBomLineMarkResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public string $lineId;

    public string $state;

    public int $runs;

    public string $markedAt;

    public static function fromLine(IndustryProjectBomLine $line): self
    {
        $mark = $line->getMark() ?? throw new \LogicException('The line has no mark.');

        $resource = new self();
        $resource->id = $line->getId()->toRfc4122();
        $resource->lineId = $line->getId()->toRfc4122();
        $resource->state = $mark->getState()->value;
        $resource->runs = min($mark->getRuns(), $line->getRuns() ?? throw new \LogicException('The job line has no runs.'));
        $resource->markedAt = $mark->getMarkedAt()->format(\DATE_ATOM);

        return $resource;
    }
}
