<?php

declare(strict_types=1);

namespace App\Service\Pve;

use App\Enum\CorporationProjectReadFailure;

final readonly class CorporationProjectReadError
{
    public function __construct(
        public string $corporationName,
        public ?string $projectName,
        public ?string $characterName,
        public CorporationProjectReadFailure $reason,
        public ?int $httpStatus = null,
    ) {
    }

    public function summary(): string
    {
        $subject = implode(' / ', array_filter(
            [$this->corporationName, $this->projectName, $this->characterName],
            static fn (?string $part): bool => $part !== null,
        ));
        $httpSuffix = $this->httpStatus === null ? '' : " (HTTP {$this->httpStatus})";

        return "{$subject}: {$this->reason->value}{$httpSuffix}";
    }
}
