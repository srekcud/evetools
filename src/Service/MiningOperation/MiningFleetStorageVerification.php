<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MiningFleetStorageCheck;
use App\Enum\MiningFleetStorageUnknownReason;

final readonly class MiningFleetStorageVerification
{
    /**
     * @param list<MiningFleetStorageVerificationLine> $lines
     */
    public function __construct(
        public MiningFleetStorageCheck $status,
        public ?MiningFleetStorageUnknownReason $unknownReason,
        public ?string $containerName,
        public ?\DateTimeImmutable $assetsReadAt,
        public array $lines,
    ) {
    }
}
