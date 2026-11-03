<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MiningBoostShip;
use App\Enum\MiningCompressionStatus;
use App\Enum\MiningCompressorKind;

final class MiningCompressionAssessor
{
    /**
     * @param list<MiningCompressorKind>        $mountable
     * @param list<MiningCompressorKind>        $mounted
     * @param array<int, ?MiningCompressorKind> $kindByTypeId
     */
    public function assess(?MiningBoostShip $boostShip, array $mountable, array $mounted, array $kindByTypeId): MiningCompressionAssessment
    {
        $verdicts = [];
        $noHullKinds = [];
        $notMountedKinds = [];

        foreach ($kindByTypeId as $typeId => $kind) {
            $status = $this->statusOf($boostShip, $kind, $mountable, $mounted);
            $verdicts[] = new MiningTypeCompression($typeId, $this->compressibleFor($status), $status);

            if (null !== $kind && MiningCompressionStatus::NoHullCompressor === $status) {
                $noHullKinds[$kind->value] = $kind;
            }
            if (null !== $kind && MiningCompressionStatus::NotMounted === $status) {
                $notMountedKinds[$kind->value] = $kind;
            }
        }

        return new MiningCompressionAssessment($verdicts, array_values($noHullKinds), array_values($notMountedKinds));
    }

    /**
     * @param list<MiningCompressorKind> $mountable
     * @param list<MiningCompressorKind> $mounted
     */
    private function statusOf(?MiningBoostShip $boostShip, ?MiningCompressorKind $kind, array $mountable, array $mounted): MiningCompressionStatus
    {
        return match (true) {
            null === $boostShip => MiningCompressionStatus::NoBoostShip,
            null === $kind => MiningCompressionStatus::UnknownOreKind,
            !in_array($kind, $mountable, true) => MiningCompressionStatus::NoHullCompressor,
            !in_array($kind, $mounted, true) => MiningCompressionStatus::NotMounted,
            default => MiningCompressionStatus::OnSite,
        };
    }

    private function compressibleFor(MiningCompressionStatus $status): ?bool
    {
        return match ($status) {
            MiningCompressionStatus::OnSite => true,
            MiningCompressionStatus::NoHullCompressor, MiningCompressionStatus::NotMounted => false,
            MiningCompressionStatus::UnknownOreKind, MiningCompressionStatus::NoBoostShip => null,
        };
    }
}
