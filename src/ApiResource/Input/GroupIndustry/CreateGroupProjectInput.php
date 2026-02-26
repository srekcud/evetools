<?php

declare(strict_types=1);

namespace App\ApiResource\Input\GroupIndustry;

use Symfony\Component\Validator\Constraints as Assert;

class CreateGroupProjectInput
{
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    /** @var list<array{typeId: int, typeName: string, meLevel: int, teLevel: int, runs: int}> */
    #[Assert\NotBlank]
    #[Assert\Count(min: 1, max: 10)]
    public array $items = [];

    /** @var int[] */
    public array $blacklistGroupIds = [];

    /** @var int[] */
    public array $blacklistTypeIds = [];

    #[Assert\Length(max: 255)]
    public ?string $containerName = null;

    /** @var array<string, int>|null */
    public ?array $lineRentalRatesOverride = null;

    #[Assert\PositiveOrZero]
    public ?float $brokerFeePercent = null;

    #[Assert\PositiveOrZero]
    public ?float $salesTaxPercent = null;
}
