<?php

declare(strict_types=1);

namespace App\Service\Industry\Blueprint;

final readonly class BlueprintLibraryRow
{
    public function __construct(
        public int $typeId,
        public string $name,
        public string $kind,
        public int $me,
        public int $te,
        public ?int $runs,
        public string $scope,
        public ?string $ownerName,
        public int $locationId,
        public ?int $divisionNumber,
        public int $count,
        public bool $usedByRunningJob,
    ) {
    }
}
