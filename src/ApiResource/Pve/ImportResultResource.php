<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

class ImportResultResource
{
    public int $imported = 0;

    public int $rejectedZeroPrice = 0;

    public int $rejectedContributions = 0;
}
