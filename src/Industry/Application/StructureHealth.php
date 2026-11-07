<?php

declare(strict_types=1);

namespace App\Industry\Application;

final readonly class StructureHealth
{
    /**
     * @param list<string> $offlineServices
     */
    public function __construct(
        public StructureHealthState $state,
        public ?float $fuelDays,
        public array $offlineServices,
    ) {
    }

    public static function unknown(): self
    {
        return new self(StructureHealthState::Unknown, null, []);
    }
}
