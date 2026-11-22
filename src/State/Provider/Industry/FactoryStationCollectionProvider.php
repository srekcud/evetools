<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\FactoryStationResource;
use App\Entity\User;
use App\Service\Industry\Factory\FactoryStation;
use App\Service\Industry\Factory\FactoryStationAccess;
use App\Service\Industry\Factory\FactoryStationProject;
use App\Service\Industry\Factory\FactoryStations;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<FactoryStationResource>
 */
final readonly class FactoryStationCollectionProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private FactoryStations $factoryStations,
    ) {
    }

    /**
     * @return list<FactoryStationResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        return array_map(self::resourceOf(...), $this->factoryStations->of($user));
    }

    private static function resourceOf(FactoryStation $station): FactoryStationResource
    {
        $resource = new FactoryStationResource();
        $resource->id = $station->id;
        $resource->name = $station->name;
        $resource->structureType = $station->structureType;
        $resource->securityType = $station->securityType;
        $resource->locationId = $station->locationId;
        $resource->solarSystemId = $station->solarSystemId;
        $resource->solarSystemName = $station->solarSystemName;
        $resource->specialisations = $station->specialisations;
        $resource->manufacturingCostIndex = $station->manufacturingCostIndex;
        $resource->reactionCostIndex = $station->reactionCostIndex;
        $resource->access = array_map(
            static fn (FactoryStationAccess $access): array => ['characterId' => $access->characterId, 'characterName' => $access->characterName, 'status' => $access->status->value],
            $station->access,
        );
        $resource->linesInUse = $station->linesInUse;
        $resource->projects = array_map(
            static fn (FactoryStationProject $project): array => ['id' => $project->id, 'name' => $project->name],
            $station->projects,
        );

        return $resource;
    }
}
