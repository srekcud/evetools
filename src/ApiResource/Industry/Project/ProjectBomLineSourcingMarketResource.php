<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Industry\Project\ChooseSourcingMarketInput;
use App\Entity\IndustryProjectBomLine;
use App\State\Processor\Industry\Project\ChooseSourcingMarketProcessor;
use App\State\Processor\Industry\Project\ClearSourcingMarketProcessor;
use App\State\Provider\Industry\Project\SourcingMarketOfLineProvider;

#[ApiResource(
    shortName: 'ProjectBomLineSourcingMarket',
    description: 'Market chosen to buy a material line of a project BOM: Jita with import or the market structure',
    operations: [
        new Post(
            uriTemplate: '/industry/projects/{projectId}/bom/{lineId}/sourcing-market',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
                'lineId' => new Link(fromClass: ProjectBomLineResource::class),
            ],
            status: 200,
            processor: ChooseSourcingMarketProcessor::class,
            input: ChooseSourcingMarketInput::class,
            openapi: new Model\Operation(
                summary: 'Choose the market of a material line',
                description: 'Stores the market, jita or structure, where the material line is bought, replacing the previous choice',
                tags: ['Group Industry - BOM'],
            ),
        ),
        new Delete(
            uriTemplate: '/industry/projects/{projectId}/bom/{lineId}/sourcing-market',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
                'lineId' => new Link(fromClass: ProjectBomLineResource::class),
            ],
            provider: SourcingMarketOfLineProvider::class,
            processor: ClearSourcingMarketProcessor::class,
            openapi: new Model\Operation(
                summary: 'Remove the market choice of a material line',
                description: 'Removes the stored market so the recommended market applies again',
                tags: ['Group Industry - BOM'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectBomLineSourcingMarketResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public string $lineId;

    public string $market;

    public static function fromLine(IndustryProjectBomLine $line): self
    {
        $market = $line->getSourcingMarket() ?? throw new \LogicException('The line has no sourcing market.');

        $resource = new self();
        $resource->id = $line->getId()->toRfc4122();
        $resource->lineId = $line->getId()->toRfc4122();
        $resource->market = $market->value;

        return $resource;
    }
}
