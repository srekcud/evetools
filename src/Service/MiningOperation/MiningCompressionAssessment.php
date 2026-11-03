<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MiningCompressorKind;

final readonly class MiningCompressionAssessment
{
    /**
     * @param list<MiningTypeCompression> $verdicts
     * @param list<MiningCompressorKind>  $noHullKinds
     * @param list<MiningCompressorKind>  $notMountedKinds
     */
    public function __construct(
        public array $verdicts,
        public array $noHullKinds,
        public array $notMountedKinds,
    ) {
    }
}
