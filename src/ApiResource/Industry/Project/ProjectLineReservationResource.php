<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Industry\Project\ReserveLineInput;
use App\Entity\IndustryProjectLineReservation;
use App\State\Processor\Industry\Project\ReleaseLineProcessor;
use App\State\Processor\Industry\Project\ReserveLineProcessor;
use App\State\Provider\Industry\Project\LineReservationProvider;

#[ApiResource(
    shortName: 'ProjectLineReservation',
    description: 'Reservation of a BOM line of a project by one of its participants',
    operations: [
        new Post(
            uriTemplate: '/industry/projects/{projectId}/bom/{lineId}/reservation',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
                'lineId' => new Link(fromClass: ProjectBomLineResource::class),
            ],
            processor: ReserveLineProcessor::class,
            input: ReserveLineInput::class,
            openapi: new Model\Operation(
                summary: 'Reserve a BOM line',
                description: 'Reserves a quantity of an incomplete line for the calling participant, within the quantity the other participants leave free, and replaces his previous reservation of the line',
                tags: ['Group Industry - BOM'],
            ),
        ),
        new Delete(
            uriTemplate: '/industry/projects/{projectId}/bom/{lineId}/reservation',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
                'lineId' => new Link(fromClass: ProjectBomLineResource::class),
            ],
            provider: LineReservationProvider::class,
            processor: ReleaseLineProcessor::class,
            openapi: new Model\Operation(
                summary: 'Release a BOM line',
                description: 'Removes the reservation of the calling participant on the line',
                tags: ['Group Industry - BOM'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectLineReservationResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public string $bomItemId;

    public string $participantId;

    public ?string $characterName = null;

    public int $quantity;

    public static function fromEntity(IndustryProjectLineReservation $reservation, ?string $characterName): self
    {
        $resource = new self();
        $resource->id = $reservation->getId()->toRfc4122();
        $resource->bomItemId = $reservation->getBomLine()->getId()->toRfc4122();
        $resource->participantId = $reservation->getParticipant()->getId()->toRfc4122();
        $resource->characterName = $characterName;
        $resource->quantity = $reservation->getQuantity();

        return $resource;
    }
}
