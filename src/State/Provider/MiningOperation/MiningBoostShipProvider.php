<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningBoostShipResource;
use App\Enum\MiningBoostShip;
use App\Exception\TypeMissingFromSdeException;
use App\Service\MiningOperation\MountableCompressorCatalog;
use App\Service\TypeNameResolver;

/**
 * @implements ProviderInterface<MiningBoostShipResource>
 */
final readonly class MiningBoostShipProvider implements ProviderInterface
{
    public function __construct(
        private MountableCompressorCatalog $compressorCatalog,
        private TypeNameResolver $typeNameResolver,
    ) {
    }

    /**
     * @return list<MiningBoostShipResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return array_map($this->resourceOf(...), MiningBoostShip::cases());
    }

    private function resourceOf(MiningBoostShip $boostShip): MiningBoostShipResource
    {
        $compressorTypeIds = $this->compressorCatalog->compressorTypesOf($boostShip);
        $names = $this->typeNameResolver->findNames([$boostShip->value, ...array_values($compressorTypeIds)]);

        $resource = new MiningBoostShipResource();
        $resource->typeId = $boostShip->value;
        $resource->name = $names[$boostShip->value] ?? throw new TypeMissingFromSdeException([$boostShip->value]);
        $resource->compressors = array_map(
            static fn (string $kindValue, int $compressorTypeId): array => [
                'kind' => $kindValue,
                'typeId' => $compressorTypeId,
                'name' => $names[$compressorTypeId] ?? throw new TypeMissingFromSdeException([$compressorTypeId]),
            ],
            array_keys($compressorTypeIds),
            array_values($compressorTypeIds),
        );

        return $resource;
    }
}
