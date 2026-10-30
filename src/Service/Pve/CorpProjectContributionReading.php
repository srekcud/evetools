<?php

declare(strict_types=1);

namespace App\Service\Pve;

final readonly class CorpProjectContributionReading
{
    /**
     * @param list<CorpProjectContribution> $contributions
     * @param list<CorporationProjectReadError> $errors
     * @param array<int, true> $refusedCharacterIds
     */
    public function __construct(
        public array $contributions = [],
        public array $errors = [],
        public array $refusedCharacterIds = [],
        public ?int $scannedProjects = 0,
    ) {
    }

    public function merge(self $other): self
    {
        return new self(
            [...$this->contributions, ...$other->contributions],
            [...$this->errors, ...$other->errors],
            $this->refusedCharacterIds + $other->refusedCharacterIds,
            $this->scannedProjects === null || $other->scannedProjects === null
                ? null
                : $this->scannedProjects + $other->scannedProjects,
        );
    }
}
