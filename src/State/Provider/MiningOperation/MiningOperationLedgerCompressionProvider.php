<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningOperationLedgerResource;
use App\Entity\MiningOperation;
use App\Enum\MiningBoostShip;
use App\Enum\MiningCompressorKind;
use App\Exception\BoostCompressorsMissingFromSdeException;
use App\Exception\TypeMissingFromSdeException;
use App\Repository\MiningOperationRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\MiningOperation\MiningCompressionAssessor;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningOperation\MiningOreKindResolver;
use App\Service\MiningOperation\MiningTypeCompression;
use App\Service\MiningOperation\MountableCompressorCatalog;
use App\Service\TypeNameResolver;

/**
 * @implements ProviderInterface<MiningOperationLedgerResource>
 */
final readonly class MiningOperationLedgerCompressionProvider implements ProviderInterface
{
    public function __construct(
        private MiningOperationLedgerProvider $ledgerProvider,
        private MiningOperationRepository $operationRepository,
        private MountableCompressorCatalog $compressorCatalog,
        private MiningCompressionAssessor $assessor,
        private MiningOreKindResolver $oreKindResolver,
        private InvTypeRepository $typeRepository,
        private TypeNameResolver $typeNameResolver,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MiningOperationLedgerResource
    {
        $ledger = $this->ledgerProvider->provide($operation, $uriVariables, $context);
        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['operationId']);

        $ledger->compression = $this->compressionOf($miningOperation, $this->minedTypeIdsOf($ledger));

        return $ledger;
    }

    /**
     * @return list<int>
     */
    private function minedTypeIdsOf(MiningOperationLedgerResource $ledger): array
    {
        $typeIds = [];
        foreach ($ledger->participants as $participant) {
            foreach ($participant['characters'] as $character) {
                foreach ($character['quantities'] as $quantity) {
                    if ($quantity['quantity'] > 0) {
                        $typeIds[$quantity['typeId']] = $quantity['typeId'];
                    }
                }
            }
        }
        sort($typeIds);

        return $typeIds;
    }

    /**
     * @param list<int> $typeIds
     *
     * @return array<string, mixed>
     */
    private function compressionOf(MiningOperation $miningOperation, array $typeIds): array
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
