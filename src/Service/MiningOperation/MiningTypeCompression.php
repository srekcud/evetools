<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MiningCompressionStatus;

final readonly class MiningTypeCompression
{
    public function __construct(
        public int $typeId,
        public ?bool $compressible,
        public MiningCompressionStatus $status,
    ) {
    }
}
