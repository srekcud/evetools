<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Enum\MiningBoostShip;
use App\Enum\MiningCompressorKind;
use App\Exception\BoostCompressorsMissingFromSdeException;
use App\Exception\TypeMissingFromSdeException;
use App\Repository\Sde\InvTypeRepository;
use App\Service\TypeNameResolver;

final readonly class MiningOperationCompressionReport
{
    public function __construct(
        private MountableCompressorCatalog $compressorCatalog,
        private MiningCompressionAssessor $assessor,
        private MiningOreKindResolver $oreKindResolver,
        private InvTypeRepository $typeRepository,
        private TypeNameResolver $typeNameResolver,
    ) {
    }

    /**
     * @param list<int> $typeIds
     *
     * @return array<string, mixed>
     */
    public function of(MiningOperation $miningOperation, array $typeIds): array
    {
        $boostShip = $miningOperation->getBoostShip();
        $compressorTypeIds = null === $boostShip ? [] : $this->compressorCatalog->compressorTypesOf($boostShip);
        $mounted = $miningOperation->getMountedCompressorKinds();

        $assessment = $this->assessor->assess(
            $boostShip,
            array_map(MiningCompressorKind::from(...), array_keys($compressorTypeIds)),
            $mounted,
            $this->oreKindsOf($typeIds),
        );

        return [
            'boostShipTypeId' => $boostShip?->value,
            'boostShipName' => $boostShip?->name,
            'mountedCompressors' => null === $boostShip ? [] : $this->mountedCompressorsOf($boostShip, $mounted, $compressorTypeIds),
            'types' => array_map(
                static fn (MiningTypeCompression $verdict): array => ['typeId' => $verdict->typeId, 'compressible' => $verdict->compressible, 'status' => $verdict->status->value],
                $assessment->verdicts,
            ),
            'gaps' => [
                'noHull' => array_map(static fn (MiningCompressorKind $kind): string => $kind->value, $assessment->noHullKinds),
                'notMounted' => array_map(static fn (MiningCompressorKind $kind): string => $kind->value, $assessment->notMountedKinds),
            ],
        ];
    }

    /**
     * @param list<int> $typeIds
     *
     * @return array<int, ?MiningCompressorKind>
     */
    private function oreKindsOf(array $typeIds): array
    {
        $types = $this->typeRepository->findByTypeIds($typeIds);

        $kinds = [];
        foreach ($typeIds as $typeId) {
            $group = isset($types[$typeId]) ? $types[$typeId]->getGroup() : null;
            $kinds[$typeId] = null === $group ? null : $this->oreKindResolver->kindOf($group->getCategory()->getCategoryId(), $group->getGroupId());
        }

        return $kinds;
    }

    /**
     * @param list<MiningCompressorKind> $mounted
     * @param array<string, int>         $compressorTypeIds
     *
     * @return list<array{kind: string, typeId: int, typeName: string}>
     */
    private function mountedCompressorsOf(MiningBoostShip $boostShip, array $mounted, array $compressorTypeIds): array
    {
        $names = $this->typeNameResolver->findNames(array_values($compressorTypeIds));

        return array_map(
            static function (MiningCompressorKind $kind) use ($boostShip, $compressorTypeIds, $names): array {
                $typeId = $compressorTypeIds[$kind->value] ?? throw new BoostCompressorsMissingFromSdeException($boostShip);

                return ['kind' => $kind->value, 'typeId' => $typeId, 'typeName' => $names[$typeId] ?? throw new TypeMissingFromSdeException([$typeId])];
            },
            $mounted,
        );
    }
}
