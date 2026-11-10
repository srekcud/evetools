<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningSeasonResource;
use App\Enum\MiningSeasonPeriod;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningOperation\MiningSeasonReader;
use App\Service\TypeNameResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @implements ProviderInterface<MiningSeasonResource>
 */
final readonly class MiningSeasonProvider implements ProviderInterface
{
    private const string INVALID_PERIOD_MESSAGE = 'The period must be 30d, 90d or all';

    public function __construct(
        private Security $security,
        private MiningSeasonReader $reader,
        private TypeNameResolver $typeNameResolver,
        private MapSolarSystemRepository $solarSystemRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MiningSeasonResource
    {
        $user = MiningOperationRequest::userOf($this->security);
        $requestedPeriod = $context['request']->query->get('period', MiningSeasonPeriod::ThirtyDays->value);
        $period = is_string($requestedPeriod) ? MiningSeasonPeriod::tryFrom($requestedPeriod) : null;
        if ($period === null) {
            throw new BadRequestHttpException(self::INVALID_PERIOD_MESSAGE);
        }

        $summary = $this->reader->read($user, $period);

        $resource = new MiningSeasonResource();
        $resource->period = $period->value;
        $resource->operationsCount = $summary->operationsCount;
        $resource->unknownValueOperationsCount = $summary->unknownValueOperationsCount;
        $resource->knownValue = $summary->knownValue;
        $resource->knownOperationMinutes = $summary->knownOperationMinutes;
        $resource->playedMinutes = $summary->playedMinutes;
        $resource->iskPerHour = $summary->iskPerHour;
        $resource->players = $summary->players;
        $shipNames = $this->typeNameResolver->findNames(array_column($summary->ships, 'shipTypeId'));
        $resource->ships = array_map(
            static fn (array $ship): array => [
                'shipTypeId' => $ship['shipTypeId'],
                'shipName' => $shipNames[$ship['shipTypeId']] ?? sprintf('Type %d', $ship['shipTypeId']),
                'charactersCount' => $ship['charactersCount'],
                'minutes' => $ship['minutes'],
                'minedValue' => $ship['minedValue'],
                'iskPerHour' => $ship['iskPerHour'],
                'isBooster' => $ship['isBooster'],
            ],
            $summary->ships,
        );
        $resource->unknownShipCharactersCount = $summary->unknownShipCharactersCount;
        $resource->sitesBySystem = array_map(
            fn (array $system): array => ['solarSystemName' => $this->solarSystemRepository->findBySolarSystemId($system['solarSystemId'])?->getSolarSystemName()] + $system,
            $summary->sitesBySystem,
        );
        $resource->sitesWithoutSystemCount = $summary->sitesWithoutSystemCount;

        return $resource;
    }
}
