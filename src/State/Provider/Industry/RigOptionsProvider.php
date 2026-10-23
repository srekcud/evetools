<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use App\Service\Industry\RigCatalogue;

class RigOptionsProvider
{
    /** @return array<string, list<array<string, mixed>>> */
    public function getRigOptionsArray(): array
    {
        return RigCatalogue::RIGS_BY_FAMILY;
    }
}
