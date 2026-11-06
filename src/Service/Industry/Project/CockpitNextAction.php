<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class CockpitNextAction
{
    public function __construct(
        public string $code,
        public bool $blocked,
        public ?string $tab,
        public ?string $targetStatus,
        public ?string $bomLineId,
        public ?int $typeId,
        public ?string $typeName,
        public ?int $quantity,
    ) {
    }
}
