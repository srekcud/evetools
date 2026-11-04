<?php

declare(strict_types=1);

namespace App\ApiResource\Input\MiningSite;

class RecordMiningSiteScanInput
{
    /** @var list<MiningSiteScanLineInput> */
    public array $lines = [];
}
