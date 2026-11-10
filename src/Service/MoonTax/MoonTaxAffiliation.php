<?php

declare(strict_types=1);

namespace App\Service\MoonTax;

final readonly class MoonTaxAffiliation
{
    public function __construct(
        public bool $known,
        public ?string $corporationName,
        public ?int $allianceId,
        public ?string $allianceName,
    ) {
    }

    public static function unknown(): self
    {
        return new self(false, null, null, null);
    }
}
