<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

class ImportStructureListInput
{
    /** @var array<mixed> */
    public array $entries = [];

    public bool $overwriteExisting = false;

    public bool $dryRun = false;
}
