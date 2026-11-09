<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Industry\Project\ProjectBomLineResource;
use App\ApiResource\Industry\Project\IndustryProjectResource;
use App\ApiResource\Input\Industry\LinkJobInput;
use App\Entity\IndustryProjectJobMatch;
use App\State\Processor\Industry\LinkJobProcessor;
use App\State\Processor\Industry\UnlinkJobProcessor;
use App\State\Provider\Industry\JobMatchDeleteProvider;

#[ApiResource(
    shortName: 'ProjectJobMatch',
    description: 'ESI job linked to a job line of the project BOM',
    operations: [
        new Post(
            uriTemplate: '/industry/projects/{projectId}/bom/{lineId}/link-job',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
                'lineId' => new Link(fromClass: ProjectBomLineResource::class),
            ],
            processor: LinkJobProcessor::class,
            input: LinkJobInput::class,
            openapi: new Model\Operation(summary: 'Link ESI job', description: 'Links an ESI job of the owner to a job line of the BOM', tags: ['Industry - Projects']),
        ),
        new Delete(
            uriTemplate: '/industry/projects/{projectId}/job-matches/{id}',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            provider: JobMatchDeleteProvider::class,
            processor: UnlinkJobProcessor::class,
            openapi: new Model\Operation(summary: 'Unlink ESI job', description: 'Removes a job link from a BOM line', tags: ['Industry - Projects']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class ProjectJobMatchResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public string $bomLineId;

    public int $esiJobId;

    public ?float $cost = null;

    public string $status;

    public ?string $endDate = null;

    public int $runs;

    public ?string $characterName = null;

    public string $matchedAt;

    public ?int $facilityId = null;

    public ?string $facilityName = null;

    public bool $manual;

    /** @var array{blueprint: array<string, mixed>, output: array<string, mixed>}|null */
    public ?array $locations = null;

    public static function fromEntity(IndustryProjectJobMatch $match): self
    {
        $resource = new self();
        $resource->id = (string) $match->getId();
        $resource->bomLineId = $match->getBomLine()->getId()->toRfc4122();
        $resource->esiJobId = $match->getEsiJobId();
        $resource->cost = $match->getCost();
        $resource->status = $match->getStatus();
        $resource->endDate = $match->getEndDate()?->format('c');
        $resource->runs = $match->getRuns();
        $resource->characterName = $match->getCharacterName();
        $resource->matchedAt = $match->getMatchedAt()->format('c');
        $resource->facilityId = $match->getFacilityId();
        $resource->facilityName = $match->getFacilityName();
        $resource->manual = $match->isManual();

        return $resource;
    }
}
