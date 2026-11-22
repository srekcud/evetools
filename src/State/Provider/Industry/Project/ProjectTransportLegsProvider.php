<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectTransportLegsResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\Haul\HaulLeg;
use App\Service\Industry\Project\Haul\HaulLine;
use App\Service\Industry\Project\Haul\HaulPlace;
use App\Service\Industry\Project\Haul\HaulUnreadable;
use App\Service\Industry\Project\Haul\ProjectHaulLegs;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectTransportLegsResource>
 */
final readonly class ProjectTransportLegsProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private ProjectHaulLegs $haulLegs,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectTransportLegsResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['projectId'] ?? null, 'Project not found'));
        if (null === $project) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        $haulLegs = $this->haulLegs->of($project, $user);
        $resource = new ProjectTransportLegsResource();
        $resource->id = $project->getId()->toRfc4122();
        $resource->personalStockUnknownReason = $haulLegs->personalStockUnknownReason;
        $resource->unreadable = array_map(
            static fn (HaulUnreadable $entry): array => ['typeId' => $entry->typeId, 'typeName' => $entry->typeName, 'locationId' => $entry->locationId, 'reason' => $entry->reason],
            $haulLegs->unreadable,
        );
        $resource->legs = array_map(fn (HaulLeg $leg): array => $this->legOf($leg), $haulLegs->legs);

        return $resource;
    }

    /**
     * @return array{key: string, departure: array{locationId: ?int, stationName: ?string, solarSystemId: ?int, solarSystemName: ?string}, arrival: array{locationId: ?int, stationName: ?string, solarSystemId: ?int, solarSystemName: ?string}, access: string, carrierCharacterIds: list<int>, volume: ?float, lines: list<array{typeId: int, typeName: string, quantity: ?int, state: string, source: string, readyAt: ?string, unitVolume: ?float, volume: ?float, unknownReason: ?string}>}
     */
    private function legOf(HaulLeg $leg): array
    {
        return [
            'key' => $leg->key,
            'departure' => $this->placeOf($leg->departure),
            'arrival' => $this->placeOf($leg->arrival),
            'access' => $leg->access->value,
            'carrierCharacterIds' => $leg->carrierCharacterIds,
            'volume' => $leg->volume,
            'lines' => array_map($this->lineOf(...), $leg->lines),
        ];
    }

    /**
     * @return array{locationId: ?int, stationName: ?string, solarSystemId: ?int, solarSystemName: ?string}
     */
    private function placeOf(HaulPlace $place): array
    {
        return [
            'locationId' => $place->locationId,
            'stationName' => $place->stationName,
            'solarSystemId' => $place->solarSystemId,
            'solarSystemName' => $place->solarSystemName,
        ];
    }

    /**
     * @return array{typeId: int, typeName: string, quantity: ?int, state: string, source: string, readyAt: ?string, unitVolume: ?float, volume: ?float, unknownReason: ?string}
     */
    private function lineOf(HaulLine $line): array
    {
        return [
            'typeId' => $line->typeId,
            'typeName' => $line->typeName,
            'quantity' => $line->quantity,
            'state' => $line->state->value,
            'source' => $line->source->value,
            'readyAt' => $line->readyAt?->format(\DATE_ATOM),
            'unitVolume' => $line->unitVolume,
            'volume' => $line->volume,
            'unknownReason' => $line->unknownReason,
        ];
    }
}
