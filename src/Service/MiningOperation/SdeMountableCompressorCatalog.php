<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MiningBoostShip;
use App\Enum\MiningCompressorKind;
use App\Exception\BoostCompressorsMissingFromSdeException;
use App\Repository\Sde\InvTypeRepository;

final readonly class SdeMountableCompressorCatalog implements MountableCompressorCatalog
{
    private const int COMPRESSORS_GROUP_ID = 4174;
    private const int CAN_FIT_SHIP_TYPE_1_ATTRIBUTE_ID = 1302;
    private const int COMPRESSIBLE_ITEMS_TYPE_LIST_ATTRIBUTE_ID = 3255;
    private const array KIND_BY_TYPE_LIST_ID = [
        330 => MiningCompressorKind::Moon,
        331 => MiningCompressorKind::Ice,
        332 => MiningCompressorKind::Gas,
        333 => MiningCompressorKind::Mercoxit,
        334 => MiningCompressorKind::Asteroid,
    ];

    public function __construct(private InvTypeRepository $typeRepository)
    {
    }

    public function kindsMountableOn(MiningBoostShip $ship): array
    {
        return array_map(
            static fn (string $kindValue): MiningCompressorKind => MiningCompressorKind::from($kindValue),
            array_keys($this->compressorTypesOf($ship)),
        );
    }

    public function compressorTypesOf(MiningBoostShip $ship): array
    {
        $typeIdByKindValue = [];
        foreach ($this->typeRepository->findCompressorsWithFit(self::COMPRESSORS_GROUP_ID, self::CAN_FIT_SHIP_TYPE_1_ATTRIBUTE_ID, self::COMPRESSIBLE_ITEMS_TYPE_LIST_ATTRIBUTE_ID) as $compressor) {
            $kind = self::KIND_BY_TYPE_LIST_ID[$compressor['kindListTypeId']] ?? null;
            if ($compressor['shipTypeId'] === $ship->value && null !== $kind) {
                $typeIdByKindValue[$kind->value] = $compressor['typeId'];
            }
        }

        if ([] === $typeIdByKindValue) {
            throw new BoostCompressorsMissingFromSdeException($ship);
        }

        $ordered = [];
        foreach (MiningCompressorKind::cases() as $kind) {
            if (isset($typeIdByKindValue[$kind->value])) {
                $ordered[$kind->value] = $typeIdByKindValue[$kind->value];
            }
        }

        return $ordered;
    }
}
