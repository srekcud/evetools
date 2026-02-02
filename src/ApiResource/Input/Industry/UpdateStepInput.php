<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateStepInput
{
    public ?bool $purchased = null;

    public ?bool $inStock = null;

    #[Assert\PositiveOrZero]
    public ?int $inStockQuantity = null;

    public ?bool $clearJobData = null;

    #[Assert\Positive]
    public ?int $runs = null;

    public ?int $esiJobsTotalRuns = null;

    public ?float $esiJobCost = null;

    public ?string $esiJobStatus = null;

    public ?string $esiJobCharacterName = null;

    public ?int $esiJobsCount = null;

    public ?bool $manualJobData = null;
}
