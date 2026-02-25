<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

use Symfony\Component\Validator\Constraints as Assert;

class StockpileImportInput
{
    #[Assert\Positive]
    public int $typeId;

    #[Assert\Positive]
    public int $runs = 1;

    #[Assert\Range(min: 0, max: 10)]
    public int $me = 0;

    #[Assert\Range(min: 0, max: 20)]
    public int $te = 0;

    #[Assert\Choice(choices: ['replace', 'merge'])]
    public string $mode = 'replace';
}
