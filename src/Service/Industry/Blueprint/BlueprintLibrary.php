<?php

declare(strict_types=1);

namespace App\Service\Industry\Blueprint;

final readonly class BlueprintLibrary
{
    /** @param list<BlueprintLibraryRow> $rows */
    public function __construct(
        public string $corporationState,
        public array $rows,
    ) {
    }
}
