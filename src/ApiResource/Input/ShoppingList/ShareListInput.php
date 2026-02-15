<?php

declare(strict_types=1);

namespace App\ApiResource\Input\ShoppingList;

use Symfony\Component\Validator\Constraints as Assert;

class ShareListInput
{
    /** @var array<array{typeId: int, typeName: string, quantity: int}> */
    #[Assert\NotBlank]
    public array $items = [];

    /** @var string[] */
    public array $notFound = [];

    /** @var array<string, float> */
    public array $totals = [];

    #[Assert\Positive]
    public float $transportCostPerM3 = 1200.0;

    public ?int $structureId = null;

    public ?string $structureName = null;
}
